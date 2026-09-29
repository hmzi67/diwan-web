<?php
declare(strict_types=1);

namespace Diwan\Apple;

use Diwan\Config\Env;
use Diwan\Support\Logger;
use RuntimeException;

/**
 * Verifies a Sign in with Apple identity token (an RS256 JWT) — the same
 * check for the website's popup and the iOS app's native sheet; only the
 * audience differs (Services ID vs bundle id).
 *
 * Apple's signing keys are published at appleid.apple.com/auth/keys and
 * rotate occasionally. They're cached in private storage for a day and
 * re-fetched early whenever a token names a key id we haven't seen, so a
 * rotation costs one extra request instead of a day of failed sign-ins.
 */
final class IdentityToken
{
    private const ISSUER    = 'https://appleid.apple.com';
    private const KEYS_URL  = 'https://appleid.apple.com/auth/keys';
    private const CACHE_TTL = 86400;
    /** Apple's clock and ours may disagree a little. */
    private const LEEWAY = 60;

    /**
     * @return array{sub:string, email:?string, email_verified:bool, is_private_email:bool, aud:string}
     */
    public static function verify(string $jwt, string $expectedNonce): array
    {
        $parts  = Jose::split($jwt);
        $header = $parts['header'];
        $claims = $parts['payload'];

        if (($header['alg'] ?? null) !== 'RS256' || !is_string($header['kid'] ?? null)) {
            throw new RuntimeException('Unexpected identity token header.');
        }

        $pem = self::keyFor($header['kid']);
        $ok  = openssl_verify($parts['signingInput'], $parts['signature'], $pem, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            throw new RuntimeException('Identity token signature does not verify.');
        }

        if (($claims['iss'] ?? null) !== self::ISSUER) {
            throw new RuntimeException('Identity token was not issued by Apple.');
        }
        $aud = (string) ($claims['aud'] ?? '');
        if (!in_array($aud, AppleConfig::signInAudiences(), true)) {
            throw new RuntimeException('Identity token was issued for a different app.');
        }
        $now = time();
        if ((int) ($claims['exp'] ?? 0) + self::LEEWAY < $now) {
            throw new RuntimeException('Identity token has expired.');
        }
        if ((int) ($claims['iat'] ?? PHP_INT_MAX) - self::LEEWAY > $now) {
            throw new RuntimeException('Identity token is not valid yet.');
        }
        if (!hash_equals($expectedNonce, (string) ($claims['nonce'] ?? ''))) {
            throw new RuntimeException('Identity token nonce does not match.');
        }
        $sub = (string) ($claims['sub'] ?? '');
        if ($sub === '') {
            throw new RuntimeException('Identity token has no subject.');
        }

        // Apple sends these booleans as either JSON booleans or the strings
        // "true"/"false", depending on the platform that minted the token.
        $truthy = static fn ($v): bool => $v === true || $v === 'true';
        $email  = isset($claims['email']) ? strtolower(trim((string) $claims['email'])) : null;

        return [
            'sub'              => $sub,
            'email'            => $email !== '' ? $email : null,
            'email_verified'   => $truthy($claims['email_verified'] ?? false),
            'is_private_email' => $truthy($claims['is_private_email'] ?? false),
            'aud'              => $aud,
        ];
    }

    private static function keyFor(string $kid): string
    {
        $keys = self::cachedKeys(false);
        if (!isset($keys[$kid])) {
            $keys = self::cachedKeys(true);   // rotated: fetch once more
        }
        if (!isset($keys[$kid])) {
            throw new RuntimeException('Identity token was signed with an unknown key.');
        }
        return $keys[$kid];
    }

    /** @return array<string,string> kid → PEM */
    private static function cachedKeys(bool $forceRefresh): array
    {
        $file = self::cacheFile();
        if (!$forceRefresh && is_readable($file) && filemtime($file) > time() - self::CACHE_TTL) {
            $cached = json_decode((string) file_get_contents($file), true);
            if (is_array($cached) && $cached !== []) {
                return $cached;
            }
        }

        $jwks = self::fetchJwks();
        $keys = [];
        foreach ($jwks['keys'] ?? [] as $jwk) {
            if (($jwk['kty'] ?? '') === 'RSA' && isset($jwk['kid'], $jwk['n'], $jwk['e'])) {
                $keys[(string) $jwk['kid']] = Jose::rsaJwkToPem((string) $jwk['n'], (string) $jwk['e']);
            }
        }
        if ($keys === []) {
            throw new RuntimeException('Apple returned no signing keys.');
        }

        @mkdir(dirname($file), 0750, true);
        @file_put_contents($file, json_encode($keys), LOCK_EX);
        return $keys;
    }

    private static function fetchJwks(): array
    {
        $ch = curl_init(self::KEYS_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if ($code !== 200 || !is_array($decoded)) {
            Logger::error('Apple JWKS fetch failed', ['http' => $code, 'error' => $err]);
            throw new RuntimeException('Could not reach Apple to verify the sign-in.');
        }
        return $decoded;
    }

    private static function cacheFile(): string
    {
        $base = defined('DIWAN_PRIVATE_STORAGE') ? DIWAN_PRIVATE_STORAGE : sys_get_temp_dir();
        return $base . '/cache/apple-jwks.json';
    }

    /** Test hook: seeds the key cache so tests never touch the network. */
    public static function seedKeysForTesting(array $kidToPem): void
    {
        $file = self::cacheFile();
        @mkdir(dirname($file), 0750, true);
        file_put_contents($file, json_encode($kidToPem));
        touch($file);
    }

    /** True when the server is configured to accept web sign-ins. */
    public static function webEnabled(): bool
    {
        return AppleConfig::servicesId() !== null && Env::get('APP_KEY') !== null;
    }
}
