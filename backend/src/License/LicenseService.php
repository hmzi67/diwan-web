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
        $this->requireOrderKind($pdo, $orderId, false);
        return $this->issue($pdo, $orderId, $email, Env::int('LICENSE_VALIDITY_DAYS', 0));
    }

    /** TEMPORARY no-payment path. Trial duration never reads the paid setting. */
    public function issueTrialForOrder(PDO $pdo, int $orderId, string $email): array
    {
        $this->requireOrderKind($pdo, $orderId, true);
        return $this->issue($pdo, $orderId, $email, TrialService::DAYS);
    }

    private function requireOrderKind(PDO $pdo, int $orderId, bool $trial): void
    {
        $stmt = $pdo->prepare('SELECT gateway, status FROM orders WHERE id = :id');
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch();
        if (!$order || ($order['gateway'] === 'trial') !== $trial
            || $order['status'] !== ($trial ? 'fulfilled' : 'paid')) {
            throw new RuntimeException('Order is not eligible for this licence issuance path.');
        }
    }

    /** Shared key storage only: trial eligibility and payments stay separate. */
    private function issue(PDO $pdo, int $orderId, string $email, int $validityDays): array
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

        $stmt = $pdo->prepare(
            'INSERT INTO licenses (order_id, customer_id, license_key_hash, license_key_encrypted,
                                   license_key_prefix, customer_email, status, max_downloads,
                                   downloads_used, expires_at, created_at)
             SELECT :order, o.customer_id, :hash, :enc, :prefix, :email, "active", :max, 0,
                    ' . $expiresSql . ', NOW()
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
        $stmt->execute($params);

        Logger::info('Licence issued', ['order_id' => $orderId, 'prefix' => substr($key, 0, 11)]);

        return ['license_key' => $key, 'already_issued' => false];
    }

    /** Looks up an active, unexpired licence by the key the customer pasted. */
    public function findActive(string $key): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM licenses
              WHERE license_key_hash = :hash
                AND status = "active"
                AND (expires_at IS NULL OR expires_at > NOW())
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
              WHERE l.id = :license AND o.customer_id = :customer
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
     * One-time device activation. `activation_status` is separate from the
     * entitlement `status` used by findActive()/downloads: a licence can be
     * fully entitled and still "unused" the first time this runs.
     *
     * Locks the row (SELECT ... FOR UPDATE) so two activation calls racing on
     * the same key cannot both observe "unused" and both win — the second
     * one always sees the first one's write.
     *
     * Returns one of:
     *   invalid_key             — key doesn't exist, or is revoked/expired
     *   activated                — first activation, now bound to this device
     *   already_active_same_device — re-activation from the bound device (no-op, success)
     *   device_mismatch          — bound to a different device
     */
    public function activate(string $rawKey, string $fingerprintHash, ?string $machineHint): array
    {
        $hash = $this->hash($this->normalize($rawKey));

        return Database::transaction(function (PDO $pdo) use ($hash, $fingerprintHash, $machineHint) {
            $stmt = $pdo->prepare(
                'SELECT id, activation_status, activated_machine_hash,
                        (status = "active" AND (expires_at IS NULL OR expires_at > NOW())) AS entitled
                   FROM licenses
                  WHERE license_key_hash = :hash
                  LIMIT 1 FOR UPDATE'
            );
            $stmt->execute(['hash' => $hash]);
            $license = $stmt->fetch();

            if (!$license) {
                return ['result' => 'invalid_key'];
            }

            if (!(bool) $license['entitled']) {
                // Same response as "doesn't exist" — a revoked/expired key
                // must not be distinguishable from a wrong one.
                return ['result' => 'invalid_key'];
            }

            if ($license['activation_status'] === 'unused') {
                $update = $pdo->prepare(
                    'UPDATE licenses
                        SET activation_status = "activated",
                            activated_machine_hash = :fp,
                            activated_machine_hint = :hint,
                            activated_at = NOW()
                      WHERE id = :id'
                );
                $update->execute([
                    'fp'   => $fingerprintHash,
                    'hint' => $machineHint,
                    'id'   => $license['id'],
                ]);

                return ['result' => 'activated', 'license_id' => (int) $license['id']];
            }

            // Already activated: same device is a no-op success (app restarts,
            // reinstalls without a machine-id change); different device is refused.
            if (hash_equals((string) $license['activated_machine_hash'], $fingerprintHash)) {
                return ['result' => 'already_active_same_device', 'license_id' => (int) $license['id']];
            }

            return ['result' => 'device_mismatch'];
        });
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
            'SELECT status, activation_status, activated_machine_hash,
                    UNIX_TIMESTAMP(expires_at) AS expires_ts,
                    (expires_at IS NOT NULL AND expires_at <= NOW()) AS past_expiry
               FROM licenses
              WHERE license_key_hash = :hash
              LIMIT 1'
        );
        $stmt->execute(['hash' => $this->hash($this->normalize($rawKey))]);
        $license = $stmt->fetch();

        if (!$license) {
            return ['result' => 'unknown'];
        }
        if ($license['activation_status'] !== 'activated') {
            return ['result' => 'not_activated'];
        }
        if (!hash_equals((string) $license['activated_machine_hash'], $fingerprintHash)) {
            return ['result' => 'unknown'];
        }

        $status = $license['status'];
        if ($status === 'active' && (bool) $license['past_expiry']) {
            $status = 'expired';
        }

        return [
            'result'     => 'ok',
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
        $stmt = \Diwan\Database\Database::pdo()->prepare(
            'SELECT id, customer_id, customer_email, status, activation_status, activated_machine_hash,
                    (expires_at IS NOT NULL AND expires_at <= NOW()) AS past_expiry
               FROM licenses
              WHERE license_key_hash = :hash
              LIMIT 1'
        );
        $stmt->execute(['hash' => $this->hash($this->normalize($rawKey))]);
        $license = $stmt->fetch();

        if (!$license) {
            return null;
        }
        if ($license['activation_status'] !== 'activated') {
            return null;
        }
        if (!hash_equals((string) $license['activated_machine_hash'], $fingerprintHash)) {
            return null;
        }
        if ($license['status'] !== 'active' || (bool) $license['past_expiry']) {
            return null;
        }

        return [
            'id'             => (int) $license['id'],
            'customer_id'    => $license['customer_id'] !== null ? (int) $license['customer_id'] : null,
            'customer_email' => (string) $license['customer_email'],
            'status'         => (string) $license['status'],
        ];
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
