<?php
/**
 * Registers the installers on a GitHub release as the active downloads.
 *
 *   php scripts/sync-github-release.php v1.1.5            # write to the DB in .env
 *   php scripts/sync-github-release.php v1.1.5 --sql      # print SQL for phpMyAdmin
 *   php scripts/sync-github-release.php v1.1.5 --dry-run  # just show the choice
 *   ... --env=/path/to/.env                               # default backend/config/.env
 *   ... --asset=windows=Diwan_x64-setup.exe               # override a pick
 *   ... --asset=macos:intel=Diwan_x64.dmg                 # override one variant
 *
 * Picking, per platform (anything else — .blockmap, .yml, .zip, .deb,
 * .tar.gz, checksums — is ignored):
 *   windows  a "-setup.exe" over any other .exe, then .msi; if the release has
 *            both a 64-bit and a 32-bit build, BOTH are registered (variants
 *            x64 / x86) and the customer chooses; otherwise one build, variant ''
 *   macos    .dmg over .pkg; if the release has both an Apple Silicon and an
 *            Intel build, BOTH are registered (variants apple-silicon / intel)
 *            and the customer chooses; otherwise one build, variant ''
 *   linux    every package format present, one variant each: appimage, deb,
 *            rpm (a 64-bit build over any other within a format)
 *   android  a "universal" .apk if there is one, else the largest
 *
 * Every chosen asset is upserted into `releases` as source = github. The
 * platform's other releases are deactivated first, so exactly this release's
 * builds are served. Installers stay on GitHub; nothing is downloaded here.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/backend/src/bootstrap-autoload.php';

use Diwan\Config\Env;
use Diwan\Database\Database;
use Diwan\Download\DownloadService;
use Diwan\Download\GitHubReleases;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$usage = "Usage: php scripts/sync-github-release.php <tag> [--sql|--dry-run] [--env=path] [--asset=platform[:variant]=file]\n";
$tag = null;
$mode = 'write';
$envPath = dirname(__DIR__) . '/backend/config/.env';
$overrides = [];   // "platform:variant" => asset name
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--sql') {
        $mode = 'sql';
    } elseif ($arg === '--dry-run') {
        $mode = 'dry';
    } elseif (str_starts_with($arg, '--env=')) {
        $envPath = substr($arg, 6);
    } elseif (preg_match('/^--asset=(' . implode('|', DownloadService::PLATFORMS) . ')(?::([a-z0-9-]{1,32}))?=(.+)$/', $arg, $m)) {
        if ($m[2] !== '' && !in_array($m[2], DownloadService::VARIANTS[$m[1]], true)) {
            fwrite(STDERR, "Unknown {$m[1]} build '{$m[2]}'. Known: " . implode(', ', DownloadService::VARIANTS[$m[1]]) . "\n");
            exit(2);
        }
        $overrides[$m[1] . ':' . $m[2]] = $m[3];
    } elseif (!str_starts_with($arg, '--') && $tag === null) {
        $tag = $arg;
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n{$usage}");
        exit(2);
    }
}
if ($tag === null || !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $tag)) {
    fwrite(STDERR, $usage);
    exit(2);
}

Env::load($envPath);

// In --sql mode stdout is only SQL (pipe it to a file); progress goes to stderr.
$log = static function (string $line) use ($mode): void {
    fwrite($mode === 'sql' ? STDERR : STDOUT, $line);
};

const EXTENSIONS = [
    'exe' => 'windows', 'msi' => 'windows',
    'dmg' => 'macos',   'pkg' => 'macos',
    'appimage' => 'linux', 'deb' => 'linux', 'rpm' => 'linux',
    'apk' => 'android',
];

/** CPU architecture from a file name: arm64, x64, x86, universal or ''. */
function archOf(string $name): string
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

/** Higher is better; used to sort candidates for one slot. */
function score(array $asset): array
{
    $name = strtolower($asset['name']);
    $ext  = pathinfo($name, PATHINFO_EXTENSION);
    $arch = archOf($name);
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

function best(array $candidates): array
{
    usort($candidates, static fn (array $a, array $b): int => score($b) <=> score($a));
    return $candidates[0];
}

try {
    $assets = (new GitHubReleases())->assets($tag);
} catch (RuntimeException $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

$byName = array_column($assets, null, 'name');
$byPlatform = [];
foreach ($assets as $asset) {
    $platform = EXTENSIONS[strtolower(pathinfo($asset['name'], PATHINFO_EXTENSION))] ?? null;
    if ($platform === null) {
        $log("  skip      {$asset['name']}\n");
        continue;
    }
    $byPlatform[$platform][] = $asset;
}

// Chosen builds: platform => [variant => asset].
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
        $chosen['linux'] = array_map('best', $byFormat);
        continue;
    }
    if ($platform === 'windows') {
        $x64 = array_values(array_filter($candidates, static fn ($a) => archOf($a['name']) === 'x64'));
        $x86 = array_values(array_filter($candidates, static fn ($a) => archOf($a['name']) === 'x86'));
        if ($x64 && $x86) {
            $chosen['windows'] = ['x64' => best($x64), 'x86' => best($x86)];
            continue;
        }
    }
    if ($platform === 'macos') {
        $arm   = array_values(array_filter($candidates, static fn ($a) => archOf($a['name']) === 'arm64'));
        $intel = array_values(array_filter($candidates, static fn ($a) => archOf($a['name']) === 'x64'));
        if ($arm && $intel) {
            $chosen['macos'] = ['apple-silicon' => best($arm), 'intel' => best($intel)];
            continue;
        }
    }
    $chosen[$platform] = ['' => best($candidates)];
}

