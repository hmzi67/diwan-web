<?php
declare(strict_types=1);

namespace Diwan\Apple;

use RuntimeException;

/**
 * The few JOSE/DER primitives Apple's formats need, with no Composer
 * dependency (FTP-only host — same reason sodium_compat is vendored).
 *
 * Apple signs with two algorithms:
 *   - ES256 (App Store transactions and server notifications, and the
 *     client-secret JWT *we* sign for Sign in with Apple's REST API).
 *     JWS carries the signature as raw R||S; OpenSSL speaks DER. Hence
 *     rawToDer() / derToRaw().
 *   - RS256 (Sign in with Apple identity tokens). Apple publishes the keys
 *     as JWKs (modulus + exponent); OpenSSL wants PEM. Hence rsaJwkToPem().
 */
final class Jose
{
    public static function b64urlDecode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4), true);
        if ($decoded === false) {
            throw new RuntimeException('Malformed base64url segment.');
        }
        return $decoded;
    }

    public static function b64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Splits a compact JWS into its decoded header and payload plus what the
     * signature covers. Nothing here is trusted yet — callers verify first.
     *
     * @return array{header: array, payload: array, signingInput: string, signature: string}
     */
    public static function split(string $jws): array
    {
        $parts = explode('.', trim($jws));
        if (count($parts) !== 3) {
            throw new RuntimeException('Not a compact JWS.');
        }
        $header  = json_decode(self::b64urlDecode($parts[0]), true);
        $payload = json_decode(self::b64urlDecode($parts[1]), true);
        if (!is_array($header) || !is_array($payload)) {
            throw new RuntimeException('JWS header or payload is not a JSON object.');
        }
        return [
            'header'       => $header,
            'payload'      => $payload,
            'signingInput' => $parts[0] . '.' . $parts[1],
            'signature'    => self::b64urlDecode($parts[2]),
        ];
    }

    /** ES256 JWS signature (32-byte R || 32-byte S) → DER for openssl_verify(). */
    public static function rawToDer(string $raw): string
    {
        if (strlen($raw) !== 64) {
            throw new RuntimeException('ES256 signature must be 64 bytes.');
        }
        $r = self::derInteger(substr($raw, 0, 32));
        $s = self::derInteger(substr($raw, 32, 32));
        return "\x30" . self::derLength(strlen($r) + strlen($s)) . $r . $s;
    }

    /** DER ECDSA signature from openssl_sign() → ES256 JWS signature. */
    public static function derToRaw(string $der): string
    {
        $offset = 0;
        if (($der[$offset++] ?? '') !== "\x30") {
            throw new RuntimeException('Not a DER sequence.');
        }
        self::readLength($der, $offset);
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            if (($der[$offset++] ?? '') !== "\x02") {
                throw new RuntimeException('Not a DER integer.');
            }
            $len = self::readLength($der, $offset);
            $int = ltrim(substr($der, $offset, $len), "\x00");
            $offset += $len;
            if (strlen($int) > 32) {
                throw new RuntimeException('ECDSA integer too long for P-256.');
            }
            $out .= str_pad($int, 32, "\x00", STR_PAD_LEFT);
        }
        return $out;
    }

    /** RSA public JWK (base64url n, e) → PEM SubjectPublicKeyInfo. */
    public static function rsaJwkToPem(string $n, string $e): string
    {
        $modulus  = self::derInteger(self::b64urlDecode($n));
        $exponent = self::derInteger(self::b64urlDecode($e));
        $rsaKey   = "\x30" . self::derLength(strlen($modulus) + strlen($exponent)) . $modulus . $exponent;

        // AlgorithmIdentifier { rsaEncryption (1.2.840.113549.1.1.1), NULL }
        $algorithm = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        $bitString = "\x03" . self::derLength(strlen($rsaKey) + 1) . "\x00" . $rsaKey;
        $spki      = "\x30" . self::derLength(strlen($algorithm) + strlen($bitString)) . $algorithm . $bitString;

        return self::pem('PUBLIC KEY', $spki);
    }

    /** Wraps DER bytes in PEM armour (certificates and keys alike). */
    public static function pem(string $label, string $der): string
    {
        return "-----BEGIN {$label}-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END {$label}-----\n";
    }

    /** Unsigned big-endian bytes → DER INTEGER (minimal, positive). */
    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || (ord($bytes[0]) & 0x80)) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . self::derLength(strlen($bytes)) . $bytes;
    }

    private static function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = ltrim(pack('N', $length), "\x00");
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function readLength(string $der, int &$offset): int
    {
        $first = ord($der[$offset++] ?? "\x00");
        if ($first < 0x80) {
            return $first;
        }
        $count = $first & 0x7f;
        if ($count < 1 || $count > 4) {
            throw new RuntimeException('Unsupported DER length.');
        }
        $length = 0;
        for ($i = 0; $i < $count; $i++) {
            $length = ($length << 8) | ord($der[$offset++] ?? "\x00");
        }
        return $length;
    }
}
