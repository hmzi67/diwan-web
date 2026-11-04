<?php
declare(strict_types=1);

namespace Diwan\Download;

use PDO;

/**
 * Turns a GitHub release's assets into the rows downloads are served from.
 * Shared by scripts/sync-github-release.php and the admin panel, so both pick
 * the same installers and write the same rows. Installers stay on GitHub.
 *
 * Picking, per platform (anything else — .blockmap, .yml, .zip, .aab, .tar.gz,
 * checksums — is ignored):
 *   windows  a "-setup.exe" over any other .exe, then .msi; if the release has
 *            both a 64-bit and a 32-bit build, BOTH are registered (variants
 *            x64 / x86) and the customer chooses; otherwise one build, variant ''
 *   macos    .dmg over .pkg; if the release has both an Apple Silicon and an
 *            Intel build, BOTH are registered (variants apple-silicon / intel)
 *            and the customer chooses; otherwise one build, variant ''
 *   linux    every package format present, one variant each: appimage, deb,
 *            rpm (a 64-bit build over any other within a format)
 *   android  a "universal" .apk if there is one, else the largest
 */
final class ReleaseRegistrar
{
    public const TAG_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/';

    public const DEACTIVATE_SQL = 'UPDATE releases SET is_active = 0 WHERE platform = %s';
    public const UPSERT_SQL = 'INSERT INTO releases (platform, variant, version, filename, storage_path, source, github_tag, github_asset, size_bytes, is_active, released_at)
VALUES (%s, %s, %s, %s, \'\', \'github\', %s, %s, %d, 1, NOW())
ON DUPLICATE KEY UPDATE filename = VALUES(filename), storage_path = \'\', source = \'github\',
  github_tag = VALUES(github_tag), github_asset = VALUES(github_asset),
  size_bytes = VALUES(size_bytes), is_active = 1';

    private const EXTENSIONS = [
        'exe' => 'windows', 'msi' => 'windows',
        'dmg' => 'macos',   'pkg' => 'macos',
        'appimage' => 'linux', 'deb' => 'linux', 'rpm' => 'linux',
        'apk' => 'android',
    ];

    /** The platform a file installs on, or null if it isn't an installer. */
    public static function platformOf(string $filename): ?string
    {
        return self::EXTENSIONS[strtolower(pathinfo($filename, PATHINFO_EXTENSION))] ?? null;
    }

    /**
     * The chosen builds: platform => [variant => asset].
     *
     * @param list<array{name:string,size:int}> $assets
     */
    public static function choose(array $assets): array
    {
        $byPlatform = [];
        foreach ($assets as $asset) {
            $platform = self::platformOf($asset['name']);
            if ($platform !== null) {
                $byPlatform[$platform][] = $asset;
            }
        }

        $chosen = [];
        foreach (DownloadService::PLATFORMS as $platform) {
            $candidates = $byPlatform[$platform] ?? [];
            if (!$candidates) {
                continue;
            }
            if ($platform === 'linux') {
                // Formats are not interchangeable (a .deb will not install on Fedora),
                // so each one present is its own build.
                $byFormat = [];
                foreach ($candidates as $asset) {
                    $byFormat[strtolower(pathinfo($asset['name'], PATHINFO_EXTENSION))][] = $asset;
                }
                ksort($byFormat);
                $chosen['linux'] = array_map([self::class, 'best'], $byFormat);
                continue;
            }
            if ($platform === 'windows') {
                $x64 = self::withArch($candidates, 'x64');
                $x86 = self::withArch($candidates, 'x86');
                if ($x64 && $x86) {
                    $chosen['windows'] = ['x64' => self::best($x64), 'x86' => self::best($x86)];
                    continue;
                }
            }
            if ($platform === 'macos') {
                $arm   = self::withArch($candidates, 'arm64');
                $intel = self::withArch($candidates, 'x64');
                if ($arm && $intel) {
                    $chosen['macos'] = ['apple-silicon' => self::best($arm), 'intel' => self::best($intel)];
                    continue;
                }
            }
            $chosen[$platform] = ['' => self::best($candidates)];
        }
        return $chosen;
    }

    /**
     * Makes exactly these builds the active ones. A platform with no build here
     * is left alone, so its customers keep the version they already get. The
     * caller owns the transaction, so a failure can't leave a platform with
     * nothing to download.
     */
    public static function save(PDO $pdo, string $tag, array $chosen): void
    {
        foreach ($chosen as $platform => $builds) {
            $pdo->prepare(sprintf(self::DEACTIVATE_SQL, ':platform'))->execute(['platform' => $platform]);
            foreach ($builds as $variant => $asset) {
                $pdo->prepare(sprintf(self::UPSERT_SQL, ':platform', ':variant', ':version', ':filename', ':tag', ':asset', $asset['size']))
                    ->execute([
                        'platform' => $platform,
                        'variant'  => (string) $variant,
                        'version'  => self::versionOf($tag),
                        'filename' => $asset['name'],
                        'tag'      => $tag,
                        'asset'    => $asset['name'],
                    ]);
            }
        }
    }

    public static function versionOf(string $tag): string
    {
        return ltrim($tag, 'vV');
    }

    /** CPU architecture from a file name: arm64, x64, x86, universal or ''. */
    private static function archOf(string $name): string
    {
        $n = strtolower($name);
        return match (true) {
            (bool) preg_match('/aarch64|arm64|apple[-_]?silicon/', $n) => 'arm64',
            (bool) preg_match('/x86[-_]64|x64|amd64|intel/', $n)       => 'x64',
            (bool) preg_match('/x86|i[36]86|ia32|[-_]386/', $n)        => 'x86',
            str_contains($n, 'universal')                              => 'universal',
            default                                                    => '',
        };
    }

    private static function withArch(array $candidates, string $arch): array
    {
        return array_values(array_filter($candidates, static fn (array $a): bool => self::archOf($a['name']) === $arch));
    }

    /** Higher is better; used to sort candidates for one slot. */
    private static function score(array $asset): array
    {
        $name = strtolower($asset['name']);
        $ext  = pathinfo($name, PATHINFO_EXTENSION);
        $arch = self::archOf($name);
        return [
            match ($arch) { 'x64', 'arm64', 'universal' => 2, '' => 1, default => 0 },
            match (true) {
                $ext === 'exe' && str_contains($name, 'setup') => 3,
                $ext === 'dmg', $ext === 'apk'                 => 3,
                $ext === 'exe'                                 => 2,
                default                                        => 1,   // .msi, .pkg
            },
            str_contains($name, 'universal') ? 1 : 0,
            $asset['size'],
        ];
    }

    private static function best(array $candidates): array
    {
        usort($candidates, static fn (array $a, array $b): int => self::score($b) <=> self::score($a));
        return $candidates[0];
    }
}
