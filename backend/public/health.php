<?php
/**
 * Deploy smoke test. Reports whether the app can boot, reach MySQL and see
 * private-storage — without ever revealing paths or credentials.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Auth\PasswordService;
use Diwan\Config\Env;
use Diwan\Database\Database;
use Diwan\Download\DownloadService;
use Diwan\License\LicenseSigner;
use Diwan\Support\Http;
use Diwan\Support\Logger;

$checks = [
    'env'             => true, // reaching here means the .env parsed
    'app_key'         => false,
    'database'        => false,
    'private_storage' => false,
    'license_signing' => false,
];

$hints = [];

// The desktop/Android app refreshes its licence from license-status.php, which
// constructs LicenseSigner only after a real key has been matched — so a
// missing or malformed signing key 500s every genuine licence check while a
// bogus-key probe (403, never reaches the signer) and this endpoint both looked
// healthy. Constructing it here is the same check the real request makes.
try {
    new LicenseSigner();
    $checks['license_signing'] = true;
} catch (Throwable $e) {
    // A fixed vocabulary naming which precondition failed — never the key,
    // its length, or the exception message.
    $signingKey = Env::get('LICENSE_SIGNING_PRIVATE_KEY');
    $decoded    = $signingKey === null ? false : base64_decode($signingKey, true);
    $hints['license_signing'] = match (true) {
        !function_exists('sodium_crypto_sign_detached') => 'sodium_extension_missing',
        $signingKey === null                             => 'not_configured',
        $decoded === false                               => 'key_not_base64',
        strlen($decoded) !== 64                          => 'key_wrong_length',
        default                                          => 'unknown',
    };
}

// APP_KEY signs every licence lookup and download token. Without it the store
// is dead — but nothing else in this endpoint touches it, so before this check
// health could report "ok" while download.php returned 500 on every request.
$appKey = Env::get('APP_KEY');
$checks['app_key'] = $appKey !== null && strlen($appKey) >= 32;
if ($appKey === null) {
    $hints['app_key'] = 'not_configured';
} elseif (strlen($appKey) < 32) {
    $hints['app_key'] = 'too_short';   // needs >= 32 chars; generate 32 random bytes, base64
}

try {
    Database::ping();
    $checks['database'] = true;
} catch (Throwable $e) {
    // Neither the response nor the log carries the driver message: it can name
    // the host, the user and the database. The category and code are enough.
    $hints['database'] = Database::classifyFailure($e);
    Logger::error('Health check: database unreachable', [
        'category'  => $hints['database'],
        'exception' => get_class($e),
        'code'      => $e->getCode(),
    ]);

    // Naming the absent keys is safe — they are documented in .env.example —
    // and it turns "not_configured" into something you can act on directly.
    if ($hints['database'] === 'not_configured') {
        $missing = [];
        foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $key) {
            if (Env::get($key) === null) {
                $missing[] = $key;
            }
        }
        if ($missing !== []) {
            $hints['database_missing_keys'] = $missing;
        }
    }
}

$checks['private_storage'] = is_dir(DIWAN_PRIVATE_STORAGE) && is_readable(DIWAN_PRIVATE_STORAGE);
if (!$checks['private_storage']) {
    $hints['private_storage'] = 'missing_directory';
} elseif (!is_writable(DIWAN_PRIVATE_STORAGE . '/logs')) {
    $hints['private_storage'] = 'logs_not_writable';
}

// Installers: informational only, so a deploy before the first release is
// registered still reports healthy. Names the platforms with nothing to serve,
// and flags GitHub-hosted releases that have no token to fetch them with.
if ($checks['database'] ?? false) {
    try {
        $sources = DownloadService::activeSources();
        $missing = array_values(array_diff(DownloadService::PLATFORMS, array_keys($sources)));
        if ($missing !== []) {
            $hints['downloads_missing'] = $missing;
        }
        if (in_array('github', $sources, true) && Env::get('GITHUB_RELEASES_TOKEN') === null) {
            $hints['downloads'] = 'github_token_missing';
        }
    } catch (Throwable $e) {
        // Pre-010 schema (no `source` column): say so rather than failing health.
        $hints['downloads'] = 'migration_010_not_applied';
    }
}

$ok = !in_array(false, $checks, true);

$body = [
    'status' => $ok ? 'ok' : 'degraded',
    'env'    => Env::get('APP_ENV', 'unknown'),
    'commit' => Env::get('APP_COMMIT', 'unknown'),
    'checks' => $checks,
    // Which password algorithm this host actually gives us. Shared hosting
    // often ships PHP without libargon2, and that is not visible from the PHP
    // version alone — PasswordService picks the strongest available rather
    // than hardcoding one, so this reports what it settled on. Safe to expose:
    // the algorithm is already recoverable from any hash's prefix.
    'password_algo' => PasswordService::algorithmName(),
];

// Off by default: the hints name missing config keys and failure categories
// to anyone who asks. Set HEALTH_DIAGNOSTICS=true while debugging a deploy.
if ($hints !== [] && Env::bool('HEALTH_DIAGNOSTICS', false)) {
    $body['hints'] = $hints;
}

Http::json($body, $ok ? 200 : 503);

