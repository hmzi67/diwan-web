<?php
declare(strict_types=1);

namespace Diwan\Apple;

use Diwan\Support\Logger;
use RuntimeException;

/**
 * Sign in with Apple's REST API: trade a sign-in's one-time authorization
 * code for a refresh token, and revoke that token when the account is
 * deleted. App Review requires the revocation for any iOS app that offers
 * Sign in with Apple together with account deletion.
 *
 * Needs the optional Team ID / Key ID / .p8 (AppleConfig::signInKey()).
 * Without them every method here is a quiet no-op that returns null/false —
 * sign-in itself never depends on this class.
 */
final class AppleAuthClient
{
    private const TOKEN_URL  = 'https://appleid.apple.com/auth/token';
    private const REVOKE_URL = 'https://appleid.apple.com/auth/revoke';

    public static function isConfigured(): bool
    {
        return AppleConfig::signInKey() !== null;
    }

    /**
     * One-time code → refresh token, or null when unconfigured or Apple
     * refuses (a failed exchange must never fail the sign-in itself).
     * $redirectUri is required for web codes and must be omitted for native.
     */
    public static function exchangeCode(string $code, string $clientId, ?string $redirectUri): ?string
    {
        $secret = self::clientSecret($clientId);
        if ($secret === null || $code === '') {
            return null;
        }
        $fields = [
            'client_id'     => $clientId,
            'client_secret' => $secret,
            'code'          => $code,
            'grant_type'    => 'authorization_code',
        ];
        if ($redirectUri !== null) {
            $fields['redirect_uri'] = $redirectUri;
        }
        [$status, $body] = self::post(self::TOKEN_URL, $fields);
        if ($status !== 200 || !is_string($body['refresh_token'] ?? null)) {
            Logger::warning('Apple code exchange failed', ['http' => $status, 'error' => $body['error'] ?? null]);
            return null;
        }
        return $body['refresh_token'];
    }

    public static function revoke(string $refreshToken, string $clientId): bool
    {
        $secret = self::clientSecret($clientId);
        if ($secret === null) {
            return false;
        }
        [$status, $body] = self::post(self::REVOKE_URL, [
            'client_id'       => $clientId,
            'client_secret'   => $secret,
            'token'           => $refreshToken,
            'token_type_hint' => 'refresh_token',
        ]);
        if ($status !== 200) {
            Logger::warning('Apple token revocation failed', ['http' => $status, 'error' => $body['error'] ?? null]);
            return false;
        }
        return true;
    }

    /**
     * The ES256 JWT Apple accepts as `client_secret`. Short-lived on purpose
     * (Apple allows six months); it's minted per request and never stored.
     */
    private static function clientSecret(string $clientId): ?string
    {
        $key = AppleConfig::signInKey();
        if ($key === null) {
            return null;
        }
        $privateKey = openssl_pkey_get_private($key['private_key']);
        if ($privateKey === false) {
            Logger::error('APPLE_SIGN_IN_PRIVATE_KEY is not a readable .p8 key');
            return null;
        }

        $now     = time();
        $header  = Jose::b64urlEncode(json_encode(['alg' => 'ES256', 'kid' => $key['key_id']]));
        $payload = Jose::b64urlEncode(json_encode([
            'iss' => $key['team_id'],
            'iat' => $now,
            'exp' => $now + 300,
            'aud' => 'https://appleid.apple.com',
            'sub' => $clientId,
        ]));
        $input = $header . '.' . $payload;
        if (!openssl_sign($input, $der, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the Apple client secret.');
        }
        return $input . '.' . Jose::b64urlEncode(Jose::derToRaw($der));
    }

    /** @return array{0:int, 1:array} */
    private static function post(string $url, array $fields): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $body = is_string($raw) ? json_decode($raw, true) : null;
        return [$status, is_array($body) ? $body : []];
    }
}
