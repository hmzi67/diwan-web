<?php
declare(strict_types=1);

use Diwan\Config\Env;
use Diwan\Database\Database;
use Diwan\License\ActivationThrottle;
use Diwan\License\LicenseService;
use Diwan\License\LicenseSigner;
use Diwan\Trial\TrialService;

/** The public half the desktop app embeds, derived from the test secret key. */
function signingPublicKey(): string
{
    $secret = base64_decode(Env::require('LICENSE_SIGNING_PRIVATE_KEY'), true);
    return sodium_crypto_sign_publickey_from_secretkey($secret);
}

return [
    'a signed status payload verifies with the public key, v and kid first' => static function (): void {
        $signed  = (new LicenseSigner())->sign(['license_key' => 'DIWAN-AAAA', 'status' => 'active']);
        $json    = base64_decode($signed['payload'], true);
        $valid   = sodium_crypto_sign_verify_detached(base64_decode($signed['signature'], true), $json, signingPublicKey());
        TestKit::check($valid, 'signature verifies');
        TestKit::same(['v', 'kid', 'license_key', 'status'], array_keys(json_decode($json, true)), 'payload key order');
        TestKit::same(LicenseSigner::PAYLOAD_VERSION, json_decode($json, true)['v'], 'payload version');
        TestKit::same('test-kid', $signed['kid'], 'kid');
    },

    'a tampered status payload does not verify' => static function (): void {
        $signed  = (new LicenseSigner())->sign(['status' => 'revoked']);
        $tampered = str_replace('"revoked"', '"active"', base64_decode($signed['payload'], true));
        $valid = sodium_crypto_sign_verify_detached(base64_decode($signed['signature'], true), $tampered, signingPublicKey());
        TestKit::check(!$valid, 'tampered payload is refused');
    },

    'activation binds once and refuses a second device' => static function (): void {
        [, $key] = TestKit::paidLicense(TestKit::customer());
        $licenses = new LicenseService();
        $a = hash('sha256', 'device-a');
        TestKit::same('activated', $licenses->activate($key, $a, 'Till 1')['result'], 'first device');
        TestKit::same('already_active_same_device', $licenses->activate($key, $a, null)['result'], 'same device again');
        TestKit::same('device_mismatch', $licenses->activate($key, hash('sha256', 'device-b'), null)['result'], 'second device');
    },

    'activation normalizes case and surrounding whitespace in the key' => static function (): void {
        [, $key] = TestKit::paidLicense(TestKit::customer());
        $result = (new LicenseService())->activate('  ' . strtolower($key) . "\n", hash('sha256', 'device-n'), null);
        TestKit::same('activated', $result['result'], 'lower-cased, padded key');
    },

    'revoked, expired and unknown keys all answer invalid_key' => static function (): void {
        $licenses = new LicenseService();
        $device = hash('sha256', 'device-x');

        [$revokedOrder, $revoked] = TestKit::paidLicense(TestKit::customer());
        TestKit::exec('UPDATE licenses SET status = "revoked" WHERE order_id = :id', ['id' => $revokedOrder['id']]);
        [$expiredOrder, $expired] = TestKit::paidLicense(TestKit::customer());
        TestKit::exec('UPDATE licenses SET expires_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE order_id = :id', ['id' => $expiredOrder['id']]);

        TestKit::same('invalid_key', $licenses->activate($revoked, $device, null)['result'], 'revoked');
        TestKit::same('invalid_key', $licenses->activate($expired, $device, null)['result'], 'expired');
        TestKit::same('invalid_key', $licenses->activate('DIWAN-0000-0000-0000-0000', $device, null)['result'], 'unknown');
        TestKit::same('invalid_key', $licenses->activate('not a key', $device, null)['result'], 'malformed');
    },

    'status answers only the bound device, and reports revocation to it' => static function (): void {
        [$order, $key] = TestKit::paidLicense(TestKit::customer());
        $licenses = new LicenseService();
        $device = hash('sha256', 'device-s');
        TestKit::same('not_activated', $licenses->statusForDevice($key, $device)['result'], 'before activation');
        $licenses->activate($key, $device, null);
        TestKit::same('active', $licenses->statusForDevice($key, $device)['status'], 'bound device');
        TestKit::same('unknown', $licenses->statusForDevice($key, hash('sha256', 'other'))['result'], 'other device');
        TestKit::exec('UPDATE licenses SET status = "revoked" WHERE order_id = :id', ['id' => $order['id']]);
        TestKit::same('revoked', $licenses->statusForDevice($key, $device)['status'], 'revoked, told to the bound device');
    },

    'the activation throttle trips on failed attempts per IP and per key prefix' => static function (): void {
        $pdo = Database::pdo();
        $ip = TestKit::ip();
        $byIp = new ActivationThrottle($pdo, $ip, 'DIWAN-' . strtoupper(bin2hex(random_bytes(2))));
        for ($i = 0; $i < 9; $i++) {
            $byIp->record('invalid_key');
        }
        TestKit::check(!$byIp->isOverBudget(), '9 failures are within budget');
        $byIp->record('invalid_key');
        TestKit::check($byIp->isOverBudget(), '10 failures from one IP trip the limit');

        $prefix = 'DIWAN-' . strtoupper(bin2hex(random_bytes(2)));
        for ($i = 0; $i < 10; $i++) {
            (new ActivationThrottle($pdo, TestKit::ip(), $prefix))->record('invalid_key');
        }
        TestKit::check((new ActivationThrottle($pdo, TestKit::ip(), $prefix))->isOverBudget(),
            '10 failures on one key from rotating IPs trip the limit');
    },

    'successful activations and refreshes never consume the throttle budget' => static function (): void {
        $throttle = new ActivationThrottle(Database::pdo(), TestKit::ip(), 'DIWAN-' . strtoupper(bin2hex(random_bytes(2))));
        for ($i = 0; $i < 25; $i++) {
            $throttle->record($i % 2 ? 'activated' : 'status_active');
        }
        TestKit::check(!$throttle->isOverBudget(), 'honest traffic is never limited');
    },

    'a trial cannot be claimed again by re-registering the same email' => static function (): void {
        $email = TestKit::email();
        $first = TestKit::customer(null, $email);
        Database::transaction(static fn (PDO $pdo) => (new TrialService())->ensureForCustomer($pdo, $first));

        // The first account goes away (its email is freed), and someone signs
        // up again with the same address typed differently.
        TestKit::exec('UPDATE customers SET email = :freed WHERE id = :id', ['freed' => TestKit::email(), 'id' => $first]);
        $second = TestKit::customer(null, '  ' . strtoupper($email) . ' ');
        $result = Database::transaction(static fn (PDO $pdo) => (new TrialService())->ensureForCustomer($pdo, $second));
        TestKit::same('existing_trial', $result['status'], 'second trial for the same email');
    },

    'a trial lasts TrialService::DAYS' => static function (): void {
        $id = TestKit::customer();
        Database::transaction(static fn (PDO $pdo) => (new TrialService())->ensureForCustomer($pdo, $id));
        $lifetime = (int) TestKit::scalar(
            'SELECT TIMESTAMPDIFF(SECOND, created_at, expires_at) FROM licenses WHERE customer_id = :id', ['id' => $id]
        );
        TestKit::same(TrialService::DAYS * 86400, $lifetime, 'trial lifetime in seconds');
    },

    'only the owner can find a licence for resend' => static function (): void {
        $owner = TestKit::customer();
        [$order] = TestKit::paidLicense($owner);
        $licenseId = (int) TestKit::scalar('SELECT id FROM licenses WHERE order_id = :id', ['id' => $order['id']]);
        $licenses = new LicenseService();
        TestKit::check($licenses->findOwned($licenseId, $owner) !== null, 'owner finds it');
        TestKit::same(null, $licenses->findOwned($licenseId, TestKit::customer()), 'someone else does not');
    },
];
