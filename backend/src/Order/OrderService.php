<?php
declare(strict_types=1);

namespace Diwan\Order;

use Diwan\Database\Database;
use Diwan\License\LicenseService;
use Diwan\Support\Logger;
use PDO;
use RuntimeException;

final class OrderService
{
    /**
     * Creates a pending order and returns it. Amounts are stored in paisa.
     * $customerId ties the order to the already-authenticated customer whose
     * session created it (checkout.php requires a session — see there) so
     * dashboard-data.php and resend-license-email.php can find it by owner
     * instead of by trusting a typed email.
     */
    public function createPending(string $sku, int $customerId, string $email, string $phone, string $gateway): array
    {
        $pdo = Database::pdo();

        $product = $this->product($sku);
        $orderRef = self::newReference('DW');

        $stmt = $pdo->prepare(
            'INSERT INTO orders (order_ref, product_id, customer_id, customer_email, customer_phone,
                                 amount_paisa, currency, gateway, status, created_at)
             VALUES (:ref, :product, :customer, :email, :phone, :amount, :currency, :gateway, "pending", NOW())'
        );
        $stmt->execute([
            'ref'      => $orderRef,
            'product'  => $product['id'],
            'customer' => $customerId,
            'email'    => $email,
            'phone'    => $phone,
            'amount'   => $product['price_paisa'],
            'currency' => $product['currency'],
            'gateway'  => $gateway,
        ]);

        Logger::info('Order created', ['order_ref' => $orderRef, 'sku' => $sku]);

        return [
            'order_ref'    => $orderRef,
            'amount_paisa' => (int) $product['price_paisa'],
            'product'      => $product,
        ];
    }

    /**
     * Every order the customer owns, newest first, with its licence (if any)
     * and the decrypted key. Scoped by owner in SQL; the caller must send the
     * result with Cache-Control: no-store (Http::json does) and never put keys
     * in URLs, logs or browser storage.
     */
    public function dashboardRowsFor(int $customerId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT o.id AS order_id, o.order_ref, o.amount_paisa, o.currency, o.status AS order_status,
                    o.created_at, o.paid_at, o.gateway,
                    p.name AS product_name,
                    l.id AS license_id, l.license_key_prefix, l.license_key_encrypted,
                    CASE WHEN l.status = "active" AND l.expires_at <= NOW() THEN "expired"
                         ELSE l.status END AS license_status,
                    UNIX_TIMESTAMP(l.expires_at) AS expires_at,
                    l.activation_status, l.activated_machine_hint, l.activated_at,
                    l.max_downloads, l.downloads_used,
                    (l.license_key_encrypted IS NOT NULL) AS can_resend
               FROM orders o
               JOIN products p ON p.id = o.product_id
          LEFT JOIN licenses l ON l.order_id = o.id
              WHERE o.customer_id = :id
              ORDER BY o.created_at DESC'
        );
        $stmt->execute(['id' => $customerId]);

        $licenses = new LicenseService();
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $row['license_key'] = $licenses->decrypt($row['license_key_encrypted']);
            unset($row['license_key_encrypted']);
            $row['expires_at'] = $row['expires_at'] === null ? null : (int) $row['expires_at'];
            $rows[] = $row;
        }
        return $rows;
    }

    public function findByRef(string $orderRef): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM orders WHERE order_ref = :ref LIMIT 1');
        $stmt->execute(['ref' => $orderRef]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Marks an order paid inside the caller's transaction.
     * Returns false if it was already paid (so the webhook stays idempotent).
     */
    public function markPaid(PDO $pdo, string $orderRef, ?string $transactionId): bool
    {
        $stmt = $pdo->prepare(
            'UPDATE orders
                SET status = "paid", gateway_txn_id = :txn, paid_at = NOW()
              WHERE order_ref = :ref AND status <> "paid" AND gateway <> "trial"'
        );
        $stmt->execute(['ref' => $orderRef, 'txn' => $transactionId]);

        return $stmt->rowCount() > 0;
    }

    public function markFailed(string $orderRef, string $reason): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE orders SET status = "failed", failure_reason = :reason
              WHERE order_ref = :ref AND status = "pending"'
        );
        $stmt->execute(['ref' => $orderRef, 'reason' => mb_substr($reason, 0, 255)]);
    }

    /** @return list<array{sku:string, name:string, price_paisa:int, currency:string}> */
    public function activeProducts(): array
    {
        $rows = Database::pdo()->query(
            'SELECT sku, name, price_paisa, currency FROM products WHERE is_active = 1 ORDER BY price_paisa'
        )->fetchAll();
        return array_map(static fn (array $row): array => ['price_paisa' => (int) $row['price_paisa']] + $row, $rows);
    }

    public function product(string $sku): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM products WHERE sku = :sku AND is_active = 1 LIMIT 1'
        );
        $stmt->execute(['sku' => $sku]);
        $product = $stmt->fetch();

        if (!$product) {
            throw new RuntimeException("Unknown or inactive product: {$sku}");
        }
        return $product;
    }

    /**
     * Gateway-safe reference: alphanumeric, unique, sortable by time. The
     * prefix names the source — DW checkout, AP App Store, TR trial.
     */
    public static function newReference(string $prefix, int $randomBytes = 3): string
    {
        return $prefix . gmdate('YmdHis') . strtoupper(bin2hex(random_bytes($randomBytes)));
    }
}
