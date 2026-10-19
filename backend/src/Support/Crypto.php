<?php
declare(strict_types=1);

namespace Diwan\Support;

use Diwan\Config\Env;

/**
 * The two primitives every module keys off APP_KEY: a keyed hash for lookups
 * (tokens, licence keys, emails) and AES-256-GCM for the few values that must
 * be recoverable (licence keys for resend, Apple refresh tokens).
 */
final class Crypto
{
    private const NONCE_BYTES = 12;
    private const TAG_BYTES   = 16;

    /** HMAC, not a bare hash: a stolen DB is useless without APP_KEY. */
    public static function hmac(string $value): string
    {
        return hash_hmac('sha256', $value, Env::require('APP_KEY'));
    }

    /** base64(nonce . tag . ciphertext) under a 32-byte binary key. */
    public static function seal(string $plain, string $key): string
    {
        $nonce  = random_bytes(self::NONCE_BYTES);
        $tag    = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        return base64_encode($nonce . $tag . $cipher);
    }

    /** Inverse of seal(). Null for anything missing, malformed or tampered with. */
    public static function open(?string $encoded, string $key): ?string
    {
        if ($encoded === null || $encoded === '') {
            return null;
        }
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < self::NONCE_BYTES + self::TAG_BYTES) {
            return null;
        }
        $plain = openssl_decrypt(
            substr($raw, self::NONCE_BYTES + self::TAG_BYTES),
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            substr($raw, 0, self::NONCE_BYTES),
            substr($raw, self::NONCE_BYTES, self::TAG_BYTES)
        );
        return $plain === false ? null : $plain;
    }

    /** 64 hex chars: the shape of every emailed or download token. */
    public static function newToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function isToken(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $value) === 1;
    }
}
