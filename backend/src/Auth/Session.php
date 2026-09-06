<?php
declare(strict_types=1);

namespace Diwan\Auth;

use Diwan\Config\Env;
use Diwan\Database\Database;

/**
 * Customer dashboard session: a signed, mostly-stateless HttpOnly cookie.
 *
 * No session table — the cookie itself is the proof. Same trust model as
 * everywhere else in this codebase (LicenseService, DownloadService): an HMAC
 * over APP_KEY. A stolen cookie without APP_KEY is useless; APP_KEY without
 * the cookie mints nothing on its own.
 *
 * The one piece of server state is customers.session_epoch, signed into the
 * cookie and checked on read. Without it a purely stateless cookie could not
 * be revoked before its 30-day expiry, so a password reset could not evict an
 * attacker who already had a session — which is the main thing a reset is for.
 * Bumping the epoch invalidates every cookie issued before it.
 */
final class Session
{
    private const COOKIE_NAME = 'diwan_session';
    private const TTL_DAYS = 30;

    /**
     * Sets the session cookie for this customer. Called right after sign-in.
     *
     * $epoch lets a caller that has already read the customer row pass the
     * value in rather than making this re-query for it — sign-in.php selects
     * it alongside the password hash, so the common path costs no extra query.
     */
    public static function start(int $customerId, ?int $epoch = null): void
    {
        $expires = time() + self::TTL_DAYS * 86400;
        $epoch ??= self::epochFor($customerId);
        $payload = $customerId . '.' . $epoch . '.' . $expires;
        $signature = hash_hmac('sha256', $payload, Env::require('APP_KEY'));

        setcookie(self::COOKIE_NAME, $payload . '.' . $signature, [
            'expires'  => $expires,
            'path'     => '/',
            // Secure only when the request actually arrived over HTTPS, so
            // this still works on `php -S localhost:8000` during local dev.
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /** Returns the logged-in customer's id, or null if there is no valid session. */
    public static function customerId(): ?int
    {
        $value = (string) ($_COOKIE[self::COOKIE_NAME] ?? '');
        $parts = explode('.', $value);

        // 4 parts since migration 004 (id.epoch.expires.signature). Cookies in
        // the old 3-part shape were minted by the magic-link flow before the
        // epoch existed; they are rejected rather than honoured, so the
        // revocation guarantee has no hole to slip through. The cost is that
        // anyone holding one signs in again — with one real account on the
        // system when this shipped, that is not a migration worth building.
        if (count($parts) !== 4) {
            return null;
        }
        [$id, $epoch, $expires, $signature] = $parts;

        $expected = hash_hmac('sha256', $id . '.' . $epoch . '.' . $expires, Env::require('APP_KEY'));
        if (!hash_equals($expected, $signature)) {
            return null;
        }
        if ((int) $expires < time()) {
            return null;
        }
        if ((int) $epoch !== self::epochFor((int) $id)) {
            return null;   // password was reset after this cookie was issued
        }
        return (int) $id;
    }

    public static function destroy(): void
    {
        setcookie(self::COOKIE_NAME, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Invalidates every session cookie already issued for this customer, on
     * every device. Called after a password reset.
     */
    public static function revokeAll(\PDO $pdo, int $customerId): void
    {
        $pdo->prepare('UPDATE customers SET session_epoch = session_epoch + 1 WHERE id = :id')
            ->execute(['id' => $customerId]);
    }

    /**
     * Reads the customer's current epoch. Missing row returns -1, which can
     * never equal a stored epoch (UNSIGNED), so a deleted account's cookie
     * stops working immediately.
     */
    private static function epochFor(int $customerId): int
    {
        $stmt = Database::pdo()->prepare('SELECT session_epoch FROM customers WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $customerId]);
        $epoch = $stmt->fetchColumn();

        return $epoch === false ? -1 : (int) $epoch;
    }
}
