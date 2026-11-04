<?php
/**
 * Registers the installers on a GitHub release as the active downloads.
 * The admin panel's Application versions page does the same from a browser.
 *
 *   php scripts/sync-github-release.php v1.1.5            # write to the DB in .env
 *   php scripts/sync-github-release.php v1.1.5 --sql      # print SQL for phpMyAdmin
 *   php scripts/sync-github-release.php v1.1.5 --dry-run  # just show the choice
 *   ... --env=/path/to/.env                               # default backend/config/.env
 *   ... --asset=windows=Diwan_x64-setup.exe               # override a pick
 *   ... --asset=macos:intel=Diwan_x64.dmg                 # override one variant
 *
 * What gets picked per platform is described on Diwan\Download\ReleaseRegistrar.
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
use Diwan\Download\ReleaseRegistrar;

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
if ($tag === null || !preg_match(ReleaseRegistrar::TAG_PATTERN, $tag)) {
    fwrite(STDERR, $usage);
    exit(2);
}

Env::load($envPath);

// In --sql mode stdout is only SQL (pipe it to a file); progress goes to stderr.
$log = static function (string $line) use ($mode): void {
    fwrite($mode === 'sql' ? STDERR : STDOUT, $line);
};

try {
    $assets = (new GitHubReleases())->assets($tag);
} catch (RuntimeException $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

$byName = array_column($assets, null, 'name');
$byPlatform = [];
foreach ($assets as $asset) {
    $platform = ReleaseRegistrar::platformOf($asset['name']);
    if ($platform === null) {
        $log("  skip      {$asset['name']}\n");
        continue;
    }
    $byPlatform[$platform][] = $asset;
}

$chosen = ReleaseRegistrar::choose($assets);

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

$version = ReleaseRegistrar::versionOf($tag);
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

$deactivate = ReleaseRegistrar::DEACTIVATE_SQL;
$upsert = ReleaseRegistrar::UPSERT_SQL;

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

Database::transaction(static fn (PDO $pdo) => ReleaseRegistrar::save($pdo, $tag, $chosen));
$log("\nRegistered {$tag} as the active release for: " . implode(', ', array_keys($chosen)) . "\n");
