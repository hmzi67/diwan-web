<?php
declare(strict_types=1);

namespace Diwan\Order;

use Diwan\Database\Database;
use Diwan\Support\Logger;
use PDO;
use RuntimeException;

final class OrderService
{
    /** Creates a pending order and returns it. Amounts are stored in paisa. */
    public function createPending(string $sku, string $email, string $phone, string $gateway): array
    {
        $pdo = Database::pdo();

        $product = $this->product($sku);
        $orderRef = $this->generateReference();

        $stmt = $pdo->prepare(
            'INSERT INTO orders (order_ref, product_id, customer_email, customer_phone,
                                 amount_paisa, currency, gateway, status, created_at)
             VALUES (:ref, :product, :email, :phone, :amount, :currency, :gateway, "pending", NOW())'
        );
        $stmt->execute([
            'ref'      => $orderRef,
            'product'  => $product['id'],
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
              WHERE order_ref = :ref AND status <> "paid"'
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

    /** Gateway-safe reference: alphanumeric, unique, sortable by time. */
    private function generateReference(): string
    {
        return 'DW' . gmdate('YmdHis') . strtoupper(bin2hex(random_bytes(3)));
    }
}
