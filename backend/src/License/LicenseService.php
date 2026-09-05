<?php
declare(strict_types=1);

namespace Diwan\License;

use Diwan\Config\Env;
use Diwan\Database\Database;
use Diwan\Support\Logger;
use PDO;

final class LicenseService
{
    private const PREFIX = 'DIWAN';

    /**
     * Issues exactly one licence per order.
     *
     * The plaintext key is returned once (to be emailed) and only its hash is
     * stored, so a database leak does not hand out working licences.
     */
    public function issueForOrder(PDO $pdo, int $orderId, string $email): array
    {
        $existing = $pdo->prepare('SELECT id FROM licenses WHERE order_id = :order LIMIT 1');
        $existing->execute(['order' => $orderId]);
        if ($existing->fetch()) {
            Logger::info('Licence already exists for order, skipping', ['order_id' => $orderId]);
            return ['license_key' => null, 'already_issued' => true];
        }

        $key = $this->generateKey();
        $ttl = Env::int('DOWNLOAD_TOKEN_TTL_DAYS', 30);

        $stmt = $pdo->prepare(
            'INSERT INTO licenses (order_id, license_key_hash, license_key_prefix, customer_email,
                                   status, max_downloads, downloads_used, expires_at, created_at)
             VALUES (:order, :hash, :prefix, :email, "active", :max, 0,
                     DATE_ADD(NOW(), INTERVAL :ttl DAY), NOW())'
        );
        $stmt->execute([
            'order'  => $orderId,
            'hash'   => $this->hash($key),
            'prefix' => substr($key, 0, 11),
            'email'  => $email,
            'max'    => Env::int('DOWNLOAD_MAX_ATTEMPTS', 5),
            'ttl'    => $ttl,
        ]);

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
        $stmt->execute(['hash' => $this->hash($this->normalise($key))]);

        return $stmt->fetch() ?: null;
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
        $hash = $this->hash($this->normalise($rawKey));

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

    /** HMAC, not a bare hash: a stolen DB is useless without APP_KEY. */
    private function hash(string $key): string
    {
        return hash_hmac('sha256', $key, Env::require('APP_KEY'));
    }

    private function normalise(string $key): string
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
