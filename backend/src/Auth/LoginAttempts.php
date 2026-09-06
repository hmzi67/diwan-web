<?php
declare(strict_types=1);

namespace Diwan\Auth;

use Diwan\Config\Env;
use Diwan\Database\Database;

/**
 * Rolling-window rate limiting for password sign-in.
 *
 * Same shape as DownloadService's download_attempts and activate-license.php's
 * license_activation_attempts: record every attempt, count recent ones, refuse
 * past a threshold. Nothing is ever deleted here — old rows are just outside
 * the window.
 *
 * Two windows, because they stop different attacks:
 *   - per IP    — one host working through many accounts
 *   - per email — many hosts working on one account (the per-IP limit alone
 *                 would never trigger for a distributed attempt)
 *
 * Emails are stored hashed. In clear, this table would be a standing log of
 * which addresses tried to sign in and when — useful to an attacker who gets
 * read access, and not something the feature needs.
 */
final class LoginAttempts
{
    private const WINDOW_MINUTES     = 15;
    private const MAX_PER_IP         = 10;
    private const MAX_PER_EMAIL      = 5;

    public static function isRateLimited(string $ip, string $email): bool
    {
        // Both windows in a single round trip. Two separate COUNT queries read
        // more clearly, but this sits in the sign-in path on shared hosting,
        // where every avoidable round trip is worth removing.
        $stmt = Database::pdo()->prepare(
            'SELECT
               SUM(ip = :ip)                 AS by_ip,
               SUM(email_hash = :hash)       AS by_email
             FROM login_attempts
            WHERE result IN ("bad_password", "unknown_email")
              AND attempted_at > DATE_SUB(NOW(), INTERVAL :mins MINUTE)
              AND (ip = :ip2 OR email_hash = :hash2)'
        );
        $stmt->execute([
            'ip'    => $ip,
            'ip2'   => $ip,
            'hash'  => self::hashEmail($email),
            'hash2' => self::hashEmail($email),
            'mins'  => self::WINDOW_MINUTES,
        ]);
        $row = $stmt->fetch();

        return (int) ($row['by_ip'] ?? 0) >= self::MAX_PER_IP
            || (int) ($row['by_email'] ?? 0) >= self::MAX_PER_EMAIL;
    }

    public static function record(string $ip, string $email, string $result): void
    {
        Database::pdo()->prepare(
            'INSERT INTO login_attempts (ip, email_hash, result, attempted_at)
             VALUES (:ip, :hash, :result, NOW())'
        )->execute([
            'ip'     => $ip,
            'hash'   => self::hashEmail($email),
            'result' => $result,
        ]);
    }

    /** Clears the per-email window after a genuine sign-in. */
    public static function clearFor(string $email): void
    {
        Database::pdo()->prepare(
            'DELETE FROM login_attempts
              WHERE email_hash = :hash AND result IN ("bad_password", "unknown_email")'
        )->execute(['hash' => self::hashEmail($email)]);
    }

    public static function windowMinutes(): int
    {
        return self::WINDOW_MINUTES;
    }

    private static function hashEmail(string $email): string
    {
        return hash_hmac('sha256', strtolower(trim($email)), Env::require('APP_KEY'));
    }
}
