<?php
declare(strict_types=1);

use Diwan\Support\Crypto;
use Diwan\Support\Logger;

return [
    'sealed values open with the same key only, and tampering is detected' => static function (): void {
        $key = random_bytes(32);
        $sealed = Crypto::seal('DIWAN-1234-5678-9ABC-DEF0', $key);
        TestKit::same('DIWAN-1234-5678-9ABC-DEF0', Crypto::open($sealed, $key), 'round trip');
        TestKit::same(null, Crypto::open($sealed, random_bytes(32)), 'wrong key');

        $raw = base64_decode($sealed, true);
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
        TestKit::same(null, Crypto::open(base64_encode($raw), $key), 'flipped ciphertext bit');
        TestKit::same(null, Crypto::open('not base64!', $key), 'garbage');
        TestKit::same(null, Crypto::open(null, $key), 'null');
    },

    'the log never holds secrets, licence keys or email addresses' => static function (): void {
        $clean = Logger::redact([
            'password'       => 'hunter2',
            'pp_SecureHash'  => 'ABC',
            'license_key'    => 'DIWAN-1234-5678-9ABC-DEF0',
            'customer_email' => 'a@b.example',
            'nested'         => ['api_key' => 'k', 'reason' => 'key DIWAN-1234-5678-9ABC-DEF0 sent to x@y.example'],
            'customer_id'    => 7,
            'passed'         => true,
        ]);
        foreach (['password', 'pp_SecureHash', 'license_key', 'customer_email'] as $key) {
            TestKit::same('[redacted]', $clean[$key], $key);
        }
        TestKit::same('[redacted]', $clean['nested']['api_key'], 'nested secret');
        TestKit::same('key DIWAN-[redacted] sent to [email]', $clean['nested']['reason'], 'free text');
        TestKit::same(7, $clean['customer_id'], 'ordinary ids are kept');
        TestKit::same(true, $clean['passed'], '"passed" is not mistaken for a password');
    },
];
