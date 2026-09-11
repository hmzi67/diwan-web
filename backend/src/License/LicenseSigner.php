<?php
declare(strict_types=1);

namespace Diwan\License;

use Diwan\Config\Env;
use RuntimeException;

/**
 * Ed25519 signing of licence-status payloads for the desktop app.
 *
 * This is a DIFFERENT key from APP_KEY, on purpose. APP_KEY is a symmetric
 * secret: anything that can verify with it can also forge with it, so it
 * can never leave the server. The app, however, has to verify these
 * payloads while fully offline — which means the verifying half has to
 * ship inside the binary. Ed25519 makes that safe: the app embeds only the
 * PUBLIC key, which can check a signature but cannot produce one. Someone
 * who extracts it from the binary gains nothing.
 *
 * The private key lives in config/.env as LICENSE_SIGNING_PRIVATE_KEY
 * (base64 of libsodium's 64-byte secret key). It is read in exactly one
 * place (the constructor), never logged, and never returned. Generate it
 * with scripts/generate-license-signing-key.php (repo root).
 *
 * LICENSE_SIGNING_KEY_ID names the key in every payload (`kid`) so the app
 * can embed more than one public key during a rotation: ship a build that
 * accepts both, then switch the server's key, then drop the old one.
 *
 * What is signed: the exact JSON bytes of the payload. The response carries
 * those bytes base64-encoded, untouched, alongside the detached signature —
 * the app verifies the bytes first and only then parses them. Re-encoding
 * on either side would break the signature, which is the point: there is
 * one canonical form and it is the one that was signed.
 */
final class LicenseSigner
{
    /** Bump when the payload's shape changes incompatibly. */
    public const PAYLOAD_VERSION = 1;

    private readonly string $secretKey;
    private readonly string $keyId;

    public function __construct()
    {
        if (!function_exists('sodium_crypto_sign_detached')) {
            throw new RuntimeException('The sodium extension is required for licence signing.');
        }

        $encoded = Env::require('LICENSE_SIGNING_PRIVATE_KEY');
        $raw     = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            // Deliberately does not echo the value or its length back.
            throw new RuntimeException(
                'LICENSE_SIGNING_PRIVATE_KEY is not a valid base64 Ed25519 secret key. '
                . 'Generate one with scripts/generate-license-signing-key.php (repo root).'
            );
        }

        $this->secretKey = $raw;
        $this->keyId     = Env::get('LICENSE_SIGNING_KEY_ID', 'default');
    }

    public function keyId(): string
    {
        return $this->keyId;
    }

    /** base64 of the 32-byte public key — the only half the app ever sees. */
    public function publicKeyBase64(): string
    {
        return base64_encode(sodium_crypto_sign_publickey_from_secretkey($this->secretKey));
    }

    /**
     * Signs `$fields` and returns the wire form:
     *   ['payload' => base64(json), 'signature' => base64(sig), 'kid' => ...]
     *
     * `v` and `kid` are prepended here so no caller can forget them and so
     * they are always the first keys — the app rejects a payload whose `v`
     * it does not understand before looking at anything else.
     */
    public function sign(array $fields): array
    {
        $payload = ['v' => self::PAYLOAD_VERSION, 'kid' => $this->keyId] + $fields;

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $sig  = sodium_crypto_sign_detached($json, $this->secretKey);

        return [
            'payload'   => base64_encode($json),
            'signature' => base64_encode($sig),
            'kid'       => $this->keyId,
        ];
    }

    /**
     * Fresh keypair, both halves base64. Used only by the keygen script —
     * the running application never generates keys.
     */
    public static function generateKeyPair(): array
    {
        $pair = sodium_crypto_sign_keypair();
        return [
            'private' => base64_encode(sodium_crypto_sign_secretkey($pair)),
            'public'  => base64_encode(sodium_crypto_sign_publickey($pair)),
        ];
    }
}
