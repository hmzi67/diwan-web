<?php
declare(strict_types=1);

namespace Diwan\Admin;

use Diwan\Database\Database;
use Diwan\Download\DownloadService;
use Diwan\Download\GitHubReleases;
use Diwan\Download\ReleaseRegistrar;
use Diwan\Notification\NotificationService;
use Diwan\Support\AuditLog;
use Diwan\Support\Logger;
use Diwan\Support\UserFacingException;
use PDO;
use RuntimeException;

/**
 * Application versions: the `releases` catalogue that downloads are served
 * from. An administrator registers a published GitHub release here (or with
 * scripts/sync-github-release.php), and can take a build offline, edit its
 * notes and tell licensed customers a new version is out.
 */
final class ReleaseAdmin
{
    /** What registering this tag would serve. Reads GitHub; changes nothing. */
    public function previewGithub(string $tag): array
    {
        return self::describe($tag, ReleaseRegistrar::choose($this->githubAssets($tag)));
    }

    public function registerGithub(string $tag, array $admin): array
    {
        return $this->registerAssets($tag, $this->githubAssets($tag), $admin);
    }

    /**
     * @param list<array{name:string,size:int}> $assets as GitHub reports them
     */
    public function registerAssets(string $tag, array $assets, array $admin): array
    {
        $chosen = ReleaseRegistrar::choose($assets);
        if (!$chosen) {
            throw new UserFacingException("Release {$tag} has no installers on it.", 422);
        }
        $summary = self::describe($tag, $chosen);
        Database::transaction(static function (PDO $pdo) use ($tag, $chosen, $summary, $admin): void {
            ReleaseRegistrar::save($pdo, $tag, $chosen);
            AuditLog::record('admin', (int) $admin['id'], (string) $admin['email'], 'release.register', 'release', null,
                ['tag' => $tag, 'platforms' => array_keys($chosen), 'not_on_release' => $summary['missing']], $pdo);
        });
        return $summary;
    }

    private function githubAssets(string $tag): array
    {
        if (!preg_match(ReleaseRegistrar::TAG_PATTERN, $tag)) {
            throw new UserFacingException('Enter a release tag, like v1.3.3.', 422);
        }
        try {
            return (new GitHubReleases())->assets($tag);
        } catch (RuntimeException $e) {
            Logger::warning('GitHub release lookup failed', ['tag' => $tag, 'error' => $e->getMessage()]);
            throw new UserFacingException(
                "Could not read release {$tag} from GitHub. Check the tag, and that the release has finished building: it stays a draft until every platform is done.",
                422
            );
        }
    }

    private static function describe(string $tag, array $chosen): array
    {
        $builds = [];
        foreach ($chosen as $platform => $variants) {
            foreach ($variants as $variant => $asset) {
                $builds[] = ['platform' => $platform, 'variant' => (string) $variant, 'filename' => $asset['name'], 'size_bytes' => $asset['size']];
            }
        }
        return [
            'tag'     => $tag,
            'version' => ReleaseRegistrar::versionOf($tag),
            'builds'  => $builds,
            'missing' => array_values(array_diff(DownloadService::PLATFORMS, array_keys($chosen))),
        ];
    }

    public function update(int $releaseId, array $input, array $admin): void
    {
        Database::transaction(static function (PDO $pdo) use ($releaseId, $input, $admin): void {
            $stmt = $pdo->prepare('SELECT id, version, platform, variant, is_active, release_notes FROM releases WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $releaseId]);
            $release = $stmt->fetch();
            if (!$release) {
                throw new UserFacingException('Version not found.', 404);
            }
            $changes = [];
            if (array_key_exists('is_active', $input)) {
                $active = !empty($input['is_active']) ? 1 : 0;
                $pdo->prepare('UPDATE releases SET is_active = :a WHERE id = :id')->execute(['a' => $active, 'id' => $releaseId]);
                $changes['is_active'] = (bool) $active;
            }
            if (array_key_exists('release_notes', $input)) {
                $notes = trim((string) $input['release_notes']);
                if (mb_strlen($notes) > 5000) {
                    throw new UserFacingException('Release notes can be at most 5000 characters.');
                }
                $pdo->prepare('UPDATE releases SET release_notes = :n WHERE id = :id')
                    ->execute(['n' => $notes === '' ? null : $notes, 'id' => $releaseId]);
                $changes['release_notes'] = 'edited';
            }
            AuditLog::record('admin', (int) $admin['id'], (string) $admin['email'], 'release.update', 'release', $releaseId,
                $changes + ['version' => $release['version'], 'platform' => $release['platform']], $pdo);
        });
    }

    /**
     * Tells every customer with a usable licence that $version is available.
     * Deduplicated per customer and version, so pressing it twice sends once.
     * Returns how many customers were newly notified.
     */
    public function announce(string $version, array $admin): int
    {
        $exists = Database::pdo()->prepare('SELECT COUNT(*) FROM releases WHERE version = :v AND is_active = 1');
        $exists->execute(['v' => $version]);
        if ((int) $exists->fetchColumn() === 0) {
            throw new UserFacingException('No active build has that version.', 404);
        }
        $customers = Database::pdo()->query(
            "SELECT DISTINCT l.customer_id FROM licenses l JOIN customers c ON c.id = l.customer_id
              WHERE l.status = 'active' AND (l.expires_at IS NULL OR l.expires_at > NOW()) AND c.status = 'active'"
        )->fetchAll(PDO::FETCH_COLUMN);
        $notifications = new NotificationService();
        $sent = 0;
        foreach ($customers as $customerId) {
            $id = $notifications->toCustomer((int) $customerId, 'release.available', "Diwan {$version} is available",
                "A new version of Diwan POS ({$version}) is ready. Download it from your dashboard and install it over the current one — your data stays.",
                '/dashboard.html#setup', true, "release:{$version}:{$customerId}");
            $sent += $id !== null ? 1 : 0;
        }
        AuditLog::record('admin', (int) $admin['id'], (string) $admin['email'], 'release.announce', 'release', null,
            ['version' => $version, 'customers_notified' => $sent]);
        return $sent;
    }
}
