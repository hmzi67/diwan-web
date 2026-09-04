<?php
/**
 * Diwan application bootstrap.
 *
 * This file lives OUTSIDE the web root. Nothing here is reachable by URL —
 * it is only ever pulled in by an entrypoint in public_html/api/.
 */
declare(strict_types=1);

define('DIWAN_SRC', __DIR__);
define('DIWAN_ROOT', dirname(__DIR__));

// --- PSR-4 style autoloader (no Composer: shared hosting is FTP-only) ---
spl_autoload_register(static function (string $class): void {
    $prefix = 'Diwan\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = DIWAN_SRC . '/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use Diwan\Config\Env;
use Diwan\Support\Logger;

Env::load(DIWAN_ROOT . '/config/.env');

// --- Error handling: never leak stack traces to a paying customer ---
$debug = Env::bool('APP_DEBUG', false);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', Env::get('PRIVATE_STORAGE_PATH', DIWAN_ROOT . '/private-storage') . '/logs/php-error.log');
error_reporting(E_ALL);

set_exception_handler(static function (Throwable $e) use ($debug): void {
    Logger::error('Unhandled exception', [
        'message' => $e->getMessage(),
        'file'    => $e->getFile() . ':' . $e->getLine(),
    ]);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    echo json_encode([
        'error' => $debug ? $e->getMessage() : 'Internal server error',
    ]);
    exit(1);
});

date_default_timezone_set('Asia/Karachi');
