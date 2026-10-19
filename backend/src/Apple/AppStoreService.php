<?php
declare(strict_types=1);

namespace Diwan\Apple;

use Diwan\Database\Database;
use Diwan\License\LicenseService;
use Diwan\Order\OrderService;
use Diwan\Support\Logger;
use Diwan\Support\UserFacingException;
use PDO;
use PDOException;
use RuntimeException;

/**
 * App Store purchases → the same orders/licences every other purchase uses.
 *
 * A licence bought in the iOS app is a normal Diwan licence: one order
 * (gateway "apple", status "paid"), one licence key, activated on a device
 * through activate-license.php and refreshed through license-status.php
 * like any other. What's Apple-specific is only how an order comes to
 * exist — a verified StoreKit transaction instead of a gateway webhook —
 * and how it can be revoked — an App Store refund notification.
 *
 * Non-consumables keep the same `originalTransactionId` for the life of the
 * purchase (across restores and devices), so apple_transactions keys on it:
 * the first verification of a purchase creates the order and licence, and
 * every later one (restore, reinstall, re-sent after a crash) finds and
 * returns that same licence. Never a second one.
 */
final class AppStoreService
{
    /**
     * Verifies a StoreKit transaction JWS and applies our own rules to it.
     *
     * @return array{original_transaction_id:string, transaction_id:string, product_id:string, sku:string,
     *               environment:string, app_account_token:?string, revoked:bool, purchased_at:int,
     *               amount_minor:?int, currency:?string}
     */
    public function verifiedTransaction(string $jws): array
    {
        $tx = AppStoreJws::verify($jws);

        if (($tx['bundleId'] ?? null) !== AppleConfig::bundleId()) {
            throw new UserFacingException('This purchase belongs to a different app.');
        }
        $environment = (string) ($tx['environment'] ?? '');
        if ($environment === 'Sandbox' && !AppleConfig::allowSandbox()) {
            throw new UserFacingException('Sandbox purchases are not accepted.');
        }
        if ($environment !== 'Production' && $environment !== 'Sandbox') {
            // "Xcode"/"LocalTesting" transactions are signed by a local
            // StoreKit configuration, never by Apple — AppStoreJws already
            // refuses their chain; this is the explicit backstop.
            throw new UserFacingException('Only App Store and sandbox purchases are accepted.');
        }
        if (($tx['type'] ?? null) !== 'Non-Consumable') {
            throw new UserFacingException('Unsupported purchase type.');
        }
        if (($tx['inAppOwnershipType'] ?? 'PURCHASED') === 'FAMILY_SHARED') {
            // A licence binds to one device; Family Sharing would let one
            // purchase move between households. Turn it off for these
            // products in App Store Connect — this refuses it regardless.
            throw new UserFacingException('Licences shared through Family Sharing cannot be activated. Please buy your own licence.');
        }
        $productId = (string) ($tx['productId'] ?? '');
        $sku       = AppleConfig::skuFor($productId);
        if ($sku === null) {
            throw new UserFacingException('Unknown App Store product.');
        }

        // `price` is in milliunits of `currency` (8000 PKR = 8000000);
        // our money columns are minor units (paisa/cents).
        $amount = isset($tx['price']) ? intdiv((int) $tx['price'], 10) : null;

        return [
            'original_transaction_id' => (string) ($tx['originalTransactionId'] ?? ''),
            'transaction_id'          => (string) ($tx['transactionId'] ?? ''),
            'product_id'              => $productId,
            'sku'                     => $sku,
            'environment'             => $environment,
            'app_account_token'       => isset($tx['appAccountToken']) ? strtolower((string) $tx['appAccountToken']) : null,
            'revoked'                 => isset($tx['revocationDate']),
            'purchased_at'            => intdiv((int) ($tx['purchaseDate'] ?? time() * 1000), 1000),
            'amount_minor'            => $amount !== null && $amount >= 0 ? $amount : null,
            'currency'                => isset($tx['currency']) ? strtoupper(substr((string) $tx['currency'], 0, 3)) : null,
        ];
    }

    /**
     * Finds or creates the order + licence for a verified transaction.
     * $customerId (a signed-in app user) attaches an ownerless purchase to
     * that account; it never re-assigns one that already has an owner.
     *
     * @return array{license_id:int, license_key:string, created:bool}
     */
    public function recordPurchase(array $tx, ?int $customerId): array
    {
        if ($tx['original_transaction_id'] === '') {
            throw new RuntimeException('Transaction has no original transaction id.');
        }
        if ($customerId === null && $tx['app_account_token'] !== null) {
            $customerId = (new AppleAccountService())->customerIdForAppAccountToken($tx['app_account_token']);
        }

        // Two verifications of one brand-new purchase can race (the purchase
        // call and the launch-time sweep of unfinished transactions). Both
        // miss the row, both insert; the loser hits the primary key or a
        // deadlock, and simply re-runs to find the winner's licence.
        for ($attempt = 0; ; $attempt++) {
            try {
                return Database::transaction(fn (PDO $pdo): array => $this->findOrCreate($pdo, $tx, $customerId));
            } catch (PDOException $e) {
                $code = (int) ($e->errorInfo[1] ?? 0);
                if ($attempt === 0 && ($code === 1062 || $code === 1213)) {
                    continue;
                }
                throw $e;
            }
        }
    }

