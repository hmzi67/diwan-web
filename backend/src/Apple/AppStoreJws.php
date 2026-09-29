<?php
declare(strict_types=1);

namespace Diwan\Apple;

use RuntimeException;

/**
 * Verifies data the App Store signs: StoreKit 2 transactions
 * (`Transaction.jwsRepresentation`) and App Store Server Notifications V2
 * (`signedPayload`, and the `signedTransactionInfo` nested inside it).
 *
 * Each is an ES256 JWS whose header carries its own certificate chain
 * (`x5c`: leaf, intermediate, root). The chain proves nothing on its own —
 * anyone can mint a chain — so the root is PINNED to Apple Root CA - G3
 * below, and every link is checked: root → intermediate → leaf signatures,
 * Apple's marker OIDs on the leaf and intermediate, validity dates at the
 * moment Apple signed, and finally the JWS signature against the leaf.
 * Only then is a single payload field trusted.
 *
 * This is Apple's documented offline verification, the same checks the
 * official App Store Server Library performs (minus its optional OCSP
 * revocation lookup). No network call, no API key needed.
 */
final class AppStoreJws
{
    /**
     * Apple Root CA - G3, DER, base64. From
     * https://www.apple.com/certificateauthority/AppleRootCA-G3.cer
     * SHA-256 63:34:3A:BF:B8:9A:6A:03:EB:B5:7E:9B:3F:5F:A7:BE:
     *         7C:4F:5C:75:6F:30:17:B3:A8:C4:88:C3:65:3E:91:79
     * Valid until 2039-04-30.
     */
    private const APPLE_ROOT_CA_G3 =
        'MIICQzCCAcmgAwIBAgIILcX8iNLFS5UwCgYIKoZIzj0EAwMwZzEbMBkGA1UEAwwSQXBwbGUgUm9v'
        . 'dCBDQSAtIEczMSYwJAYDVQQLDB1BcHBsZSBDZXJ0aWZpY2F0aW9uIEF1dGhvcml0eTETMBEGA1UE'
        . 'CgwKQXBwbGUgSW5jLjELMAkGA1UEBhMCVVMwHhcNMTQwNDMwMTgxOTA2WhcNMzkwNDMwMTgxOTA2'
        . 'WjBnMRswGQYDVQQDDBJBcHBsZSBSb290IENBIC0gRzMxJjAkBgNVBAsMHUFwcGxlIENlcnRpZmlj'
        . 'YXRpb24gQXV0aG9yaXR5MRMwEQYDVQQKDApBcHBsZSBJbmMuMQswCQYDVQQGEwJVUzB2MBAGByqG'
        . 'SM49AgEGBSuBBAAiA2IABJjpLz1AcqTtkyJygRMc3RCV8cWjTnHcFBbZDuWmBSp3ZHtfTjjTuxxE'
        . 'tX/1H7YyYl3J6YRbTzBPEVoA/VhYDKX1DyxNB0cTddqXl5dvMVztK517IDvYuVTZXpmkOlEKMaNC'
        . 'MEAwHQYDVR0OBBYEFLuw3qFYM4iapIqZ3r6966/ayySrMA8GA1UdEwEB/wQFMAMBAf8wDgYDVR0P'
        . 'AQH/BAQDAgEGMAoGCCqGSM49BAMDA2gAMGUCMQCD6cHEFl4aXTQY2e3v9GwOAEZLuN+yRhHFD/3m'
        . 'eoyhpmvOwgPUnPWTxnS4at+qIxUCMG1mihDK1A3UT82NQz60imOlM27jbdoXt2QfyFMm+YhidDkL'
        . 'F1vLUagM6BgD56KyKA==';

    /** Marker extensions Apple puts on the App Store signing leaf / WWDR intermediate. */
    private const LEAF_OID         = '1.2.840.113635.100.6.11.1';
    private const INTERMEDIATE_OID = '1.2.840.113635.100.6.2.1';

    /** @var string|null test hook: a throwaway root, never set in production code paths */
    private static ?string $rootOverrideDer = null;

