<?php
declare(strict_types=1);

namespace Diwan\Download;

use Diwan\Config\Env;
use Diwan\Database\Database;
use Diwan\Support\Logger;
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
    /**
     * How long a single download LINK stays valid, in minutes. This governs the
     * link only — it has nothing to do with how long the customer's licence
     * lasts, which is LICENSE_VALIDITY_DAYS (see LicenseService::issueForOrder).
     * Keep it short: the link is single-use and is the only thing standing
     * between a leaked URL and a free installer.
     */
    private const TOKEN_TTL_MINUTES = 15;

    /**
     * $variant picks one build when a platform ships several (e.g. macOS
     * 'apple-silicon' / 'intel'). '' means "whatever this platform has".
     */
    public function issueToken(int $licenseId, string $platform, string $ip, string $variant = ''): array
    {
        $release = $this->activeRelease($platform, $variant);

        $ttlMinutes = Env::int('DOWNLOAD_LINK_TTL_MINUTES', self::TOKEN_TTL_MINUTES);
        if ($ttlMinutes < 1) {
            $ttlMinutes = self::TOKEN_TTL_MINUTES;
        }

        $token     = bin2hex(random_bytes(32));
        $expiresAt = (new \DateTimeImmutable('+' . $ttlMinutes . ' minutes'));

        $stmt = Database::pdo()->prepare(
            'INSERT INTO download_tokens (token_hash, license_id, release_id, issued_ip, expires_at, created_at)
             VALUES (:hash, :license, :release, :ip, :expires, NOW())'
        );
        $stmt->execute([
            'hash'    => $this->hashToken($token),
            'license' => $licenseId,
            'release' => $release['id'],
            'ip'      => $ip,
            'expires' => $expiresAt->format('Y-m-d H:i:s'),
        ]);

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
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            'SELECT t.*, r.filename, r.storage_path, r.checksum_sha256, r.version,
                    r.source, r.github_tag, r.github_asset,
                    l.id AS license_id, l.status AS license_status,
                    l.downloads_used, l.max_downloads, l.expires_at AS license_expires_at
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
        if ($row['used_at'] !== null) {
            $this->deny('This download link has already been used. Request a new one.', 410, ['reason' => 'token_used']);
        }
        if (strtotime((string) $row['expires_at']) < time()) {
            $this->deny('This download link has expired. Request a new one.', 410, ['reason' => 'token_expired']);
        }
        if ($row['license_status'] !== 'active') {
            $this->deny('This licence is no longer active.', 403, ['reason' => 'license_inactive']);
        }
        if ($row['license_expires_at'] !== null && strtotime((string) $row['license_expires_at']) < time()) {
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
        // that reconnects mid-transfer cannot farm extra downloads.
        $pdo->prepare('UPDATE download_tokens SET used_at = NOW(), used_ip = :ip WHERE id = :id')
            ->execute(['ip' => $ip, 'id' => $row['id']]);
        $pdo->prepare('UPDATE licenses SET downloads_used = downloads_used + 1 WHERE id = :id')
            ->execute(['id' => $row['license_id']]);

        Logger::info('Download served', [
            'license_id' => $row['license_id'],
            'file'       => $row['filename'],
            'version'    => $row['version'],
            'source'     => $row['source'],
            'ip'         => $ip,
        ]);

        if ($githubUrl !== null) {
            $this->redirect($githubUrl);
        }
        $this->send((string) $path, (string) $row['filename']);
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
     * Guarantees the resolved file really sits inside private-storage.
     * Without this, a poisoned storage_path ("../../public_html/.env") would
     * turn the download endpoint into an arbitrary file reader.
     */
    private function resolvePath(string $storagePath): string
    {
        $base = realpath(DIWAN_PRIVATE_STORAGE);
        if ($base === false) {
            throw new RuntimeException('Private storage directory does not exist on this server');
        }

        $full = realpath($base . '/releases/' . ltrim($storagePath, '/'));
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

    private function send(string $path, string $filename): never
    {
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
        while (!feof($handle)) {
            echo fread($handle, 8192);
            flush();
        }
        fclose($handle);
        exit;
    }

    private function deny(string $message, int $status, array $context = []): never
    {
        Logger::warning('Download denied', $context + ['status' => $status]);
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode(['error' => $message]);
        exit;
    }

    private function hashToken(string $token): string
    {
        return hash_hmac('sha256', $token, Env::require('APP_KEY'));
    }
}
