<?php
declare(strict_types=1);

namespace Diwan\Download;

use DateTimeImmutable;
use Diwan\Config\Env;
use Diwan\Database\Database;
use Diwan\Support\Crypto;
use Diwan\Support\Logger;
use PDO;
use RuntimeException;

/**
 * The only code in the system permitted to read from private-storage.
 *
 * Two-step design on purpose:
 *   1. issueToken()  — proves the caller holds a valid licence, mints a short
 *                      lived single-use token.
 *   2. stream()      — redeems that token and pipes the file through PHP.
 *
 * The installer path never appears in a URL, so there is nothing to guess,
 * share, or hotlink beyond the token's short lifetime.
 *
 * A release is either a file in private-storage (source = local) or an asset
 * on a private GitHub release (source = github). For GitHub, stream() answers
 * with a redirect to GitHub's short-lived signed URL instead of piping bytes
 * through PHP — see GitHubReleases.
 */
final class DownloadService
{
    /** Every platform an installer can be issued for; mirrors releases.platform. */
    public const PLATFORMS = ['windows', 'macos', 'linux', 'android'];

    /**
     * The builds each platform can ship, mirrored by scripts/sync-github-release.php
     * and the platform picker in frontend/src/js/dashboard.js. Android has one.
     */
    public const VARIANTS = [
        'windows' => ['x64', 'x86'],
        'macos'   => ['apple-silicon', 'intel'],
        'linux'   => ['appimage', 'deb', 'rpm'],
        'android' => [],
    ];

    /** Licence-key guesses allowed per IP per hour on issue-download.php. */
    private const MAX_ATTEMPTS_PER_HOUR = 10;

    /**
     * How long a single download LINK stays valid, in minutes. This governs the
     * link only — it has nothing to do with how long the customer's licence
     * lasts, which is LICENSE_VALIDITY_DAYS (see LicenseService::issueForOrder).
     * Keep it short: the link is single-use and is the only thing standing
     * between a leaked URL and a free installer.
     */
    private const TOKEN_TTL_MINUTES = 15;

    /** The token being redeemed, once known — so a refusal can be recorded against it. */
    private ?int $tokenId = null;

    /**
     * $variant picks one build when a platform ships several (macOS
     * 'apple-silicon' / 'intel', Windows 'x64' / 'x86', Linux 'appimage' /
     * 'deb' / 'rpm'). '' means "whatever this platform has".
     */
    /**
     * platform => source ('local' | 'github') of each platform's active
     * release, for health.php. Throws on a pre-010 schema (no `source`).
     *
     * @return array<string,string>
     */
    public static function activeSources(): array
    {
        return Database::pdo()->query(
            'SELECT platform, source FROM releases WHERE is_active = 1'
        )->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /**
     * Rate limit for issue-download.php, the obvious brute-force target:
     * every attempt counts, valid or not. Records this attempt when allowed.
     */
    public function allowAttempt(string $ip): bool
    {
        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM download_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)')->execute();

        $count = $pdo->prepare(
            'SELECT COUNT(*) FROM download_attempts
              WHERE ip = :ip AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
        );
        $count->execute(['ip' => $ip]);
        if ((int) $count->fetchColumn() >= self::MAX_ATTEMPTS_PER_HOUR) {
            Logger::warning('Download issue rate limited', ['ip' => $ip]);
            return false;
        }

        $pdo->prepare('INSERT INTO download_attempts (ip, attempted_at) VALUES (:ip, NOW())')
            ->execute(['ip' => $ip]);
        return true;
    }

    /**
     * $license is the licence row (findActive()); $channel says where the
     * request came from, for download analytics only.
     */
    public function issueToken(array $license, string $platform, string $ip, string $variant = '', string $channel = 'other'): array
    {
        $licenseId = (int) $license['id'];
        $release = $this->activeRelease($platform, $variant);

        $ttlMinutes = Env::int('DOWNLOAD_LINK_TTL_MINUTES', self::TOKEN_TTL_MINUTES);
        if ($ttlMinutes < 1) {
            $ttlMinutes = self::TOKEN_TTL_MINUTES;
        }

        $token = Crypto::newToken();

        // Stored and checked on MySQL's clock (see redeem()); the PHP-side
        // value is only what the customer is shown.
        $stmt = Database::pdo()->prepare(
            'INSERT INTO download_tokens (token_hash, license_id, release_id, issued_ip, expires_at, created_at)
             VALUES (:hash, :license, :release, :ip, DATE_ADD(NOW(), INTERVAL :ttl MINUTE), NOW())'
        );
        $stmt->execute([
            'hash'    => $this->hashToken($token),
            'license' => $licenseId,
            'release' => $release['id'],
            'ip'      => $ip,
            'ttl'     => $ttlMinutes,
        ]);
        $expiresAt = new DateTimeImmutable('+' . $ttlMinutes . ' minutes');
        DownloadEvents::requested((int) Database::pdo()->lastInsertId(), $license, $release, $channel);

        return [
            'token'      => $token,
            'expires_at' => $expiresAt->format('c'),
            'filename'   => $release['filename'],
        ];
    }

