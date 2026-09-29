<?php
/** Integration checks against a disposable MySQL database; no test framework. */
declare(strict_types=1);

require dirname(__DIR__) . '/backend/src/bootstrap-autoload.php';

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
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS: {$message}\n";
}
function customer(PDO $pdo): int
{
    $pdo->prepare('INSERT INTO customers (email) VALUES (:email)')
        ->execute(['email' => bin2hex(random_bytes(12)) . '@trial-test.invalid']);
    return (int) $pdo->lastInsertId();
}
function rejected(callable $operation): bool
{
    try {
        $operation();
        return false;
    } catch (RuntimeException $e) {
        return true;
    }
}

// Worker mode permits a real concurrent-issuance check with separate PDO connections.
if (($argv[2] ?? '') === '--claim') {
    $id = (int) ($argv[3] ?? 0);
    echo json_encode(Database::transaction(static fn (PDO $pdo): array => $trials->ensureForCustomer($pdo, $id)));
    exit;
}

// A separate invocation verifies the kill switch without changing application config.
if (!Env::bool('TRIAL_SIGNUP_ENABLED', true)) {
    $pdo->beginTransaction();
    try {
        $id = customer($pdo);
        check($trials->ensureForCustomer($pdo, $id)['status'] === 'disabled', 'Disabled trials create no grant');
        check((int) $pdo->query('SELECT COUNT(*) FROM orders WHERE customer_id = ' . $id)->fetchColumn() === 0, 'Disabled trials create no order');
    } finally {
        $pdo->rollBack();
    }
    exit;
}

