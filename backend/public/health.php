<?php
/**
 * Deploy smoke test. Reports whether the app can boot, reach MySQL and see
 * private-storage — without ever revealing paths or credentials.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Config\Env;
use Diwan\Database\Database;
use Diwan\Support\Http;
use Diwan\Support\Logger;

$checks = [
    'env'             => true, // reaching here means the .env parsed
    'database'        => false,
    'private_storage' => false,
];

$hints = [];

try {
    Database::pdo()->query('SELECT 1');
    $checks['database'] = true;
} catch (Throwable $e) {
    // Full detail goes to the log. The response carries only a coarse category,
    // never the driver message, the host, the user or the database name.
    Logger::error('Health check: database unreachable', ['message' => $e->getMessage()]);
    $hints['database'] = classifyDatabaseFailure($e);
}

$checks['private_storage'] = is_dir(DIWAN_PRIVATE_STORAGE) && is_readable(DIWAN_PRIVATE_STORAGE);
if (!$checks['private_storage']) {
    $hints['private_storage'] = 'missing_directory';
} elseif (!is_writable(DIWAN_PRIVATE_STORAGE . '/logs')) {
    $hints['private_storage'] = 'logs_not_writable';
}

$ok = !in_array(false, $checks, true);

$body = [
    'status' => $ok ? 'ok' : 'degraded',
    'env'    => Env::get('APP_ENV', 'unknown'),
    'commit' => Env::get('APP_COMMIT', 'unknown'),
    'checks' => $checks,
];

// Diagnostics are on by default so a fresh deploy is debuggable. Set
// HEALTH_DIAGNOSTICS=false in .env once the site is live and healthy.
if ($hints !== [] && Env::bool('HEALTH_DIAGNOSTICS', true)) {
    $body['hints'] = $hints;
}

Http::json($body, $ok ? 200 : 503);

/**
 * Maps a connection failure to a coarse category. Deliberately returns a fixed
 * vocabulary: enough to tell you which thing to go fix, not enough to help
 * anyone attack the database.
 */
function classifyDatabaseFailure(Throwable $e): string
{
    if ($e instanceof RuntimeException && str_contains($e->getMessage(), 'Missing required environment value')) {
        return 'not_configured';   // a DB_* value is absent from .env
    }
    $message = $e->getMessage();
    return match (true) {
        str_contains($message, '1045'), str_contains($message, 'Access denied')   => 'auth_failed',
        str_contains($message, '1049'), str_contains($message, 'Unknown database') => 'unknown_database',
        str_contains($message, '2002'), str_contains($message, '2005')             => 'unreachable',
        default                                                                    => 'unknown',
    };
}
