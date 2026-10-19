<?php
/**
 * Dependency-free test runner for the backend's security- and money-critical
 * paths. Runs against a disposable MySQL database (name must start with
 * diwan_test_), the same way CI does:
 *
 *   php scripts/tests/run.php /path/to/test.env [name-filter]
 *
 * The env file needs only the DB_* values and APP_KEY. Everything else the
 * tests need — gateway secrets, a fresh Ed25519 signing key, a private-storage
 * directory, a mail server that refuses connections — is added here, so a run
 * can never send real mail or touch real credentials.
 *
 * Each *.test.php file returns [test name => callable]. Exit code is 0 only
 * when every test passed.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !isset($argv[1])) {
    fwrite(STDERR, "Usage: php scripts/tests/run.php /path/to/test.env [name-filter]\n");
    exit(2);
}

require dirname(__DIR__, 2) . '/backend/src/bootstrap-autoload.php';
require __DIR__ . '/TestKit.php';

use Diwan\Config\Env;
use Diwan\License\LicenseSigner;

$base = @file_get_contents($argv[1]);
if ($base === false) {
    fwrite(STDERR, "Cannot read {$argv[1]}\n");
    exit(2);
}
$filter = $argv[2] ?? '';

// Deliberately far from any server timezone (UTC+14): production pins PHP to
// Asia/Karachi while MySQL follows its host, so code that mixes PHP's clock
// with MySQL's NOW() must fail here rather than drift silently in production.
date_default_timezone_set('Pacific/Kiritimati');

$storage = sys_get_temp_dir() . '/diwan-tests-' . bin2hex(random_bytes(4));
mkdir($storage . '/releases', 0700, true);
mkdir($storage . '/logs', 0700, true);
define('DIWAN_PRIVATE_STORAGE', $storage);

$signing = LicenseSigner::generateKeyPair();
$defaults = [
    'APP_ENV'                     => 'testing',
    'APP_URL'                     => 'https://shop.tests.invalid',
    'MAIL_DRIVER'                 => 'smtp',     // port 9 refuses at once: nothing is ever sent
    'MAIL_HOST'                   => '127.0.0.1',
    'MAIL_PORT'                   => '9',
    'MAIL_FROM'                   => 'no-reply@tests.invalid',
    'PAYMENT_GATEWAY'             => 'jazzcash',
    'JAZZCASH_MERCHANT_ID'        => 'TESTMERCHANT',
    'JAZZCASH_PASSWORD'           => 'test-password',
    'JAZZCASH_INTEGRITY_SALT'     => 'test-integrity-salt',
    'JAZZCASH_ENDPOINT'           => 'https://jazzcash.tests.invalid/pay',
    'EASYPAISA_STORE_ID'          => '12345',
    'EASYPAISA_HASH_KEY'          => '0123456789abcdef',
    'SAFEPAY_WEBHOOK_SECRET'      => 'test-safepay-secret',
    'LICENSE_SIGNING_KEY_ID'      => 'test-kid',
    'LICENSE_SIGNING_PRIVATE_KEY' => $signing['private'],
    'DOWNLOAD_MAX_ATTEMPTS'       => '3',
    'APPLE_BUNDLE_ID'             => 'com.diwan.pos',
    'TRIAL_SIGNUP_ENABLED'        => 'true',
];
// The given file wins for anything it sets; defaults fill the rest.
preg_match_all('/^\s*([A-Z0-9_]+)\s*=/m', $base, $m);
$extra = '';
foreach (array_diff_key($defaults, array_flip($m[1])) as $key => $value) {
    $extra .= "{$key}={$value}\n";
}
$envFile = $storage . '/test.env';
file_put_contents($envFile, rtrim($base) . "\n" . $extra);
Env::load($envFile);

if (!str_starts_with(Env::require('DB_NAME'), 'diwan_test_')) {
    fwrite(STDERR, "Refusing to run: DB_NAME must start with diwan_test_.\n");
    exit(2);
}

$tests = [];
foreach (glob(__DIR__ . '/*.test.php') as $file) {
    foreach (require $file as $name => $test) {
        $tests[basename($file, '.test.php') . ': ' . $name] = $test;
    }
}

$passed = 0;
$failed = [];
try {
    foreach ($tests as $name => $test) {
        if ($filter !== '' && stripos($name, $filter) === false) {
            continue;
        }
        try {
            $test();
            $passed++;
            echo "PASS  {$name}\n";
        } catch (Throwable $e) {
            $failed[] = $name;
            $where = $e instanceof TestFailure ? '' : ' (' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . ')';
            echo "FAIL  {$name}\n      {$e->getMessage()}{$where}\n";
        }
    }
} finally {
    TestKit::cleanup();
    Diwan\Apple\AppStoreJws::overrideRootForTesting(null);
    exec('rm -rf ' . escapeshellarg($storage));
}

echo "\n{$passed} passed, " . count($failed) . " failed\n";
exit($failed === [] ? 0 : 1);
