<?php
/**
 * Shared helpers for the dependency-free test scripts (scripts/tests/run.php
 * and scripts/test-trial.php). Everything runs against a disposable MySQL
 * database named diwan_test_*; fixtures are tagged so cleanup() removes
 * exactly what a run created and never truncates a table.
 */
declare(strict_types=1);

use Diwan\Database\Database;
use Diwan\Order\OrderService;

final class TestFailure extends RuntimeException
{
}

final class TestKit
{
    /** Every fixture customer's email ends with this. */
    public const EMAIL_DOMAIN = '@tests.invalid';

    /** Versions of fixture releases start with this. */
    public const RELEASE_PREFIX = 'test-';

    /** Codes of fixture plans and features start with this. */
    public const CODE_PREFIX = 'test_';

    /** Highest ids before the run, for rows that carry no fixture marker. */
    private static array $baseline = [];

    /** Records where shared append-only tables stood, so cleanup() removes only this run's rows. */
    public static function begin(): void
    {
        foreach (['notifications', 'audit_logs'] as $table) {
            self::$baseline[$table] = (int) Database::pdo()->query("SELECT COALESCE(MAX(id), 0) FROM {$table}")->fetchColumn();
        }
    }

    /** An administrator fixture; returns the admin row AdminGuard would. */
    public static function admin(string $password = 'correct horse battery'): array
    {
        $id = Diwan\Admin\AdminAuth::createOrReset(self::email(), 'Test Admin', $password, false);
        $stmt = Database::pdo()->prepare('SELECT id, email, name, session_epoch, last_login_at FROM admin_users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch();
    }

    /** A verified customer, as plan requests require. */
    public static function verifiedCustomer(): int
    {
        $id = self::customer();
        self::exec('UPDATE customers SET email_verified_at = NOW() WHERE id = :id', ['id' => $id]);
        return $id;
    }

    /** Submits and approves a request; returns [approval result, customer id]. */
    public static function approvedPlan(string $plan = 'basic', ?int $customerId = null): array
    {
        $customerId ??= self::verifiedCustomer();
        $request = (new Diwan\License\LicenseRequestService())->submit($customerId, $plan, ['business_name' => 'Test Shop']);
        $result = (new Diwan\License\LicenseRequestService())->approve((int) $request['id'], self::admin());
        return [$result, $customerId];
    }

    /** RFC 5737 TEST-NET-3: never a real client address. */
    public static function ip(): string
    {
        return '203.0.113.' . random_int(1, 254);
    }

    public static function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new TestFailure($message);
        }
    }

    public static function same(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new TestFailure($message . ' — expected ' . var_export($expected, true)
                . ', got ' . var_export($actual, true));
        }
    }

    /**
     * Runs $operation and requires it to throw $class. A different exception
     * fails the test instead of counting as "rejected", and $messagePart, when
     * given, must appear in the message — so a rejection for the wrong reason
     * cannot pass.
     */
    public static function throws(string $class, callable $operation, string $message, ?string $messagePart = null): void
    {
        try {
            $operation();
        } catch (Throwable $e) {
            if (!$e instanceof $class) {
                throw new TestFailure("{$message} — threw " . get_class($e) . ': ' . $e->getMessage());
            }
            if ($messagePart !== null && !str_contains($e->getMessage(), $messagePart)) {
                throw new TestFailure("{$message} — wrong reason: " . $e->getMessage());
            }
            return;
        }
        throw new TestFailure("{$message} — nothing was thrown");
    }

    public static function customer(?PDO $pdo = null, ?string $email = null): int
    {
        $pdo ??= Database::pdo();
        $pdo->prepare('INSERT INTO customers (email, created_at) VALUES (:email, NOW())')
            ->execute(['email' => $email ?? self::email()]);
        return (int) $pdo->lastInsertId();
    }

    public static function email(): string
    {
        return bin2hex(random_bytes(8)) . self::EMAIL_DOMAIN;
    }

    public static function emailOf(int $customerId): string
    {
        $stmt = Database::pdo()->prepare('SELECT email FROM customers WHERE id = :id');
        $stmt->execute(['id' => $customerId]);
        return (string) $stmt->fetchColumn();
    }

    /** A pending order for a real SKU; returns the order row. */
    public static function pendingOrder(int $customerId, string $sku = 'diwan-pos-starter', string $gateway = 'jazzcash'): array
    {
        $orders  = new OrderService();
        $pending = $orders->createPending($sku, $customerId, self::emailOf($customerId), '03000000000', $gateway);
        return $orders->findByRef($pending['order_ref']);
    }

    /** A paid order with an issued licence; returns [order row, raw key]. */
    public static function paidLicense(int $customerId): array
    {
        $order = self::pendingOrder($customerId);
        $key = Database::transaction(static function (PDO $pdo) use ($order): string {
            (new OrderService())->markPaid($pdo, $order['order_ref'], 'test-' . $order['order_ref']);
            return (string) (new Diwan\License\LicenseService())
                ->issueForOrder($pdo, (int) $order['id'], (string) $order['customer_email'])['license_key'];
        });
        return [$order, $key];
    }

    public static function scalar(string $sql, array $params = []): mixed
    {
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    public static function exec(string $sql, array $params = []): void
    {
        Database::pdo()->prepare($sql)->execute($params);
    }

    /** Deletes every row a run created, children before parents. */
    public static function cleanup(): void
    {
        $customers = 'SELECT id FROM customers WHERE email LIKE :domain';
        $orders    = "SELECT id FROM orders WHERE customer_id IN ({$customers})";
        $licenses  = "SELECT id FROM licenses WHERE order_id IN ({$orders})";
        $domain    = ['domain' => '%' . self::EMAIL_DOMAIN . '%'];   // trailing % also catches padded emails

        // MySQL refuses a subquery on the table being deleted from, hence the
        // derived tables (SELECT id FROM (...) t).
        $codes = ['code' => self::CODE_PREFIX . '%'];
        $businesses = "SELECT id FROM businesses WHERE license_id IN (SELECT id FROM ({$licenses}) t)";
        $statements = [
            "DELETE FROM business_sales WHERE business_id IN ({$businesses})" => $domain,
            "DELETE FROM sync_media WHERE business_id IN ({$businesses})" => $domain,
            "DELETE FROM sync_conflicts WHERE business_id IN ({$businesses})" => $domain,
            "DELETE FROM stock_reservations WHERE business_id IN ({$businesses})" => $domain,
            "DELETE FROM stock_ledger WHERE business_id IN ({$businesses})" => $domain,
            "DELETE FROM sync_stock WHERE business_id IN ({$businesses})" => $domain,
            "DELETE FROM sync_rows WHERE business_id IN ({$businesses})" => $domain,
            "DELETE FROM pos_terminals WHERE business_id IN ({$businesses})" => $domain,
            "DELETE FROM businesses WHERE license_id IN (SELECT id FROM ({$licenses}) t)" => $domain,
            "DELETE FROM download_events WHERE customer_id IN ({$customers})" => $domain,
            "DELETE FROM download_events WHERE license_id IN (SELECT id FROM ({$licenses}) t)" => $domain,
            'DELETE FROM download_events WHERE release_id IN (SELECT id FROM releases WHERE version LIKE :prefix)' => ['prefix' => self::RELEASE_PREFIX . '%'],
            "DELETE FROM license_activations WHERE license_id IN (SELECT id FROM ({$licenses}) t)" => $domain,
            "DELETE FROM download_tokens WHERE license_id IN (SELECT id FROM ({$licenses}) t)" => $domain,
            "DELETE FROM webhook_events WHERE order_ref IN (SELECT order_ref FROM orders WHERE customer_id IN ({$customers}))" => $domain,
            "DELETE FROM trial_grants WHERE customer_id IN ({$customers})" => $domain,
            "DELETE FROM apple_transactions WHERE order_id IN (SELECT id FROM ({$orders}) t)" => $domain,
            "DELETE FROM licenses WHERE order_id IN (SELECT id FROM ({$orders}) t)" => $domain,
            "DELETE FROM license_requests WHERE customer_id IN ({$customers})" => $domain,
            "DELETE FROM orders WHERE customer_id IN (SELECT id FROM ({$customers}) t)" => $domain,
            "DELETE FROM password_reset_tokens WHERE customer_id IN ({$customers})" => $domain,
            "DELETE FROM email_verification_tokens WHERE customer_id IN ({$customers})" => $domain,
            "DELETE FROM notifications WHERE customer_id IN ({$customers})" => $domain,
            'DELETE FROM customers WHERE email LIKE :domain' => $domain,
            'DELETE FROM admin_users WHERE email LIKE :domain' => $domain,
            'DELETE FROM plans WHERE code LIKE :code' => $codes,
            'DELETE FROM features WHERE code LIKE :code' => $codes,
            'DELETE FROM download_tokens WHERE release_id IN (SELECT id FROM releases WHERE version LIKE :prefix)' => ['prefix' => self::RELEASE_PREFIX . '%'],
            'DELETE FROM releases WHERE version LIKE :prefix' => ['prefix' => self::RELEASE_PREFIX . '%'],
            "DELETE FROM login_attempts WHERE ip LIKE '203.0.113.%'" => [],
            "DELETE FROM download_attempts WHERE ip LIKE '203.0.113.%'" => [],
            "DELETE FROM license_activation_attempts WHERE ip LIKE '203.0.113.%'" => [],
            "DELETE FROM admin_login_attempts WHERE ip LIKE '203.0.113.%'" => [],
            'DELETE FROM notifications WHERE id > :after' => ['after' => self::$baseline['notifications'] ?? PHP_INT_MAX],
            'DELETE FROM audit_logs WHERE id > :after' => ['after' => self::$baseline['audit_logs'] ?? PHP_INT_MAX],
        ];
        foreach ($statements as $sql => $params) {
            self::exec($sql, $params);
        }
    }
}
