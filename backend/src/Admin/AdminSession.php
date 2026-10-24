<?php
declare(strict_types=1);

namespace Diwan\Admin;

use Diwan\Database\Database;
use Diwan\Support\Crypto;

/**
 * The administrator's session: a signed HttpOnly cookie, built like the
 * customer session (Diwan\Auth\Session) but separate in every way that
 * matters — its own cookie name, an `admin:` signing domain so neither
 * cookie can be replayed as the other, a 12-hour lifetime, SameSite=Strict,
 * and an epoch on admin_users so a password reset or sign-out-everywhere
 * revokes every copy.
 *
 * Every state-changing admin request must also send the CSRF token from
 * csrfToken() in an X-CSRF-Token header (checked by AdminGuard). Strict
 * same-site cookies already stop cross-site requests; the token is the
 * second lock.
 */
final class AdminSession
{
    private const COOKIE_NAME = 'diwan_admin';
    private const TTL_SECONDS = 12 * 3600;

    public static function start(array $admin): string
    {
        $expires = time() + self::TTL_SECONDS;
        $payload = $admin['id'] . '.' . $admin['session_epoch'] . '.' . $expires;
        $value = $payload . '.' . Crypto::hmac('admin:' . $payload);
        setcookie(self::COOKIE_NAME, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        $_COOKIE[self::COOKIE_NAME] = $value;
        return self::csrfToken();
    }

    /** The signed-in, active administrator, or null. */
    public static function admin(): ?array
    {
        $parts = explode('.', (string) ($_COOKIE[self::COOKIE_NAME] ?? ''));
        if (count($parts) !== 4) {
            return null;
        }
        [$id, $epoch, $expires, $signature] = $parts;
        if (!hash_equals(Crypto::hmac('admin:' . $id . '.' . $epoch . '.' . $expires), $signature)
            || (int) $expires < time()) {
            return null;
        }
        $stmt = Database::pdo()->prepare(
            'SELECT id, email, name, session_epoch, last_login_at FROM admin_users WHERE id = :id AND is_active = 1'
        );
        $stmt->execute(['id' => (int) $id]);
        $admin = $stmt->fetch();
        if (!$admin || (int) $admin['session_epoch'] !== (int) $epoch) {
            return null;
        }
        return $admin;
    }

    /** Bound to this exact cookie, so a token from another session is useless. */
    public static function csrfToken(): string
    {
        return Crypto::hmac('admin-csrf:' . (string) ($_COOKIE[self::COOKIE_NAME] ?? ''));
    }

    public static function checkCsrf(): bool
    {
        $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        return $sent !== '' && ($_COOKIE[self::COOKIE_NAME] ?? '') !== '' && hash_equals(self::csrfToken(), $sent);
    }

    public static function destroy(): void
    {
        setcookie(self::COOKIE_NAME, '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict']);
    }

    /** Signs this administrator out of every browser. */
    public static function revokeAll(int $adminId): void
    {
        Database::pdo()->prepare('UPDATE admin_users SET session_epoch = session_epoch + 1 WHERE id = :id')
            ->execute(['id' => $adminId]);
    }
}
