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
