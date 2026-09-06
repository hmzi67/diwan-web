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

        // Licence lifetime is its OWN setting, deliberately unrelated to how
        // long a download link stays valid (DownloadService::TOKEN_TTL_MINUTES,
        // configurable via DOWNLOAD_LINK_TTL_MINUTES).
        //
        // 0 — the default — means the licence never expires, which is what the
        // "one-time licence, no subscription" offer on the pricing page
        // actually promises. Set a positive number only if you deliberately
        // start selling time-limited licences.
        //
        // This previously read DOWNLOAD_TOKEN_TTL_DAYS, which made every
        // licence expire 30 days after purchase. See migration 005, which
        // repairs licences issued while that was live.
        $validityDays = Env::int('LICENSE_VALIDITY_DAYS', 0);

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
            'max'          => Env::int('DOWNLOAD_MAX_ATTEMPTS', 5),
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

    /**
     * Reversible copy of the key, for the dashboard's "resend my key" only.
     * Never used for lookup/authentication — hash() remains the sole source
     * of truth there. See migration 002 for the trade-off this accepts.
     */
    private function encrypt(string $key): string
    {
        $encKey = hash('sha256', Env::require('APP_KEY'), true);
        $nonce  = random_bytes(12);
        $tag    = '';
        $cipher = openssl_encrypt($key, 'aes-256-gcm', $encKey, OPENSSL_RAW_DATA, $nonce, $tag);
        return base64_encode($nonce . $tag . $cipher);
    }

    /** Inverse of encrypt(). Returns null for anything malformed or pre-migration-002 (NULL). */
    public function decrypt(?string $encoded): ?string
    {
        if ($encoded === null || $encoded === '') {
            return null;
        }
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 12 + 16) {
            return null;
        }
        $nonce  = substr($raw, 0, 12);
        $tag    = substr($raw, 12, 16);
        $cipher = substr($raw, 28);

        $encKey = hash('sha256', Env::require('APP_KEY'), true);
        $plain  = openssl_decrypt($cipher, 'aes-256-gcm', $encKey, OPENSSL_RAW_DATA, $nonce, $tag);
        return $plain === false ? null : $plain;
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
