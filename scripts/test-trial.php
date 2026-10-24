<?php
/**
 * Trial licensing regression checks against a disposable MySQL database; no
 * test framework. Run twice by CI: once with TRIAL_SIGNUP_ENABLED=true, once
 * with it false to check the kill switch. The wider suite is scripts/tests/.
 *
 *   php scripts/test-trial.php /path/to/test.env
 */
declare(strict_types=1);

require dirname(__DIR__) . '/backend/src/bootstrap-autoload.php';
require __DIR__ . '/tests/TestKit.php';

use Diwan\Config\Env;
use Diwan\Database\Database;
use Diwan\License\LicenseService;
use Diwan\Order\OrderService;
use Diwan\Trial\TrialService;

if (PHP_SAPI !== 'cli' || !isset($argv[1])) {
    throw new RuntimeException('Usage: php scripts/test-trial.php /path/to/test.env');
}
Env::load($argv[1]);
if (!str_starts_with(Env::require('DB_NAME'), 'diwan_test_')) {
    throw new RuntimeException('Use a disposable database named diwan_test_*.');
}
$pdo = Database::pdo();
$trials = new TrialService();
$licenses = new LicenseService();
$orders = new OrderService();

function check(bool $condition, string $message): void
{
    TestKit::check($condition, $message);
    echo "PASS: {$message}\n";
}

/** Requires the issuance-path guard specifically, not just any failure. */
function rejectsIneligible(callable $operation, string $message): void
{
    TestKit::throws(RuntimeException::class, $operation, $message, 'not eligible');
    echo "PASS: {$message}\n";
}

function count_rows(string $sql, int $customerId): int
{
    return (int) TestKit::scalar($sql, ['id' => $customerId]);
}

/**
 * Applies a migration file statement by statement. Splits only on a ';' that
 * ends a line, after removing -- and block comments, which is the shape every
 * file in database/migrations/ follows.
 */
function apply_migration(PDO $pdo, string $file): void
{
    $sql = (string) file_get_contents($file);
    $sql = (string) preg_replace(['~/\*.*?\*/~s', '/^\s*--.*$/m'], '', $sql);
    foreach (preg_split('/;\s*$/m', $sql) as $statement) {
        if (trim($statement) !== '') {
            $pdo->query($statement)->closeCursor();
        }
    }
}

// Worker mode permits a real concurrent-issuance check with separate PDO connections.
if (($argv[2] ?? '') === '--claim') {
    $id = (int) ($argv[3] ?? 0);
    echo json_encode(Database::transaction(static fn (PDO $pdo): array => $trials->ensureForCustomer($pdo, $id)));
    exit;
}

// A separate invocation verifies the kill switch without changing application config.
if (!Env::bool('TRIAL_SIGNUP_ENABLED', false)) {
    $pdo->beginTransaction();
    try {
        $id = TestKit::customer($pdo);
        check($trials->ensureForCustomer($pdo, $id)['status'] === 'disabled', 'Disabled trials create no grant');
        check(count_rows('SELECT COUNT(*) FROM orders WHERE customer_id = :id', $id) === 0, 'Disabled trials create no order');
    } finally {
        $pdo->rollBack();
    }
    exit;
}