    /**
     * Returns the verified payload, or throws. Never returns unverified data.
     *
     * @return array<string,mixed>
     */
    public static function verify(string $jws): array
    {
        $jwsParts = Jose::split($jws);
        $header   = $jwsParts['header'];
        $payload  = $jwsParts['payload'];

        if (($header['alg'] ?? null) !== 'ES256') {
            throw new RuntimeException('Unexpected JWS algorithm.');
        }
        $x5c = $header['x5c'] ?? null;
        if (!is_array($x5c) || count($x5c) !== 3) {
            throw new RuntimeException('JWS must carry a three-certificate chain.');
        }

        $der = [];
        foreach ($x5c as $encoded) {
            $bytes = is_string($encoded) ? base64_decode($encoded, true) : false;
            if ($bytes === false || $bytes === '') {
                throw new RuntimeException('Malformed certificate in x5c.');
            }
            $der[] = $bytes;
        }
        [$leafDer, $intermediateDer, $rootDer] = $der;

        $pinnedRoot = self::$rootOverrideDer ?? base64_decode(self::APPLE_ROOT_CA_G3, true);
        if (!hash_equals(hash('sha256', (string) $pinnedRoot), hash('sha256', $rootDer))) {
            throw new RuntimeException('Certificate chain does not end at Apple Root CA - G3.');
        }

        $leaf         = self::cert($leafDer);
        $intermediate = self::cert($intermediateDer);
        $root         = self::cert($rootDer);

        if (openssl_x509_verify($intermediate, self::publicKey($root)) !== 1) {
            throw new RuntimeException('Intermediate certificate is not signed by the Apple root.');
        }
        if (openssl_x509_verify($leaf, self::publicKey($intermediate)) !== 1) {
            throw new RuntimeException('Signing certificate is not signed by the intermediate.');
        }

        self::requireExtension($leaf, self::LEAF_OID, 'signing');
        self::requireExtension($intermediate, self::INTERMEDIATE_OID, 'intermediate');

        // Judge validity at the moment Apple signed, not now: a transaction
        // signed last year stays valid after that year's leaf expires, which
        // is exactly what "restore purchases" re-sends.
        $signedAt = isset($payload['signedDate']) ? intdiv((int) $payload['signedDate'], 1000) : time();
        self::requireValidAt($leaf, $signedAt, 'signing');
        self::requireValidAt($intermediate, $signedAt, 'intermediate');

        $ok = openssl_verify(
            $jwsParts['signingInput'],
            Jose::rawToDer($jwsParts['signature']),
            self::publicKey($leaf),
            OPENSSL_ALGO_SHA256
        );
        if ($ok !== 1) {
            throw new RuntimeException('JWS signature does not verify.');
        }

        return $payload;
    }

    /** For tests only: accept a chain rooted at this DER instead of Apple's. */
    public static function overrideRootForTesting(?string $der): void
    {
        self::$rootOverrideDer = $der;
    }

    /** @return \OpenSSLCertificate */
    private static function cert(string $der)
    {
        $cert = openssl_x509_read(Jose::pem('CERTIFICATE', $der));
        if ($cert === false) {
            throw new RuntimeException('Unreadable certificate in x5c.');
        }
        return $cert;
    }

    /** @param \OpenSSLCertificate $cert */
    private static function publicKey($cert): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_get_public($cert);
        if ($key === false) {
            throw new RuntimeException('Certificate has no usable public key.');
        }
        return $key;
    }

    /** @param \OpenSSLCertificate $cert */
    private static function requireExtension($cert, string $oid, string $which): void
    {
        $info = openssl_x509_parse($cert);
        if (!is_array($info) || !array_key_exists($oid, $info['extensions'] ?? [])) {
            throw new RuntimeException("The {$which} certificate is not an App Store certificate.");
        }
    }

    /** @param \OpenSSLCertificate $cert */
    private static function requireValidAt($cert, int $when, string $which): void
    {
        $info = openssl_x509_parse($cert);
        $from = (int) ($info['validFrom_time_t'] ?? PHP_INT_MAX);
        $to   = (int) ($info['validTo_time_t'] ?? 0);
        if ($when < $from || $when > $to) {
            throw new RuntimeException("The {$which} certificate was not valid when this was signed.");
        }
    }
}
