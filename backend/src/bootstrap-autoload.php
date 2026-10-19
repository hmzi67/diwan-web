<?php
/**
 * Autoloader only — for CLI scripts that need Diwan\* classes without the
 * rest of bootstrap.php (which loads config/.env, sets up logging, and
 * assumes it is being run by a web entrypoint). Same PSR-4 mapping.
 */
declare(strict_types=1);

if (!defined('DIWAN_SRC')) {
    define('DIWAN_SRC', __DIR__);
}

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


// Same libsodium fallback as bootstrap.php: the keygen script and the tests
// sign with Ed25519 too, on machines that may lack ext-sodium.
if (!extension_loaded('sodium')) {
    require_once DIWAN_SRC . '/ThirdParty/sodium_compat/autoload.php';
}
