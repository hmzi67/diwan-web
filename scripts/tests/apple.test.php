<?php
declare(strict_types=1);

use Diwan\Apple\AppStoreJws;
use Diwan\Apple\AppStoreService;
use Diwan\Apple\IdentityToken;
use Diwan\Apple\Jose;
use Diwan\Support\UserFacingException;

/** A P-256 key. Some PHP/OpenSSL builds cannot generate EC keys, so fall back to the CLI. */
function ecKey(): OpenSSLAsymmetricKey
{
    $key = @openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    if ($key === false) {
        $pem = shell_exec('openssl genpkey -algorithm EC -pkeyopt ec_paramgen_curve:P-256 2>/dev/null');
        $key = is_string($pem) ? openssl_pkey_get_private($pem) : false;
    }
    if ($key === false) {
        throw new RuntimeException('Cannot generate a P-256 key on this machine.');
    }
    return $key;
}

/**
 * A throwaway App Store-shaped chain (root → intermediate → leaf, P-256),
 * carrying the marker extensions AppStoreJws requires. Built once per run.
 */
function appleChain(): array
{
    static $chain = null;
    if ($chain !== null) {
        return $chain;
    }
    $cnf = DIWAN_PRIVATE_STORAGE . '/apple-test-openssl.cnf';
    file_put_contents($cnf, <<<CNF
[req]
distinguished_name = dn
[dn]
[root]
basicConstraints = critical,CA:true
keyUsage = critical,keyCertSign,cRLSign
[intermediate]
basicConstraints = critical,CA:true
keyUsage = critical,keyCertSign
1.2.840.113635.100.6.2.1 = ASN1:NULL
[leaf]
basicConstraints = critical,CA:false
1.2.840.113635.100.6.11.1 = ASN1:NULL
[plain_leaf]
basicConstraints = critical,CA:false
CNF);
    $make = static function (string $cn, string $section, $issuer, $issuerKey) use ($cnf): array {
        $key  = ecKey();
        $opts = ['config' => $cnf, 'digest_alg' => 'sha256', 'x509_extensions' => $section];
        $csr  = openssl_csr_new(['commonName' => $cn], $key, $opts);
        $cert = openssl_csr_sign($csr, $issuer, $issuerKey ?? $key, 30, $opts, random_int(1, PHP_INT_MAX));
        openssl_x509_export($cert, $pem);
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pem));
        return ['cert' => $cert, 'key' => $key, 'der' => $der];
    };
    $root  = $make('Test Root', 'root', null, null);
    $inter = $make('Test WWDR', 'intermediate', $root['cert'], $root['key']);
    $leaf  = $make('Test Signing', 'leaf', $inter['cert'], $inter['key']);
    $plain = $make('Test Plain Leaf', 'plain_leaf', $inter['cert'], $inter['key']);
    return $chain = compact('root', 'inter', 'leaf', 'plain');
}

/** An ES256 JWS over $payload, signed by $leaf, carrying the full x5c chain. */
function appleJws(array $payload, ?array $leaf = null): string
{
    $chain  = appleChain();
    $leaf ??= $chain['leaf'];
    $header = ['alg' => 'ES256', 'x5c' => array_map('base64_encode', [$leaf['der'], $chain['inter']['der'], $chain['root']['der']])];
    $input  = Jose::b64urlEncode(json_encode($header)) . '.' . Jose::b64urlEncode(json_encode($payload));
    openssl_sign($input, $der, $leaf['key'], OPENSSL_ALGO_SHA256);
    return $input . '.' . Jose::b64urlEncode(Jose::derToRaw($der));
}

function transaction(array $overrides = []): array
{
    return $overrides + [
        'bundleId'              => 'com.diwan.pos',
        'environment'           => 'Production',
        'type'                  => 'Non-Consumable',
        'productId'             => 'com.diwan.pos.license.standard',
        'originalTransactionId' => (string) random_int(1, PHP_INT_MAX),
        'transactionId'         => (string) random_int(1, PHP_INT_MAX),
        'purchaseDate'          => time() * 1000,
        'signedDate'            => time() * 1000,
    ];
}

/** RS256 identity token signed by a key placed in IdentityToken's JWKS cache. */
function identityToken(array $claims, string $kid = 'test-kid'): string
{
    static $key = null;
    if ($key === null) {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        @mkdir(DIWAN_PRIVATE_STORAGE . '/cache', 0700, true);
        file_put_contents(DIWAN_PRIVATE_STORAGE . '/cache/apple-jwks.json', json_encode([
            'test-kid' => openssl_pkey_get_details($key)['key'],
        ]));
    }
    $input = Jose::b64urlEncode(json_encode(['alg' => 'RS256', 'kid' => $kid]))
        . '.' . Jose::b64urlEncode(json_encode($claims + [
            'iss'   => 'https://appleid.apple.com',
            'aud'   => 'com.diwan.pos',
            'sub'   => '000123.test',
            'iat'   => time(),
            'exp'   => time() + 600,
            'nonce' => 'nonce-1',
        ]));
    openssl_sign($input, $sig, $key, OPENSSL_ALGO_SHA256);
    return $input . '.' . Jose::b64urlEncode($sig);
}