    private function findOrCreate(PDO $pdo, array $tx, ?int $customerId): array
    {
        $licenses = new LicenseService();

        $existing = $pdo->prepare(
            'SELECT at.order_id, o.customer_id, l.id AS license_id, l.status, l.license_key_encrypted
               FROM apple_transactions at
               JOIN orders o ON o.id = at.order_id
               JOIN licenses l ON l.order_id = at.order_id
              WHERE at.original_transaction_id = :id
              LIMIT 1 FOR UPDATE'
        );
        $existing->execute(['id' => $tx['original_transaction_id']]);
        $row = $existing->fetch();

        if ($row) {
            if ($row['status'] === 'revoked') {
                throw new UserFacingException('This purchase was refunded, so its licence is no longer active.');
            }
            if ($customerId !== null && $row['customer_id'] === null) {
                $this->attachToCustomer($pdo, (int) $row['order_id'], $customerId);
            }
            $pdo->prepare(
                'UPDATE apple_transactions SET latest_transaction_id = :tx, updated_at = NOW()
                  WHERE original_transaction_id = :id'
            )->execute(['tx' => $tx['transaction_id'], 'id' => $tx['original_transaction_id']]);

            $key = $licenses->decrypt($row['license_key_encrypted']);
            if ($key === null) {
                throw new UserFacingException('This purchase\'s licence key cannot be recovered. Please contact support.');
            }
            return ['license_id' => (int) $row['license_id'], 'license_key' => $key, 'created' => false];
        }

        if ($tx['revoked']) {
            throw new UserFacingException('This purchase was refunded, so it cannot be activated.');
        }

        $product = (new OrderService())->product($tx['sku']);
        $email   = '';
        if ($customerId !== null) {
            $stmt = $pdo->prepare('SELECT email FROM customers WHERE id = :id AND deleted_at IS NULL');
            $stmt->execute(['id' => $customerId]);
            $found = $stmt->fetchColumn();
            if ($found === false) {
                $customerId = null;
            } else {
                $email = (string) $found;
            }
        }

        $pdo->prepare(
            'INSERT INTO orders (order_ref, product_id, customer_id, customer_email, customer_phone,
                                 amount_paisa, currency, gateway, gateway_txn_id, status, created_at, paid_at)
             VALUES (:ref, :product, :customer, :email, "", :amount, :currency, "apple", :txn, "paid",
                     NOW(), FROM_UNIXTIME(:paid_at))'
        )->execute([
            'ref'      => OrderService::newReference('AP'),
            'product'  => $product['id'],
            'customer' => $customerId,
            'email'    => $email,
            'amount'   => $tx['amount_minor'] ?? (int) $product['price_paisa'],
            'currency' => $tx['currency'] ?? $product['currency'],
            'txn'      => $tx['original_transaction_id'],
            'paid_at'  => $tx['purchased_at'],
        ]);
        $orderId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO apple_transactions (original_transaction_id, order_id, product_id, environment,
                                             latest_transaction_id, app_account_token, created_at)
             VALUES (:id, :order_id, :product, :env, :tx, :aat, NOW())'
        )->execute([
            'id'       => $tx['original_transaction_id'],
            'order_id' => $orderId,
            'product'  => $tx['product_id'],
            'env'      => $tx['environment'],
            'tx'       => $tx['transaction_id'],
            'aat'      => $tx['app_account_token'],
        ]);

        $issued = $licenses->issueForOrder($pdo, $orderId, $email);
        $lookup = $pdo->prepare('SELECT id FROM licenses WHERE order_id = :o');
        $lookup->execute(['o' => $orderId]);
        $licenseId = (int) $lookup->fetchColumn();

        Logger::info('App Store purchase recorded', [
            'order_id'    => $orderId,
            'sku'         => $tx['sku'],
            'environment' => $tx['environment'],
        ]);

        return ['license_id' => $licenseId, 'license_key' => (string) $issued['license_key'], 'created' => true];
    }

    private function attachToCustomer(PDO $pdo, int $orderId, int $customerId): void
    {
        $stmt = $pdo->prepare('SELECT email FROM customers WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute(['id' => $customerId]);
        $email = $stmt->fetchColumn();
        if ($email === false) {
            return;
        }
        $pdo->prepare('UPDATE orders SET customer_id = :c, customer_email = :e WHERE id = :o AND customer_id IS NULL')
            ->execute(['c' => $customerId, 'e' => $email, 'o' => $orderId]);
        $pdo->prepare('UPDATE licenses SET customer_id = :c, customer_email = :e WHERE order_id = :o AND customer_id IS NULL')
            ->execute(['c' => $customerId, 'e' => $email, 'o' => $orderId]);
        Logger::info('App Store purchase linked to account', ['order_id' => $orderId, 'customer_id' => $customerId]);
    }

    /**
     * "Restore purchases" on a device the licence isn't bound to — a new
     * iPad, or a phone that was wiped. Apple has just proven this Apple ID
     * owns the purchase, which is exactly the proof support would ask for,
     * so the move is self-service, limited to once per cooldown so a single
     * purchase can't be passed back and forth between tills.
     *
     * @return 'unused'|'same_device'|'moved'|'throttled'
     */
    public function moveToDevice(int $licenseId, string $fingerprintHash, ?string $hint): string
    {
        return Database::transaction(function (PDO $pdo) use ($licenseId, $fingerprintHash, $hint): string {
            $stmt = $pdo->prepare(
                'SELECT activation_status, activated_machine_hash,
                        (last_reactivated_at IS NULL OR last_reactivated_at < NOW() - INTERVAL :days DAY) AS may_move
                   FROM licenses WHERE id = :id FOR UPDATE'
            );
            $stmt->execute(['id' => $licenseId, 'days' => AppleConfig::restoreMoveCooldownDays()]);
            $license = $stmt->fetch();
            if (!$license || $license['activation_status'] !== 'activated') {
                return 'unused';
            }
            if (hash_equals((string) $license['activated_machine_hash'], $fingerprintHash)) {
                return 'same_device';
            }
            if (!(bool) $license['may_move']) {
                return 'throttled';
            }
            $pdo->prepare(
                'UPDATE licenses
                    SET activated_machine_hash = :fp, activated_machine_hint = :hint, activated_at = NOW(),
                        last_reactivated_at = NOW(), reactivated_count = reactivated_count + 1
                  WHERE id = :id'
            )->execute(['fp' => $fingerprintHash, 'hint' => $hint, 'id' => $licenseId]);
            Logger::info('App Store licence moved to a new device', ['license_id' => $licenseId]);
            return 'moved';
        });
    }

    /**
     * App Store Server Notifications V2. Only what changes entitlement is
     * acted on: a refund (or Family Sharing revocation) revokes the licence
     * — the app locks on its next status refresh — and a reversed refund
     * restores it. Everything else is acknowledged and logged.
     *
     * @param array $notification verified `signedPayload` body
     * @return string what happened, for the log/response
     */
    public function applyNotification(array $notification): string
    {
        $type = (string) ($notification['notificationType'] ?? '');
        $data = is_array($notification['data'] ?? null) ? $notification['data'] : [];

        if (($data['bundleId'] ?? null) !== AppleConfig::bundleId()) {
            throw new RuntimeException('Notification is for a different app.');
        }
        $appAppleId = AppleConfig::appAppleId();
        if ($appAppleId !== null && ($data['environment'] ?? '') === 'Production'
            && (int) ($data['appAppleId'] ?? 0) !== $appAppleId) {
            throw new RuntimeException('Notification is for a different app id.');
        }

        if (!in_array($type, ['REFUND', 'REVOKE', 'REFUND_REVERSED'], true)) {
            return 'ignored:' . ($type !== '' ? $type : 'unknown');
        }
        if (!is_string($data['signedTransactionInfo'] ?? null)) {
            throw new RuntimeException('Notification has no transaction.');
        }
        $tx = AppStoreJws::verify($data['signedTransactionInfo']);
        $originalId = (string) ($tx['originalTransactionId'] ?? '');

        return Database::transaction(static function (PDO $pdo) use ($type, $originalId): string {
            $stmt = $pdo->prepare(
                'SELECT order_id FROM apple_transactions WHERE original_transaction_id = :id LIMIT 1 FOR UPDATE'
            );
            $stmt->execute(['id' => $originalId]);
            $orderId = $stmt->fetchColumn();
            if ($orderId === false) {
                return 'unknown_transaction';
            }

            if ($type === 'REFUND_REVERSED') {
                $pdo->prepare('UPDATE licenses SET status = "active" WHERE order_id = :o AND status = "revoked"')
                    ->execute(['o' => $orderId]);
                $pdo->prepare('UPDATE orders SET status = "paid" WHERE id = :o AND status = "refunded"')
                    ->execute(['o' => $orderId]);
                $pdo->prepare('UPDATE apple_transactions SET revoked_at = NULL, updated_at = NOW() WHERE order_id = :o')
                    ->execute(['o' => $orderId]);
                Logger::info('App Store refund reversed; licence reinstated', ['order_id' => (int) $orderId]);
                return 'reinstated';
            }

            $pdo->prepare('UPDATE licenses SET status = "revoked" WHERE order_id = :o')->execute(['o' => $orderId]);
            $pdo->prepare('UPDATE orders SET status = "refunded" WHERE id = :o')->execute(['o' => $orderId]);
            $pdo->prepare('UPDATE apple_transactions SET revoked_at = NOW(), updated_at = NOW() WHERE order_id = :o')
                ->execute(['o' => $orderId]);
            Logger::info('App Store purchase refunded; licence revoked', ['order_id' => (int) $orderId, 'type' => $type]);
            return 'revoked';
        });
    }
}