// Explicit --asset picks replace the automatic ones.
foreach ($overrides as $slot => $name) {
    [$platform, $variant] = explode(':', $slot, 2);
    if (!isset($byName[$name])) {
        fwrite(STDERR, "Error: {$name} is not an asset on {$tag}\n");
        exit(1);
    }
    if ($variant === '') {
        $chosen[$platform] = ['' => $byName[$name]];
    } else {
        unset($chosen[$platform]['']);
        $chosen[$platform][$variant] = $byName[$name];
    }
}

$version = ltrim($tag, 'vV');
$log("\n");
foreach (DownloadService::PLATFORMS as $platform) {
    if (empty($chosen[$platform])) {
        $log("  MISSING   no {$platform} installer on {$tag}\n");
        continue;
    }
    foreach ($chosen[$platform] as $variant => $asset) {
        $label = $variant === '' ? $platform : "{$platform}:{$variant}";
        $log(sprintf("  %-22s %s (%.1f MB)\n", $label, $asset['name'], $asset['size'] / 1048576));
        $others = count($byPlatform[$platform]) - count($chosen[$platform]);
    }
    if ($others > 0) {
        $log("  " . str_repeat(' ', 22) . "(ignored {$others} other {$platform} file" . ($others === 1 ? '' : 's') . "; override with --asset=)\n");
    }
}

if (!$chosen) {
    fwrite(STDERR, "No installers found on {$tag}.\n");
    exit(1);
}
if ($mode === 'dry') {
    exit(0);
}

$deactivate = 'UPDATE releases SET is_active = 0 WHERE platform = %s';
$upsert = 'INSERT INTO releases (platform, variant, version, filename, storage_path, source, github_tag, github_asset, size_bytes, is_active, released_at)
VALUES (%s, %s, %s, %s, \'\', \'github\', %s, %s, %d, 1, NOW())
ON DUPLICATE KEY UPDATE filename = VALUES(filename), storage_path = \'\', source = \'github\',
  github_tag = VALUES(github_tag), github_asset = VALUES(github_asset),
  size_bytes = VALUES(size_bytes), is_active = 1';

if ($mode === 'sql') {
    // Values come from GitHub asset names; quote them the way MySQL expects.
    $q = static fn (string $v): string => "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $v) . "'";
    echo "-- Diwan: register GitHub release {$tag} as the active downloads.\n";
    echo "-- Paste into phpMyAdmin (SQL tab) on the production database. Needs migration 010 (and 012 for Linux).\n";
    echo "START TRANSACTION;\n";
    foreach ($chosen as $platform => $builds) {
        echo sprintf($deactivate, $q($platform)) . ";\n";
        foreach ($builds as $variant => $asset) {
            echo sprintf($upsert, $q($platform), $q((string) $variant), $q($version), $q($asset['name']), $q($tag), $q($asset['name']), $asset['size']) . ";\n";
        }
    }
    echo "COMMIT;\n";
    exit(0);
}

$pdo = Database::pdo();
Database::transaction(static function () use ($pdo, $chosen, $version, $tag, $upsert, $deactivate): void {
    foreach ($chosen as $platform => $builds) {
        $pdo->prepare(sprintf($deactivate, ':platform'))->execute(['platform' => $platform]);
        foreach ($builds as $variant => $asset) {
            $pdo->prepare(sprintf($upsert, ':platform', ':variant', ':version', ':filename', ':tag', ':asset', $asset['size']))
                ->execute([
                    'platform' => $platform,
                    'variant'  => (string) $variant,
                    'version'  => $version,
                    'filename' => $asset['name'],
                    'tag'      => $tag,
                    'asset'    => $asset['name'],
                ]);
        }
    }
});
$log("\nRegistered {$tag} as the active release for: " . implode(', ', array_keys($chosen)) . "\n");