// Issuance and activation manage transactions separately, like HTTP requests.
$id = 0;
$paidCustomer = 0;
$raceCustomer = 0;
try {
    $id = Database::transaction(static function (PDO $pdo) use ($trials): int {
        $id = customer($pdo);
        check($trials->ensureForCustomer($pdo, $id)['status'] === 'issued', 'New account receives a trial');
        return $id;
    });
    $row = $pdo->query('SELECT l.*, o.order_ref, o.gateway, o.amount_paisa, o.paid_at,
        o.gateway_txn_id, o.status AS order_status,
        TIMESTAMPDIFF(SECOND, l.created_at, l.expires_at) AS lifetime
        FROM licenses l JOIN orders o ON o.id = l.order_id WHERE o.customer_id = ' . $id)->fetch();
    check($row['gateway'] === 'trial' && $row['order_status'] === 'fulfilled'
        && (int) $row['amount_paisa'] === 0 && $row['paid_at'] === null
        && $row['gateway_txn_id'] === null, 'Trial order is distinguishable from payment');
    check((int) $row['lifetime'] === 30 * 86400, 'Trial lasts exactly 30 days');
    $key = $licenses->decrypt($row['license_key_encrypted']);
    check($key !== null && $licenses->findActive($key) !== null, 'Account key decrypts and authorizes downloads');

    for ($i = 0; $i < 3; $i++) {
        $result = Database::transaction(static fn (PDO $pdo): array => $trials->ensureForCustomer($pdo, $id));
        check($result['status'] === 'existing_trial', 'Retry reuses the trial');
    }
    check((int) $pdo->query('SELECT COUNT(*) FROM orders WHERE customer_id = ' . $id)->fetchColumn() === 1, 'One order after repeated requests');
    check(!$orders->markPaid($pdo, $row['order_ref'], 'must-not-be-written'), 'Payment transition cannot mark a trial paid');
    check(rejected(static fn () => $licenses->issueForOrder($pdo, (int) $row['order_id'], $row['customer_email'])), 'Paid issuer rejects a trial order');
    check(rejected(static fn () => $orders->product('diwan-pos-trial')), 'Trial SKU is unavailable to checkout');

    $fingerprint = hash('sha256', 'device-a');
    check($licenses->activate($key, $fingerprint, 'Test device')['result'] === 'activated', 'Trial binds to first device');
    check($licenses->activate($key, $fingerprint, null)['result'] === 'already_active_same_device', 'Same-device activation is idempotent');
    check($licenses->activate($key, hash('sha256', 'device-b'), null)['result'] === 'device_mismatch', 'Trial rejects a second device');
    check($licenses->statusForDevice($key, $fingerprint)['expires_at'] !== null, 'Device status includes trial expiry');

    // Exercise the actual repair SQL, including its diagnostic statements.
    $repair = file_get_contents(dirname(__DIR__) . '/database/migrations/005_fix_license_expiry.sql');
    $repair = preg_replace('/^--.*$/m', '', $repair);
    foreach (explode(';', $repair) as $sql) {
        if (trim($sql) !== '') {
            $stmt = $pdo->query($sql);
            $stmt->closeCursor();
        }
    }
    check($pdo->query('SELECT expires_at FROM licenses WHERE id = ' . (int) $row['id'])->fetchColumn() === $row['expires_at'], 'Repair migration preserves trial expiry');

    $pdo->exec('UPDATE licenses SET expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = ' . (int) $row['id']);
    check($licenses->findActive($key) === null, 'Expired trial cannot authorize downloads');
    check($licenses->activate($key, $fingerprint, null)['result'] === 'invalid_key', 'Expired trial cannot activate');
    check($licenses->statusForDevice($key, $fingerprint)['status'] === 'expired', 'Bound device receives expired status');
    $result = Database::transaction(static fn (PDO $pdo): array => $trials->ensureForCustomer($pdo, $id));
    check($result['status'] === 'existing_trial', 'Expired trial is not reissued');
    $pdo->exec('UPDATE licenses SET status = "revoked" WHERE id = ' . (int) $row['id']);
    $result = Database::transaction(static fn (PDO $pdo): array => $trials->ensureForCustomer($pdo, $id));
    check($result['status'] === 'existing_trial', 'Revoked trial is not reissued');

    $paidCustomer = customer($pdo);
    $email = $pdo->query('SELECT email FROM customers WHERE id = ' . $paidCustomer)->fetchColumn();
    $pending = $orders->createPending('diwan-pos-starter', $paidCustomer, $email, '', 'test');
    $paid = $orders->findByRef($pending['order_ref']);
    Database::transaction(static function (PDO $pdo) use ($orders, $licenses, $paid, $email): void {
        check($orders->markPaid($pdo, $paid['order_ref'], 'test-payment'), 'Real order can transition to paid');
        $licenses->issueForOrder($pdo, (int) $paid['id'], $email);
    });
    check($pdo->query('SELECT expires_at FROM licenses WHERE order_id = ' . (int) $paid['id'])->fetchColumn() === null, 'Paid licence remains perpetual');
    check(rejected(static fn () => $licenses->issueTrialForOrder($pdo, (int) $paid['id'], $email)), 'Trial issuer rejects paid orders');
    $result = Database::transaction(static fn (PDO $pdo): array => $trials->ensureForCustomer($pdo, $paidCustomer));
    check($result['status'] === 'paid_license', 'Paid owner retains existing entitlement');

    $raceCustomer = customer($pdo);
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
    check((int) $pdo->query('SELECT COUNT(*) FROM licenses WHERE customer_id = ' . $raceCustomer)->fetchColumn() === 1, 'Concurrent requests persist exactly one licence');

    // Check that a mid-signup failure leaves neither account nor grant behind.
    $rollbackId = 0;
    try {
        Database::transaction(static function (PDO $pdo) use ($trials, &$rollbackId): void {
            $rollbackId = customer($pdo);
            $trials->ensureForCustomer($pdo, $rollbackId);
            throw new RuntimeException('Simulated failure before commit');
        });
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== 'Simulated failure before commit') {
            throw $e;
        }
    }
    check((int) $pdo->query('SELECT COUNT(*) FROM customers WHERE id = ' . $rollbackId)->fetchColumn() === 0, 'Failed signup rolls back account');
    check((int) $pdo->query('SELECT COUNT(*) FROM orders WHERE customer_id = ' . $rollbackId)->fetchColumn() === 0, 'Failed signup rolls back trial order');
} finally {
    // Only the generated test accounts are removed; never truncate shared tables.
    foreach ([$id, $paidCustomer, $raceCustomer] as $customerId) {
        if ($customerId > 0) {
            $pdo->exec('DELETE FROM trial_grants WHERE customer_id = ' . $customerId);
            $pdo->exec('DELETE FROM licenses WHERE customer_id = ' . $customerId);
            $pdo->exec('DELETE FROM orders WHERE customer_id = ' . $customerId);
            $pdo->exec('DELETE FROM customers WHERE id = ' . $customerId);
        }
    }
}
