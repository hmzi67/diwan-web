<?php
/**
 * Creates the first (or another) platform administrator, or resets one's
 * password. The only way an admin account comes into existence: there is no
 * web sign-up and no credential in code, SQL or .env.
 *
 *   php scripts/create-admin.php you@example.com "Your Name"            # prompts for the password
 *   php scripts/create-admin.php you@example.com --reset                 # new password, signs out everywhere
 *   ... --env=/path/to/.env                                              # default backend/config/.env
 *
 * The password is read from the terminal with echo off (or from STDIN when
 * piped, e.g. from a password manager) and is never printed or logged. On a
 * host without a shell, run this locally against the production database
 * from a machine allowed to reach it, or over the host's SSH/terminal.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/backend/src/bootstrap-autoload.php';

use Diwan\Admin\AdminAuth;
use Diwan\Config\Env;
use Diwan\Support\UserFacingException;

$envPath = dirname(__DIR__) . '/backend/config/.env';
$positional = [];
$reset = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--reset') {
        $reset = true;
    } elseif (str_starts_with($arg, '--env=')) {
        $envPath = substr($arg, 6);
    } else {
        $positional[] = $arg;
    }
}
if ($positional === []) {
    fwrite(STDERR, "Usage: php scripts/create-admin.php <email> [\"Name\"] [--reset] [--env=path]\n");
    exit(2);
}
Env::load($envPath);

/** Reads one line without echoing it when attached to a terminal. */
function readSecret(string $prompt): string
{
    $tty = function_exists('posix_isatty') && posix_isatty(STDIN);
    if ($tty) {
        fwrite(STDERR, $prompt);
        shell_exec('stty -echo');
    }
    $line = fgets(STDIN);
    if ($tty) {
        shell_exec('stty echo');
        fwrite(STDERR, "\n");
    }
    return rtrim((string) $line, "\r\n");
}

$password = readSecret('Admin password (at least ' . AdminAuth::MIN_PASSWORD_LENGTH . ' characters): ');
if (function_exists('posix_isatty') && posix_isatty(STDIN) && readSecret('Repeat password: ') !== $password) {
    fwrite(STDERR, "Passwords do not match.\n");
    exit(1);
}

try {
    $id = AdminAuth::createOrReset($positional[0], $positional[1] ?? '', $password, $reset);
} catch (UserFacingException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
echo ($reset ? 'Password reset' : 'Administrator created') . " (id {$id}). Sign in at " . Env::get('APP_URL', '') . "/admin/\n";