return [
    'an App Store JWS on a chain to the pinned root verifies' => static function (): void {
        AppStoreJws::overrideRootForTesting(appleChain()['root']['der']);
        TestKit::same('com.diwan.pos', AppStoreJws::verify(appleJws(transaction()))['bundleId'], 'payload returned');
    },

    'a JWS whose chain does not end at the Apple root is refused' => static function (): void {
        AppStoreJws::overrideRootForTesting(null);   // the real Apple Root CA - G3
        TestKit::throws(RuntimeException::class, static fn () => AppStoreJws::verify(appleJws(transaction())),
            'self-made chain', 'Apple Root CA');
    },

    'a JWS with a tampered payload is refused' => static function (): void {
        AppStoreJws::overrideRootForTesting(appleChain()['root']['der']);
        $parts = explode('.', appleJws(transaction()));
        $parts[1] = Jose::b64urlEncode(json_encode(transaction(['productId' => 'com.diwan.pos.license.enterprise'])));
        TestKit::throws(RuntimeException::class, static fn () => AppStoreJws::verify(implode('.', $parts)),
            'swapped payload', 'does not verify');
    },

    'a JWS signed by a leaf without the App Store marker is refused' => static function (): void {
        AppStoreJws::overrideRootForTesting(appleChain()['root']['der']);
        TestKit::throws(RuntimeException::class, static fn () => AppStoreJws::verify(appleJws(transaction(), appleChain()['plain'])),
            'unmarked leaf', 'not an App Store certificate');
    },

    'verified transactions for another app or a family share are refused with customer text' => static function (): void {
        AppStoreJws::overrideRootForTesting(appleChain()['root']['der']);
        $store = new AppStoreService();
        TestKit::throws(UserFacingException::class, static fn () => $store->verifiedTransaction(appleJws(transaction(['bundleId' => 'com.other.app']))),
            'other app', 'different app');
        TestKit::throws(UserFacingException::class, static fn () => $store->verifiedTransaction(appleJws(transaction(['inAppOwnershipType' => 'FAMILY_SHARED']))),
            'family sharing', 'Family Sharing');
        TestKit::same('diwan-pos-standard', $store->verifiedTransaction(appleJws(transaction()))['sku'], 'a good one maps to its SKU');
    },

    'an identity token verifies only with the right nonce, audience and time' => static function (): void {
        TestKit::same('000123.test', IdentityToken::verify(identityToken([]), 'nonce-1')['sub'], 'valid token');
        TestKit::throws(RuntimeException::class, static fn () => IdentityToken::verify(identityToken([]), 'other-nonce'),
            'wrong nonce', 'nonce');
        TestKit::throws(RuntimeException::class, static fn () => IdentityToken::verify(identityToken(['aud' => 'com.other.app']), 'nonce-1'),
            'wrong audience', 'different app');
        TestKit::throws(RuntimeException::class, static fn () => IdentityToken::verify(identityToken(['exp' => time() - 3600]), 'nonce-1'),
            'expired', 'expired');
        TestKit::throws(RuntimeException::class, static fn () => IdentityToken::verify(identityToken(['iss' => 'https://evil.example']), 'nonce-1'),
            'wrong issuer', 'not issued by Apple');
    },

    'an identity token with a forged signature or algorithm is refused' => static function (): void {
        $parts = explode('.', identityToken([]));
        $parts[1] = Jose::b64urlEncode(json_encode(['sub' => 'someone-else', 'aud' => 'com.diwan.pos', 'nonce' => 'nonce-1']));
        TestKit::throws(RuntimeException::class, static fn () => IdentityToken::verify(implode('.', $parts), 'nonce-1'),
            'swapped claims', 'signature');

        $none = Jose::b64urlEncode(json_encode(['alg' => 'none', 'kid' => 'test-kid'])) . '.' . $parts[1] . '.';
        TestKit::throws(RuntimeException::class, static fn () => IdentityToken::verify($none, 'nonce-1'),
            'alg none', 'header');
        TestKit::throws(RuntimeException::class, static fn () => IdentityToken::verify('not-a-jwt', 'nonce-1'),
            'malformed', 'compact JWS');
    },
];
