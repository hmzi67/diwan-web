<?php
declare(strict_types=1);

namespace Diwan\Trial;

use Diwan\Config\Env;
use Diwan\License\LicenseService;
use Diwan\Order\OrderService;
use PDO;
use RuntimeException;

/**
 * TEMPORARY no-payment onboarding. Disable TRIAL_SIGNUP_ENABLED to stop new
 * grants; keep historical orders/grants when introducing paid upgrades.
 * Call inside the account creation or authenticated trial-start transaction.
 */
final class TrialService
{
    /** Trial length. Never read from LICENSE_VALIDITY_DAYS, which is the paid setting. */
    public const DAYS = 30;

    /** Inactive for checkout; only this service issues it. */
    public const SKU = 'diwan-pos-trial';

    public function ensureForCustomer(PDO $pdo, int $customerId): array
    {
        if (!$pdo->inTransaction()) {
            throw new RuntimeException('Trial issuance requires a transaction.');
        }

        // Serialize requests for the same account, including simultaneous tabs.
        $customer = $pdo->prepare('SELECT email FROM customers WHERE id = :id FOR UPDATE');
        $customer->execute(['id' => $customerId]);
        $email = $customer->fetchColumn();
        if ($email === false) {
            throw new RuntimeException('Customer does not exist.');
        }
        $email = strtolower(trim((string) $email));

        $paid = $pdo->prepare(
            'SELECT l.id FROM licenses l JOIN orders o ON o.id = l.order_id
              WHERE o.customer_id = :customer AND o.gateway <> "trial" AND o.status = "paid"
                AND l.status = "active" AND (l.expires_at IS NULL OR l.expires_at > NOW())
              LIMIT 1'
        );
        $paid->execute(['customer' => $customerId]);
        if ($paid->fetchColumn() !== false) {
            return ['status' => 'paid_license'];
        }

        // Never filter by expiry/status: expired and revoked trials still count.
        $grant = $pdo->prepare(
            'SELECT order_id FROM trial_grants
              WHERE customer_id = :customer OR normalized_email = :email LIMIT 1'
        );
        $grant->execute(['customer' => $customerId, 'email' => $email]);
        if ($grant->fetchColumn() !== false) {
            return ['status' => 'existing_trial'];
        }

        if (!Env::bool('TRIAL_SIGNUP_ENABLED', true)) {
            return ['status' => 'disabled'];
        }

        // This SKU is deliberately inactive for checkout. Only this service
        // issues it, at zero cost, without a payment transaction or paid_at.
        $product = $pdo->prepare('SELECT id FROM products WHERE sku = :sku');
        $product->execute(['sku' => self::SKU]);
        $product = $product->fetchColumn();
        if ($product === false) {
            throw new RuntimeException('Trial product is not configured. Apply migration 007.');
        }
        $pdo->prepare(
            'INSERT INTO orders (order_ref, product_id, customer_id, customer_email, customer_phone,
                                 amount_paisa, currency, gateway, status, created_at)
             VALUES (:ref, :product, :customer, :email, "", 0, "PKR", "trial", "fulfilled", NOW())'
        )->execute([
            'ref' => OrderService::newReference('TR', 6),
            'product' => $product,
            'customer' => $customerId,
            'email' => $email,
        ]);
        $orderId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO trial_grants (customer_id, normalized_email, order_id, issued_at)
             VALUES (:customer, :email, :order_id, NOW())'
        )->execute(['customer' => $customerId, 'email' => $email, 'order_id' => $orderId]);

        (new LicenseService())->issueTrialForOrder($pdo, $orderId, $email);
        return ['status' => 'issued'];
    }
}
