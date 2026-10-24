<?php
declare(strict_types=1);

namespace Diwan\License;

use Diwan\Config\Env;
use Diwan\Database\Database;
use Diwan\Support\Crypto;
use Diwan\Support\Logger;
use Diwan\Support\Mailer;
use Diwan\Trial\TrialService;
use PDO;
use RuntimeException;

final class LicenseService
{
    private const PREFIX = 'DIWAN';

    /** Paid issuance only; the paid lifetime remains perpetual by default. */
    public function issueForOrder(PDO $pdo, int $orderId, string $email): array
    {
        $this->requireOrderKind($pdo, $orderId, 'paid');
        return $this->issue($pdo, $orderId, $email, Env::int('LICENSE_VALIDITY_DAYS', 0));
    }

    /**
     * Admin-approved plan licence (LicenseRequestService::approve). The order
     * is the zero-gateway `admin` order created for the request; the plan,
     * the request, the approving administrator and the device limit are
     * recorded on the licence itself.
     */
    public function issueForPlan(
        PDO $pdo,
        int $orderId,
        string $email,
        int $planId,
        int $requestId,
        int $adminId,
        int $validityDays,
        int $maxDevices
    ): array {
        $this->requireOrderKind($pdo, $orderId, 'admin');
        return $this->issue($pdo, $orderId, $email, $validityDays, [
            'plan_id'            => $planId,
            'license_request_id' => $requestId,
            'issued_by'          => $adminId,
            'max_devices'        => max(1, $maxDevices),
        ]);
    }

    /** TEMPORARY no-payment path. Trial duration never reads the paid setting. */
    public function issueTrialForOrder(PDO $pdo, int $orderId, string $email): array
    {
        $this->requireOrderKind($pdo, $orderId, 'trial');
        return $this->issue($pdo, $orderId, $email, TrialService::DAYS);
    }

    /** $kind: 'paid' (a verified payment), 'trial' or 'admin' (an approved request). */
    private function requireOrderKind(PDO $pdo, int $orderId, string $kind): void
    {
        $stmt = $pdo->prepare('SELECT gateway, status FROM orders WHERE id = :id');
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch();
        $eligible = $order !== false && match ($kind) {
            'paid'  => !in_array($order['gateway'], ['trial', 'admin'], true) && $order['status'] === 'paid',
            'trial' => $order['gateway'] === 'trial' && $order['status'] === 'fulfilled',
            'admin' => $order['gateway'] === 'admin' && $order['status'] === 'fulfilled',
        };
        if (!$eligible) {
            throw new RuntimeException('Order is not eligible for this licence issuance path.');
        }
    }

    /** Shared key storage only: trial eligibility and payments stay separate. */
    /** $extra: additional licences columns (plan-issued licences only). */
    private function issue(PDO $pdo, int $orderId, string $email, int $validityDays, array $extra = []): array
    {
        $existing = $pdo->prepare('SELECT id FROM licenses WHERE order_id = :order LIMIT 1');
        $existing->execute(['order' => $orderId]);
        if ($existing->fetch()) {
            Logger::debug('Licence already exists for order, skipping', ['order_id' => $orderId]);
            return ['license_key' => null, 'already_issued' => true];
        }

        $key = $this->generateKey();

        // customer_id is copied from the order rather than looked up by email:
        // checkout.php sets it from the authenticated session, so it is already
        // the right answer and cannot drift from the order it belongs to.
        //
        // Migration 001 added this column and backfilled existing rows, but
        // nothing ever populated it for NEW licences — so every licence issued
        // between then and now has customer_id NULL, and any query joining
        // licences by owner silently misses them.
        // NULL expires_at means "never expires" — every read path already
        // spells this as `expires_at IS NULL OR expires_at > NOW()`, so a
        // perpetual licence needs no other change.
        $expiresSql = $validityDays > 0
            ? 'DATE_ADD(NOW(), INTERVAL :validity_days DAY)'
            : 'NULL';

        $extraColumns = implode('', array_map(static fn (string $c): string => ", {$c}", array_keys($extra)));
        $extraValues  = implode('', array_map(static fn (string $c): string => ", :{$c}", array_keys($extra)));
        $stmt = $pdo->prepare(
            'INSERT INTO licenses (order_id, customer_id, license_key_hash, license_key_encrypted,
                                   license_key_prefix, customer_email, status, max_downloads,
                                   downloads_used, expires_at, created_at' . $extraColumns . ')
             SELECT :order, o.customer_id, :hash, :enc, :prefix, :email, "active", :max, 0,
                    ' . $expiresSql . ', NOW()' . $extraValues . '
               FROM orders o WHERE o.id = :order_lookup'
        );

