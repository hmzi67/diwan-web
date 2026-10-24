<?php
declare(strict_types=1);

namespace Diwan\Admin;

use Diwan\Auth\PasswordService;
use Diwan\Database\Database;
use Diwan\Support\AuditLog;
use Diwan\Support\Crypto;
use Diwan\Support\UserFacingException;
use PDO;

/**
 * Platform administrator accounts and sign-in. Administrators are a separate
 * table from customers, so no customer action (sign-up, reset, Apple) can
 * ever produce or reach an admin account. The first administrator is created
 * from the command line (scripts/create-admin.php); there is no web sign-up.
 */
final class AdminAuth
{
    public const MIN_PASSWORD_LENGTH = 12;
    private const WINDOW_MINUTES = 15;
    private const MAX_PER_IP = 10;
    private const MAX_PER_EMAIL = 5;

    /**
     * Verifies credentials and returns the admin row, or throws a generic
     * refusal. Rate-limited per IP and per address in a rolling window.
     */
    public static function signIn(string $email, string $password, string $ip): array
    {
        $email = strtolower(trim($email));
        $emailHash = Crypto::hmac('admin:' . $email);
        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM admin_login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)')->execute();

        $recent = $pdo->prepare(
            "SELECT SUM(ip = :ip) AS by_ip, SUM(email_hash = :email) AS by_email FROM admin_login_attempts
              WHERE result <> 'ok' AND attempted_at > DATE_SUB(NOW(), INTERVAL " . self::WINDOW_MINUTES . ' MINUTE)'
        );
        $recent->execute(['ip' => $ip, 'email' => $emailHash]);
        $counts = $recent->fetch();
        if ((int) $counts['by_ip'] >= self::MAX_PER_IP || (int) $counts['by_email'] >= self::MAX_PER_EMAIL) {
            self::record($ip, $emailHash, 'rate_limited');
            throw new UserFacingException('Too many sign-in attempts. Please wait ' . self::WINDOW_MINUTES . ' minutes.', 429);
        }

        $stmt = $pdo->prepare('SELECT * FROM admin_users WHERE email = :email AND is_active = 1');
        $stmt->execute(['email' => $email]);
        $admin = $stmt->fetch();
        if (!$admin) {
            PasswordService::burnTime();
            self::record($ip, $emailHash, 'failed');
            throw new UserFacingException('Email or password is incorrect.', 401);
        }
        if (!PasswordService::verify($password, (string) $admin['password_hash'])) {
            self::record($ip, $emailHash, 'failed');
            AuditLog::record('admin', (int) $admin['id'], $email, 'admin.sign_in_failed');
            throw new UserFacingException('Email or password is incorrect.', 401);
        }
        if (PasswordService::needsRehash((string) $admin['password_hash'])) {
            $pdo->prepare('UPDATE admin_users SET password_hash = :hash WHERE id = :id')
                ->execute(['hash' => PasswordService::hash($password), 'id' => $admin['id']]);
        }
        $pdo->prepare('UPDATE admin_users SET last_login_at = NOW() WHERE id = :id')->execute(['id' => $admin['id']]);
        self::record($ip, $emailHash, 'ok');
        AuditLog::record('admin', (int) $admin['id'], $email, 'admin.sign_in');
        return $admin;
    }

    /**
     * Creates (or, with $replace, resets the password of) an administrator.
     * CLI only — see scripts/create-admin.php. Returns the admin id.
     */
    public static function createOrReset(string $email, string $name, string $password, bool $replace): int
    {
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new UserFacingException('That is not a valid email address.');
        }
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new UserFacingException('Admin passwords must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.');
        }
        $name = trim($name) === '' ? 'Administrator' : mb_substr(trim($name), 0, 120);
        return Database::transaction(static function (PDO $pdo) use ($email, $name, $password, $replace): int {
            $existing = $pdo->prepare('SELECT id FROM admin_users WHERE email = :email FOR UPDATE');
            $existing->execute(['email' => $email]);
            $id = $existing->fetchColumn();
            if ($id !== false && !$replace) {
                throw new UserFacingException('An administrator with that email already exists (use --reset to change the password).', 409);
            }
            if ($id === false) {
                $pdo->prepare(
                    'INSERT INTO admin_users (email, name, password_hash, created_at) VALUES (:email, :name, :hash, NOW())'
                )->execute(['email' => $email, 'name' => $name, 'hash' => PasswordService::hash($password)]);
                $id = (int) $pdo->lastInsertId();
                AuditLog::record('system', null, 'cli', 'admin.create', 'admin', $id, ['email' => $email], $pdo);
            } else {
                // A reset also signs the account out everywhere.
                $pdo->prepare(
                    'UPDATE admin_users SET password_hash = :hash, is_active = 1, session_epoch = session_epoch + 1 WHERE id = :id'
                )->execute(['hash' => PasswordService::hash($password), 'id' => $id]);
                AuditLog::record('system', null, 'cli', 'admin.password_reset', 'admin', (int) $id, [], $pdo);
            }
            return (int) $id;
        });
    }

    private static function record(string $ip, string $emailHash, string $result): void
    {
        Database::pdo()->prepare(
            'INSERT INTO admin_login_attempts (ip, email_hash, result, attempted_at) VALUES (:ip, :email, :result, NOW())'
        )->execute(['ip' => $ip, 'email' => $emailHash, 'result' => $result]);
    }
}