    /**
     * Validates a token and streams the matching file. Everything that can
     * fail, fails before a single byte of the installer is written out.
     */
    public function stream(string $token, string $ip): never
    {
        try {
            $target = $this->redeem($token, $ip);
        } catch (DownloadDenied $e) {
            Logger::warning('Download denied', $e->context + ['status' => $e->status]);
            http_response_code($e->status);
            header('Content-Type: application/json');
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }

        if ($target['github_url'] !== null) {
            $this->redirect($target['github_url']);
        }
        $this->send((string) $target['path'], $target['filename'], $target['token_id']);
    }

    /**
     * Validates and burns a token, returning where the installer is. Throws
     * DownloadDenied for every refusal; nothing is burned or counted unless
     * the installer can actually be served.
     *
     * @return array{github_url:?string, path:?string, filename:string, token_id:int}
     */
    public function redeem(string $token, string $ip): array
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            'SELECT t.*, r.filename, r.storage_path, r.checksum_sha256, r.version,
                    r.source, r.github_tag, r.github_asset,
                    l.id AS license_id, l.status AS license_status,
                    l.downloads_used, l.max_downloads,
                    (t.expires_at <= NOW()) AS token_expired,
                    (l.expires_at IS NOT NULL AND l.expires_at <= NOW()) AS license_expired
               FROM download_tokens t
               JOIN releases r ON r.id = t.release_id
               JOIN licenses l ON l.id = t.license_id
              WHERE t.token_hash = :hash
              LIMIT 1'
        );
        $stmt->execute(['hash' => $this->hashToken($token)]);
        $row = $stmt->fetch();

        if (!$row) {
            $this->deny('Invalid download link.', 404, ['reason' => 'token_not_found', 'ip' => $ip]);
        }
        $this->tokenId = (int) $row['id'];
        if ($row['used_at'] !== null) {
            $this->deny('This download link has already been used. Request a new one.', 410, ['reason' => 'token_used']);
        }
        // Both expiries are compared on MySQL's clock, the one they were written with.
        if ((bool) $row['token_expired']) {
            $this->deny('This download link has expired. Request a new one.', 410, ['reason' => 'token_expired']);
        }
        if ($row['license_status'] !== 'active') {
            $this->deny('This licence is no longer active.', 403, ['reason' => 'license_inactive']);
        }
        if ((bool) $row['license_expired']) {
            $this->deny('This licence has expired.', 403, ['reason' => 'license_expired']);
        }
        if ((int) $row['downloads_used'] >= (int) $row['max_downloads']) {
            $this->deny('Download limit reached for this licence. Contact support.', 403, ['reason' => 'quota_exceeded']);
        }

        // Resolve where the bytes come from BEFORE burning the token, so a
        // missing file or a GitHub outage does not cost the customer a download.
        $githubUrl = null;
        $path = null;
        if ($row['source'] === 'github') {
            $githubUrl = $this->githubUrl($row);
        } else {
            $path = $this->resolvePath((string) $row['storage_path']);
        }

        // Burn the token and count the download BEFORE streaming, so a client
        // that reconnects mid-transfer cannot farm extra downloads. The
        // `used_at IS NULL` guard makes the burn atomic: of two requests racing
        // on one token, only the one whose UPDATE changes the row may proceed.
        $burn = $pdo->prepare(
            'UPDATE download_tokens SET used_at = NOW(), used_ip = :ip WHERE id = :id AND used_at IS NULL'
        );
        $burn->execute(['ip' => $ip, 'id' => $row['id']]);
        if ($burn->rowCount() === 0) {
            $this->deny('This download link has already been used. Request a new one.', 410, ['reason' => 'token_used']);
        }
        $pdo->prepare('UPDATE licenses SET downloads_used = downloads_used + 1 WHERE id = :id')
            ->execute(['id' => $row['license_id']]);

        DownloadEvents::mark((int) $row['id'], 'served');
        Logger::info('Download served', [
            'license_id' => $row['license_id'],
            'file'       => $row['filename'],
            'version'    => $row['version'],
            'source'     => $row['source'],
            'ip'         => $ip,
        ]);

        return ['github_url' => $githubUrl, 'path' => $path, 'filename' => (string) $row['filename'], 'token_id' => (int) $row['id']];
    }

    /** @param array<string,mixed> $row */
    private function githubUrl(array $row): string
    {
        $tag   = (string) ($row['github_tag'] ?? '');
        $asset = (string) ($row['github_asset'] ?? '');
        if ($tag === '' || $asset === '') {
            $this->deny('Installer is temporarily unavailable. Please contact support.', 503, [
                'reason'     => 'github_release_incomplete',
                'release_id' => $row['release_id'],
            ]);
        }
        try {
            return (new GitHubReleases())->signedUrl($tag, $asset);
        } catch (RuntimeException $e) {
            $this->deny('Installer is temporarily unavailable. Please try again in a minute.', 503, [
                'reason' => 'github_unavailable',
                'tag'    => $tag,
                'asset'  => $asset,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    /** Hands the browser GitHub's pre-signed URL; it expires within minutes. */
    private function redirect(string $url): never
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Cache-Control: no-store, must-revalidate');
        header('Referrer-Policy: no-referrer');
        header('Location: ' . $url, true, 302);
        exit;
    }

    /**
     * The newest active release for a platform. With a $variant, that exact
     * build wins, falling back to the platform's variant-less build (so a
     * client asking for 'apple-silicon' still gets a single universal build).
     * Without one, ties break alphabetically — 'apple-silicon' before 'intel'.
     *
     * @return array{id:int,filename:string,storage_path:string,version:string}
     */
    private function activeRelease(string $platform, string $variant = ''): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM releases
              WHERE platform = :platform AND is_active = 1
                AND (:any = 1 OR variant IN (:variant, \'\'))
              ORDER BY variant = :preferred DESC, released_at DESC, variant ASC
              LIMIT 1'
        );
        $stmt->execute([
            'platform'  => $platform,
            'any'       => $variant === '' ? 1 : 0,
            'variant'   => $variant,
            'preferred' => $variant,
        ]);
        $release = $stmt->fetch();

        if (!$release) {
            throw new RuntimeException("No active release for platform: {$platform}");
        }
        return $release;
    }

    /**
     * Guarantees the resolved file really sits inside private-storage/releases.
     * Without this, a poisoned storage_path ("../../public_html/.env", or
     * "../logs/app-….log" next door) would turn the download endpoint into a
     * file reader for everything else in private storage.
     */
    private function resolvePath(string $storagePath): string
    {
        $base = realpath(DIWAN_PRIVATE_STORAGE . '/releases');
        if ($base === false) {
            throw new RuntimeException('Private storage releases directory does not exist on this server');
        }

        $full = realpath($base . '/' . ltrim($storagePath, '/'));
        if ($full === false || !is_file($full)) {
            $this->deny('Installer is temporarily unavailable. Please contact support.', 503, [
                'reason' => 'file_missing',
                'path'   => $storagePath,
            ]);
        }
        if (!str_starts_with($full, $base . DIRECTORY_SEPARATOR)) {
            $this->deny('Installer is temporarily unavailable. Please contact support.', 503, [
                'reason' => 'path_escape_attempt',
                'path'   => $storagePath,
            ]);
        }
        return $full;
    }

    private function send(string $path, string $filename, int $tokenId): never
    {
        // Keep running after a disconnect so the outcome can be recorded.
        ignore_user_abort(true);
        // Discard any buffered output so the binary is not corrupted by stray bytes.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
        header('Content-Length: ' . filesize($path));
        header('Content-Transfer-Encoding: binary');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, must-revalidate');
        header('Pragma: no-cache');

        // readfile() would buffer the whole installer in memory on some hosts.
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open installer for reading');
        }
        set_time_limit(0);
        while (!feof($handle) && !connection_aborted()) {
            echo fread($handle, 8192);
            flush();
        }
        $complete = feof($handle) && !connection_aborted();
        fclose($handle);
        DownloadEvents::mark($tokenId, $complete ? 'completed' : 'failed', $complete ? null : 'interrupted');
        exit;
    }

    private function deny(string $message, int $status, array $context = []): never
    {
        // A refused redemption is a failed attempt — unless the token had
        // already been used, which is a repeat click, not a new attempt.
        if ($this->tokenId !== null && ($context['reason'] ?? '') !== 'token_used') {
            DownloadEvents::mark($this->tokenId, 'failed', (string) ($context['reason'] ?? 'denied'));
        }
        throw new DownloadDenied($message, $status, $context);
    }

    private function hashToken(string $token): string
    {
        return Crypto::hmac($token);
    }
}
