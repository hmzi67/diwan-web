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

$checks = [
    'env'             => true, // reaching here means the .env parsed
    'database'        => false,
    'private_storage' => false,
];

try {
    Database::pdo()->query('SELECT 1');
    $checks['database'] = true;
} catch (Throwable) {
    // Reported as false; details go to the log, not the response.
}

$checks['private_storage'] = is_dir(DIWAN_PRIVATE_STORAGE) && is_readable(DIWAN_PRIVATE_STORAGE);

$ok = !in_array(false, $checks, true);

Http::json([
    'status' => $ok ? 'ok' : 'degraded',
    'env'    => Env::get('APP_ENV', 'unknown'),
    'commit' => Env::get('APP_COMMIT', 'unknown'),
    'checks' => $checks,
], $ok ? 200 : 503);