        $params = [
            'order'        => $orderId,
            'order_lookup' => $orderId,
            'hash'         => $this->hash($key),
            'enc'          => $this->encrypt($key),
            'prefix'       => substr($key, 0, 11),
            'email'        => $email,
            'max'          => Env::int('DOWNLOAD_MAX_ATTEMPTS', 20),
        ];
        if ($validityDays > 0) {
            $params['validity_days'] = $validityDays;
        }
        $stmt->execute($params + $extra);
        $licenseId = (int) $pdo->lastInsertId();

        Logger::info('Licence issued', ['order_id' => $orderId, 'prefix' => substr($key, 0, 11)]);

        return ['license_key' => $key, 'already_issued' => false, 'license_id' => $licenseId];
    }

    /** Looks up an active, unexpired licence by the key the customer pasted. */
    public function findActive(string $key): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT l.* FROM licenses l
          LEFT JOIN customers c ON c.id = l.customer_id
              WHERE l.license_key_hash = :hash
                AND l.status = "active"
                AND (l.expires_at IS NULL OR l.expires_at > NOW())
                AND (c.status IS NULL OR c.status = "active")
              LIMIT 1'
        );
        $stmt->execute(['hash' => $this->hash($this->normalize($key))]);

        return $stmt->fetch() ?: null;
    }

    /**
     * One of the customer's own licences, matched through its order, or null
     * when it does not exist or belongs to someone else.
     */
    public function findOwned(int $licenseId, int $customerId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT l.id, l.license_key_encrypted, l.customer_email
               FROM licenses l
               JOIN orders o ON o.id = l.order_id
              WHERE l.id = :license AND o.customer_id = :customer AND l.status <> "revoked"
              LIMIT 1'
        );
        $stmt->execute(['license' => $licenseId, 'customer' => $customerId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Emails a licence key with the download link. $resend switches the
     * opening line; the rest of the message is the same on purpose, so a
     * customer who asks for it again gets exactly what they got the first time.
     */
    public function sendKeyEmail(string $email, string $key, bool $resend = false): bool
    {
        return Mailer::send(
            $email,
            'Your Diwan POS licence key',
            ($resend ? "Here is your licence key again, as requested:\n\n" : "Thank you for your purchase.\n\n")
            . "Licence key: {$key}\n\n"
            . 'Download your installer: ' . Env::appUrl('/#download') . "\n\n"
            . "Keep this key safe — it is your proof of purchase.\n"
        );
    }

    public function hasDownloadsLeft(array $license): bool
    {
        return (int) $license['downloads_used'] < (int) $license['max_downloads'];
    }

    /**
     * Device activation, up to the licence's `max_devices` (1 unless the plan
     * or an administrator allows more). `activation_status` is separate from
     * the entitlement `status` used by findActive()/downloads: a licence can
     * be fully entitled and still "unused" the first time this runs.
     *
     * Locks the licence row (SELECT ... FOR UPDATE) so two activations racing
     * for the last free slot cannot both take it.
     *
     * Returns one of:
     *   invalid_key                 — key doesn't exist, is revoked/suspended/
     *                                 expired, or the account is suspended
     *   activated                   — this device now holds a slot
     *   already_active_same_device  — this device already held one (no-op)
     *   device_mismatch             — every slot is taken by other devices
     *                                 (with `max_devices`)
     */
    public function activate(string $rawKey, string $fingerprintHash, ?string $machineHint): array
    {
        $hash = $this->hash($this->normalize($rawKey));

        return Database::transaction(function (PDO $pdo) use ($hash, $fingerprintHash, $machineHint) {
            $stmt = $pdo->prepare(
                'SELECT l.id, l.max_devices,
                        (l.status = "active" AND (l.expires_at IS NULL OR l.expires_at > NOW())
                         AND (c.status IS NULL OR c.status = "active")) AS entitled
                   FROM licenses l
              LEFT JOIN customers c ON c.id = l.customer_id
                  WHERE l.license_key_hash = :hash
                  LIMIT 1 FOR UPDATE'
            );
            $stmt->execute(['hash' => $hash]);
            $license = $stmt->fetch();

            // Same response for "doesn't exist" and "not entitled": a revoked
            // or suspended key must not be distinguishable from a wrong one.
            if (!$license || !(bool) $license['entitled']) {
                return ['result' => 'invalid_key'];
            }
            $licenseId = (int) $license['id'];

            if ($this->hasActiveDevice($pdo, $licenseId, $fingerprintHash)) {
                $pdo->prepare(
                    'UPDATE license_activations SET last_seen_at = NOW()
                      WHERE license_id = :id AND machine_hash = :fp'
                )->execute(['id' => $licenseId, 'fp' => $fingerprintHash]);
                return ['result' => 'already_active_same_device', 'license_id' => $licenseId];
            }

            $maxDevices = max(1, (int) $license['max_devices']);
            if ($this->activeDeviceCount($pdo, $licenseId) >= $maxDevices) {
                return ['result' => 'device_mismatch', 'max_devices' => $maxDevices];
            }

            $this->bindDevice($pdo, $licenseId, $fingerprintHash, $machineHint);
            return ['result' => 'activated', 'license_id' => $licenseId];
        });
    }

    /** Whether this device currently holds one of the licence's slots. */
    public function hasActiveDevice(PDO $pdo, int $licenseId, string $fingerprintHash): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM license_activations
              WHERE license_id = :id AND machine_hash = :fp AND deactivated_at IS NULL'
        );
        $stmt->execute(['id' => $licenseId, 'fp' => $fingerprintHash]);
        return $stmt->fetchColumn() !== false;
    }

    public function activeDeviceCount(PDO $pdo, int $licenseId): int
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM license_activations WHERE license_id = :id AND deactivated_at IS NULL'
        );
        $stmt->execute(['id' => $licenseId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Gives this device a slot (re-using its old row if an administrator had
     * freed it). The licence's own activated_machine_* columns keep the first
     * binding for older readers. The caller holds the licence row lock and
     * has already checked the device limit.
     */
    public function bindDevice(PDO $pdo, int $licenseId, string $fingerprintHash, ?string $machineHint): void
    {
        $pdo->prepare(
            'INSERT INTO license_activations (license_id, machine_hash, machine_hint, activated_at, last_seen_at)
             VALUES (:id, :fp, :hint, NOW(), NOW())
             ON DUPLICATE KEY UPDATE deactivated_at = NULL, activated_at = NOW(), last_seen_at = NOW(),
                                     machine_hint = COALESCE(VALUES(machine_hint), machine_hint)'
        )->execute(['id' => $licenseId, 'fp' => $fingerprintHash, 'hint' => $machineHint]);
        $pdo->prepare(
            'UPDATE licenses
                SET activation_status = "activated",
                    activated_machine_hash = COALESCE(activated_machine_hash, :fp),
                    activated_machine_hint = COALESCE(activated_machine_hint, :hint),
                    activated_at = COALESCE(activated_at, NOW())
              WHERE id = :id'
        )->execute(['fp' => $fingerprintHash, 'hint' => $machineHint, 'id' => $licenseId]);
    }

    /**
     * Current entitlement of a licence, for the device it is bound to.
     *
     * Backs license-status.php. Unlike activate(), this is read-only and is
     * only willing to describe a licence to the machine that activated it —
     * anyone else (wrong key, wrong device, key never activated) gets the
     * same `unknown` answer, so the endpoint cannot be used to probe which
     * keys exist or which have been revoked.
     *
     * Returns one of:
     *   unknown        — no such key, or bound to a different device
     *   not_activated  — key exists but has never been activated (the app
     *                    must call activate-license.php first)
     *   ok             — with `status` (active|revoked|expired) and
     *                    `expires_at` (unix timestamp, or null = perpetual)
     *
     * `status` folds the row's own `status` together with `expires_at`: a
     * row that is still marked "active" but whose expiry has passed reports
     * "expired", so the app never has to reason about the row's two axes.
     * `expires_at` is converted with MySQL's own UNIX_TIMESTAMP() so it is
     * measured on the same clock as the `> NOW()` comparisons everywhere
     * else in this class, rather than re-parsed under PHP's timezone.
     */
    public function statusForDevice(string $rawKey, string $fingerprintHash): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT l.id, l.status, l.plan_id, l.activation_status, a.id AS activation_id,
                    c.status AS customer_status,
                    UNIX_TIMESTAMP(l.expires_at) AS expires_ts,
                    (l.expires_at IS NOT NULL AND l.expires_at <= NOW()) AS past_expiry
               FROM licenses l
          LEFT JOIN customers c ON c.id = l.customer_id
          LEFT JOIN license_activations a
                 ON a.license_id = l.id AND a.machine_hash = :fp AND a.deactivated_at IS NULL
              WHERE l.license_key_hash = :hash
              LIMIT 1'
        );
        $stmt->execute(['hash' => $this->hash($this->normalize($rawKey)), 'fp' => $fingerprintHash]);
        $license = $stmt->fetch();

        if (!$license) {
            return ['result' => 'unknown'];
        }
        if ($license['activation_status'] !== 'activated') {
            return ['result' => 'not_activated'];
        }
        // Not one of this licence's devices (or an administrator freed its
        // slot): the same answer as a wrong key.
        if ($license['activation_id'] === null) {
            return ['result' => 'unknown'];
        }

        Database::pdo()->prepare('UPDATE license_activations SET last_seen_at = NOW() WHERE id = :id')
            ->execute(['id' => $license['activation_id']]);

        $status = $license['status'];
        if ($status === 'active' && (bool) $license['past_expiry']) {
            $status = 'expired';
        }
        // A suspended account suspends every licence it owns, without
        // touching the licences themselves — reactivating restores them.
        if ($license['customer_status'] === 'suspended' && in_array($status, ['active', 'expired'], true)) {
            $status = 'suspended';
        }

        return [
            'result'     => 'ok',
            'license_id' => (int) $license['id'],
            'plan_id'    => $license['plan_id'] === null ? null : (int) $license['plan_id'],
            'status'     => $status,
            'expires_at' => $license['expires_ts'] === null ? null : (int) $license['expires_ts'],
        ];
    }

    /**
     * Looks up an activated licence row for a given key and device fingerprint.
     * Used by POS cloud sync to authenticate the terminal and associate its
     * sales records with the customer account that owns the licence.
     *
     * @return array{id: int, customer_id: int|null, customer_email: string, status: string}|null
     */
    public function findLicenseForDevice(string $rawKey, string $fingerprintHash): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT l.id, l.customer_id, l.customer_email, l.status
               FROM licenses l
               JOIN license_activations a
                 ON a.license_id = l.id AND a.machine_hash = :fp AND a.deactivated_at IS NULL
          LEFT JOIN customers c ON c.id = l.customer_id
              WHERE l.license_key_hash = :hash
                AND l.status = "active"
                AND (l.expires_at IS NULL OR l.expires_at > NOW())
                AND (c.status IS NULL OR c.status = "active")
              LIMIT 1'
        );
        $stmt->execute(['hash' => $this->hash($this->normalize($rawKey)), 'fp' => $fingerprintHash]);
        $license = $stmt->fetch();
        if (!$license) {
            return null;
        }

        return [
            'id'             => (int) $license['id'],
            'customer_id'    => $license['customer_id'] !== null ? (int) $license['customer_id'] : null,
            'customer_email' => (string) $license['customer_email'],
            'status'         => (string) $license['status'],
        ];
    }

    /**
     * A fresh key for an existing licence (an administrator rotating a leaked
     * key). The old key stops working at once; the licence keeps its plan,
     * expiry and device slots, so a device that enters the new key
     * re-activates into the slot it already held. Returns the new key — the
     * only time it is in plaintext outside the owner's dashboard.
     */
    public function rotateKey(PDO $pdo, int $licenseId): string
    {
        $key = $this->generateKey();
        $pdo->prepare(
            'UPDATE licenses SET license_key_hash = :hash, license_key_encrypted = :enc, license_key_prefix = :prefix
              WHERE id = :id'
        )->execute([
            'hash'   => $this->hash($key),
            'enc'    => $this->encrypt($key),
            'prefix' => substr($key, 0, 11),
            'id'     => $licenseId,
        ]);
        return $key;
    }

    /** The value a licence is looked up by (`license_key_hash`) for a key as typed. */
    public function lookupHash(string $rawKey): string
    {
        return $this->hash($this->normalize($rawKey));
    }

    /** The canonical form of a key as the app must store and resend it. */
    public function normalizeKey(string $key): string
    {
        return $this->normalize($key);
    }

    private function hash(string $key): string
    {
        return Crypto::hmac($key);
    }

    /**
     * Reversible key for the authenticated owner's dashboard and email recovery.
     * Never used for lookup/authentication — hash() remains the sole source
     * of truth there. See migration 002 for the trade-off this accepts.
     */
    private function encrypt(string $key): string
    {
        return Crypto::seal($key, $this->encryptionKey());
    }

    /** Inverse of encrypt(). Returns null for anything malformed or pre-migration-002 (NULL). */
    public function decrypt(?string $encoded): ?string
    {
        return Crypto::open($encoded, $this->encryptionKey());
    }

    private function encryptionKey(): string
    {
        return hash('sha256', Env::require('APP_KEY'), true);
    }

    private function normalize(string $key): string
    {
        return strtoupper(trim($key));
    }

    private function generateKey(): string
    {
        $groups = [];
        for ($i = 0; $i < 4; $i++) {
            $groups[] = strtoupper(bin2hex(random_bytes(2)));
        }
        return self::PREFIX . '-' . implode('-', $groups);
    }
}
