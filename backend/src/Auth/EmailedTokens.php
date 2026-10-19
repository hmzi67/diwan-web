<?php
declare(strict_types=1);

namespace Diwan\Auth;

use Diwan\Support\Crypto;
use Diwan\Support\Logger;
use InvalidArgumentException;
use PDO;

/**
 * Single-use links sent by email: signup verification and password reset.
 *
 * Both tables have the same shape (token_hash, customer_id, issued_ip,
 * expires_at, used_at, created_at) and the same rules: only the HMAC of a
 * token is stored, issuance is rate limited per IP and per customer, and a
 * redeemed link takes every other open link for that customer with it.
 *
 * Every time comparison happens in SQL against NOW(): PHP and MySQL may run
 * in different timezones (PHP is pinned to Asia/Karachi in bootstrap.php,
 * MySQL follows its server), and mixing the two clocks shifts every expiry.
 */
final class EmailedTokens
{
    private const TABLES = ['email_verification_tokens', 'password_reset_tokens'];

    /**
     * @param string $table one of TABLES — interpolated into SQL, so never user input
     * @param string $label names the flow in log lines ("Password reset")
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table,
        private readonly string $label,
    ) {
        if (!in_array($table, self::TABLES, true)) {
            throw new InvalidArgumentException("Not an emailed-token table: {$table}");
        }
    }

    /** Expired rows are never consulted again; nothing else cleans them up. */
    public function prune(): void
    {
        $this->pdo->exec("DELETE FROM {$this->table} WHERE expires_at < NOW()");
    }

    public function issuedFromIpLastHour(string $ip): int
    {
        return $this->countLastHour('issued_ip', $ip);
    }

    public function issuedToCustomerLastHour(int $customerId): int
    {
        return $this->countLastHour('customer_id', $customerId);
    }

    /** Stores the token's HMAC and returns the raw token for the emailed link. */
    public function issue(int $customerId, string $ip, int $ttlSeconds): string
    {
        $token = Crypto::newToken();
        $this->pdo->prepare(
            "INSERT INTO {$this->table} (token_hash, customer_id, issued_ip, expires_at, created_at)
             VALUES (:hash, :id, :ip, DATE_ADD(NOW(), INTERVAL :ttl SECOND), NOW())"
        )->execute([
            'hash' => Crypto::hmac($token),
            'id'   => $customerId,
            'ip'   => $ip,
            'ttl'  => $ttlSeconds,
        ]);
        return $token;
    }

    /**
     * Must run inside the caller's transaction. Returns the customer id and
     * marks every open link for that customer used — or null for a token that
     * is unknown, already used or expired.
     */
    public function redeem(string $rawToken, string $ip): ?int
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, customer_id, used_at, (expires_at <= NOW()) AS expired FROM {$this->table}
              WHERE token_hash = :hash LIMIT 1 FOR UPDATE"
        );
        $stmt->execute(['hash' => Crypto::hmac($rawToken)]);
        $token = $stmt->fetch();

        $problem = match (true) {
            !$token                                          => 'not found',
            $token['used_at'] !== null                       => 'already used',
            (bool) $token['expired']                         => 'expired',
            default                                          => null,
        };
        if ($problem !== null) {
            Logger::warning("{$this->label} link redeemed: {$problem}", ['ip' => $ip]);
            return null;
        }

        $customerId = (int) $token['customer_id'];

        // The redeemed link and any other copy still outstanding die together —
        // otherwise a stale or stolen link keeps working after the first click.
        $this->pdo->prepare(
            "UPDATE {$this->table} SET used_at = NOW() WHERE customer_id = :id AND used_at IS NULL"
        )->execute(['id' => $customerId]);

        return $customerId;
    }

    private function countLastHour(string $column, string|int $value): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM {$this->table}
              WHERE {$column} = :value AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        $stmt->execute(['value' => $value]);
        return (int) $stmt->fetchColumn();
    }
}
