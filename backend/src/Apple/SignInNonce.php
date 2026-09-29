<?php
declare(strict_types=1);

namespace Diwan\Apple;

use Diwan\Config\Env;

/**
 * Stateless nonce for Sign in with Apple: `random.expires.hmac`.
 *
 * The client fetches one from apple-nonce.php, hands it to Apple, and Apple
 * copies it into the identity token's `nonce` claim. apple-sign-in.php then
 * requires (a) our HMAC on it, so it was minted here, (b) it hasn't expired,
 * and (c) it equals the claim, so the token was minted for THIS request —
 * an identity token lifted from elsewhere carries some other nonce.
 *
 * Same HMAC-over-APP_KEY trust model as Session and the download tokens; the
 * domain prefix keeps these from ever being confused with those.
 */
final class SignInNonce
{
    private const TTL_SECONDS = 600;

    public static function issue(): string
    {
        $body = bin2hex(random_bytes(16)) . '.' . (time() + self::TTL_SECONDS);
        return $body . '.' . self::mac($body);
    }

    public static function isValid(string $nonce): bool
    {
        $parts = explode('.', $nonce);
        if (count($parts) !== 3) {
            return false;
        }
        [$random, $expires, $mac] = $parts;
        return hash_equals(self::mac($random . '.' . $expires), $mac) && (int) $expires >= time();
    }

    private static function mac(string $body): string
    {
        return hash_hmac('sha256', 'apple-nonce:' . $body, Env::require('APP_KEY'));
    }
}