// Issuance and activation manage transactions separately, like HTTP requests.
try {
    $id = Database::transaction(static function (PDO $pdo) use ($trials): int {
        $id = TestKit::customer($pdo);
        check($trials->ensureForCustomer($pdo, $id)['status'] === 'issued', 'New account receives a trial');
        return $id;
    });
    $stmt = $pdo->prepare('SELECT l.*, o.order_ref, o.gateway, o.amount_paisa, o.paid_at,
        o.gateway_txn_id, o.status AS order_status,
        TIMESTAMPDIFF(SECOND, l.created_at, l.expires_at) AS lifetime,
        UNIX_TIMESTAMP(l.expires_at) AS expires_ts
        FROM licenses l JOIN orders o ON o.id = l.order_id WHERE o.customer_id = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    check($row['gateway'] === 'trial' && $row['order_status'] === 'fulfilled'
        && (int) $row['amount_paisa'] === 0 && $row['paid_at'] === null
        && $row['gateway_txn_id'] === null, 'Trial order is distinguishable from payment');
    check((int) $row['lifetime'] === TrialService::DAYS * 86400, 'Trial lasts TrialService::DAYS');
    $key = $licenses->decrypt($row['license_key_encrypted']);
    check($key !== null && $licenses->findActive($key) !== null, 'Account key decrypts and authorizes downloads');

    for ($i = 0; $i < 3; $i++) {
        $result = Database::transaction(static fn (PDO $pdo): array => $trials->ensureForCustomer($pdo, $id));
        check($result['status'] === 'existing_trial', 'Retry reuses the trial');
    }
    check(count_rows('SELECT COUNT(*) FROM orders WHERE customer_id = :id', $id) === 1, 'One order after repeated requests');
    check(!$orders->markPaid($pdo, $row['order_ref'], 'must-not-be-written'), 'Payment transition cannot mark a trial paid');
    rejectsIneligible(static fn () => $licenses->issueForOrder($pdo, (int) $row['order_id'], $row['customer_email']), 'Paid issuer rejects a trial order');
    TestKit::throws(RuntimeException::class, static fn () => $orders->product(TrialService::SKU), 'Trial SKU is unavailable to checkout', 'inactive product');
    echo "PASS: Trial SKU is unavailable to checkout\n";

    $fingerprint = hash('sha256', 'device-a');
    check($licenses->activate($key, $fingerprint, 'Test device')['result'] === 'activated', 'Trial binds to first device');
    check($licenses->activate($key, $fingerprint, null)['result'] === 'already_active_same_device', 'Same-device activation is idempotent');
    check($licenses->activate($key, hash('sha256', 'device-b'), null)['result'] === 'device_mismatch', 'Trial rejects a second device');
    check($licenses->statusForDevice($key, $fingerprint)['expires_at'] !== null, 'Device status includes trial expiry');

    // Exercise the actual repair SQL, including its diagnostic statements.
    apply_migration($pdo, dirname(__DIR__) . '/database/migrations/005_fix_license_expiry.sql');
    check((int) TestKit::scalar('SELECT UNIX_TIMESTAMP(expires_at) FROM licenses WHERE id = :id', ['id' => $row['id']])
        === (int) $row['expires_ts'], 'Repair migration preserves trial expiry');

    TestKit::exec('UPDATE licenses SET expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = :id', ['id' => $row['id']]);
    check($licenses->findActive($key) === null, 'Expired trial cannot authorize downloads');
    check($licenses->activate($key, $fingerprint, null)['result'] === 'invalid_key', 'Expired trial cannot activate');
    check($licenses->statusForDevice($key, $fingerprint)['status'] === 'expired', 'Bound device receives expired status');
    $result = Database::transaction(static fn (PDO $pdo): array => $trials->ensureForCustomer($pdo, $id));
    check($result['status'] === 'existing_trial', 'Expired trial is not reissued');
    TestKit::exec('UPDATE licenses SET status = "revoked" WHERE id = :id', ['id' => $row['id']]);
    $result = Database::transaction(static fn (PDO $pdo): array => $trials->ensureForCustomer($pdo, $id));
    check($result['status'] === 'existing_trial', 'Revoked trial is not reissued');

    $paidCustomer = TestKit::customer();
    [$paid] = TestKit::paidLicense($paidCustomer);
    check(TestKit::scalar('SELECT expires_at FROM licenses WHERE order_id = :id', ['id' => $paid['id']]) === null, 'Paid licence remains perpetual');
    rejectsIneligible(static fn () => $licenses->issueTrialForOrder($pdo, (int) $paid['id'], $paid['customer_email']), 'Trial issuer rejects paid orders');
    $result = Database::transaction(static fn (PDO $pdo): array => $trials->ensureForCustomer($pdo, $paidCustomer));
    check($result['status'] === 'paid_license', 'Paid owner retains existing entitlement');

    $raceCustomer = TestKit::customer();
    $workers = [];
    for ($i = 0; $i < 2; $i++) {
        $process = proc_open([PHP_BINARY, __FILE__, $argv[1], '--claim', (string) $raceCustomer],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start concurrent trial worker');
        }
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    $outcomes = [];
    foreach ($workers as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        check(proc_close($process) === 0, 'Concurrent trial request succeeds: ' . trim($error));
        $outcomes[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR)['status'];
    }
    sort($outcomes);
    check($outcomes === ['existing_trial', 'issued'], 'Concurrent requests issue exactly one trial');
    check(count_rows('SELECT COUNT(*) FROM licenses WHERE customer_id = :id', $raceCustomer) === 1, 'Concurrent requests persist exactly one licence');

    // Check that a mid-signup failure leaves neither account nor grant behind.
    $rollbackId = 0;
    try {
        Database::transaction(static function (PDO $pdo) use ($trials, &$rollbackId): void {
            $rollbackId = TestKit::customer($pdo);
            $trials->ensureForCustomer($pdo, $rollbackId);
            throw new LogicException('Simulated failure before commit');
        });
    } catch (LogicException $e) {
        // expected
    }
    check(count_rows('SELECT COUNT(*) FROM customers WHERE id = :id', $rollbackId) === 0, 'Failed signup rolls back account');
    check(count_rows('SELECT COUNT(*) FROM orders WHERE customer_id = :id', $rollbackId) === 0, 'Failed signup rolls back trial order');
} finally {
    // Only fixture accounts (@tests.invalid) are removed; never truncate shared tables.
    TestKit::cleanup();
}
