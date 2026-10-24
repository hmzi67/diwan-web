<?php
declare(strict_types=1);

namespace Diwan\Auth;

use Diwan\Database\Database;
use Diwan\Support\Crypto;
use PDO;

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
    private const APP_TOKEN_TTL_SECONDS = 3600;

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
        $signature = Crypto::hmac($payload);

        setcookie(self::COOKIE_NAME, $payload . '.' . $signature, [
            'expires'  => $expires,
            'path'     => '/',
            // Secure when the request arrived over HTTPS, including behind a
            // TLS-terminating proxy — same X-Forwarded-Proto test .htaccess
            // uses to force the redirect, so the flag and the redirect can
            // never disagree. Honoring HTTPS alone would leave the cookie
            // unflagged on any host where TLS terminates in front of PHP.
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
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

        $expected = Crypto::hmac($id . '.' . $epoch . '.' . $expires);
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

    /**
     * Short-lived bearer token for the iOS app, which talks to the API from
     * Rust with no cookie jar. Same shape and epoch check as the cookie, but
     * signed under a different domain prefix, so neither can ever be
     * replayed as the other. Held in the app's memory only; the TTL is
     * short because the app simply signs in with Apple again when needed.
     */
    public static function issueAppToken(int $customerId, ?int $epoch = null): string
    {
        $expires = time() + self::APP_TOKEN_TTL_SECONDS;
        $epoch ??= self::epochFor($customerId);
        $payload = $customerId . '.' . $epoch . '.' . $expires;
        return $payload . '.' . Crypto::hmac('app:' . $payload);
    }

    /**
     * The customer behind an app token, or null. The app sends it in the
     * JSON body (`app_token`), not an Authorization header: shared hosts
     * running PHP under FastCGI routinely strip that header before PHP
     * ever sees it.
     */
    public static function customerIdFromAppToken(?string $token): ?int
    {
        $parts = explode('.', (string) $token);
        if (count($parts) !== 4) {
            return null;
        }
        [$id, $epoch, $expires, $signature] = $parts;
        $expected = Crypto::hmac('app:' . $id . '.' . $epoch . '.' . $expires);
        if (!hash_equals($expected, $signature) || (int) $expires < time()) {
            return null;
        }
        if ((int) $epoch !== self::epochFor((int) $id)) {
            return null;
        }
        return (int) $id;
    }

    /** Website cookie or app token — for endpoints both clients call. */
    public static function customerIdFromCookieOrAppToken(?string $appToken): ?int
    {
        return self::customerId() ?? self::customerIdFromAppToken($appToken);
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
    public static function revokeAll(PDO $pdo, int $customerId): void
    {
        $pdo->prepare('UPDATE customers SET session_epoch = session_epoch + 1 WHERE id = :id')
            ->execute(['id' => $customerId]);
    }

    /**
     * Reads the customer's current epoch. Missing (or suspended) returns -1,
     * which can never equal a stored epoch (UNSIGNED), so a deleted or
     * suspended account's cookie stops working immediately.
     */
    private static function epochFor(int $customerId): int
    {
        // A suspended account reads as missing: every cookie and app token
        // it holds stops working at once, and reactivating restores them.
        $stmt = Database::pdo()->prepare(
            "SELECT session_epoch FROM customers WHERE id = :id AND status = 'active' LIMIT 1"
        );
        $stmt->execute(['id' => $customerId]);
        $epoch = $stmt->fetchColumn();

        return $epoch === false ? -1 : (int) $epoch;
    }
}
