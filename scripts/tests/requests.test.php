<?php
declare(strict_types=1);

use Diwan\Admin\AdminAuth;
use Diwan\Admin\AdminSettings;
use Diwan\Admin\CustomerAdmin;
use Diwan\Database\Database;
use Diwan\Download\DownloadService;
use Diwan\License\ExpiryReminders;
use Diwan\License\LicenseAdmin;
use Diwan\License\LicenseRequestService;
use Diwan\License\LicenseService;
use Diwan\Support\UserFacingException;

function licenseCountFor(int $customerId): int
{
    return (int) TestKit::scalar('SELECT COUNT(*) FROM licenses WHERE customer_id = :id', ['id' => $customerId]);
}

function auditCount(string $action, string $type, int $id): int
{
    return (int) TestKit::scalar(
        'SELECT COUNT(*) FROM audit_logs WHERE action = :a AND target_type = :t AND target_id = :id',
        ['a' => $action, 't' => $type, 'id' => $id]
    );
}

return [
    'a request stays pending and issues nothing until an administrator acts' => static function (): void {
        $customer = TestKit::verifiedCustomer();
        $request = (new LicenseRequestService())->submit($customer, 'advance', ['business_name' => 'Corner Shop', 'contact_phone' => '0300 1234567']);
        TestKit::same('pending', $request['status'], 'pending');
        TestKit::same(0, licenseCountFor($customer), 'no licence before approval');
        TestKit::same('Corner Shop', TestKit::scalar('SELECT business_name FROM customers WHERE id = :id', ['id' => $customer]), 'business saved');
        TestKit::same(1, auditCount('license_request.submit', 'license_request', (int) $request['id']), 'audited');
        TestKit::check((int) TestKit::scalar("SELECT COUNT(*) FROM notifications WHERE audience = 'admin' AND type = 'license_request.new' AND id > 0") > 0, 'admins notified');
    },

    'one pending request at a time, and only verified active accounts may ask' => static function (): void {
        $service = new LicenseRequestService();
        $customer = TestKit::verifiedCustomer();
        $service->submit($customer, 'basic', []);
        TestKit::throws(UserFacingException::class, static fn () => $service->submit($customer, 'advance', []), 'second pending refused', 'already have a request');

        $unverified = TestKit::customer();
        TestKit::throws(UserFacingException::class, static fn () => $service->submit($unverified, 'basic', []), 'unverified refused', 'verify');

        $suspended = TestKit::verifiedCustomer();
        TestKit::exec("UPDATE customers SET status = 'suspended' WHERE id = :id", ['id' => $suspended]);
        TestKit::throws(UserFacingException::class, static fn () => $service->submit($suspended, 'basic', []), 'suspended refused', 'suspended');
        TestKit::throws(UserFacingException::class, static fn () => $service->submit(TestKit::verifiedCustomer(), 'enterprise', []), 'unknown plan refused');
    },

    'approval issues exactly one licence on the approved plan, even when repeated' => static function (): void {
        $customer = TestKit::verifiedCustomer();
        $service = new LicenseRequestService();
        $request = $service->submit($customer, 'basic', []);
        $admin = TestKit::admin();

        $first = $service->approve((int) $request['id'], $admin);
        TestKit::same('issued', $first['mode'], 'new licence');
        TestKit::check(preg_match('/^DIWAN-[0-9A-F]{4}(-[0-9A-F]{4}){3}$/', (string) $first['license_key']) === 1, 'key returned once, well formed');
        $again = $service->approve((int) $request['id'], $admin);
        TestKit::check($again['already'] && $again['license_key'] === null, 'second approval is a no-op without the key');
        TestKit::same($first['license_id'], $again['license_id'], 'same licence');
        TestKit::same(1, licenseCountFor($customer), 'exactly one licence');

        $license = Database::pdo()->query('SELECT l.*, o.gateway, o.status AS order_status, DATEDIFF(l.expires_at, NOW()) AS days_left
                                             FROM licenses l JOIN orders o ON o.id = l.order_id WHERE l.id = ' . (int) $first['license_id'])->fetch();
        TestKit::same((int) TestKit::scalar("SELECT id FROM plans WHERE code = 'basic'"), (int) $license['plan_id'], 'plan recorded');
        TestKit::same((int) $request['id'], (int) $license['license_request_id'], 'request recorded');
        TestKit::same((int) $admin['id'], (int) $license['issued_by'], 'approver recorded');
        TestKit::same(['admin', 'fulfilled'], [$license['gateway'], $license['order_status']], 'no payment order');
        TestKit::check(in_array((int) $license['days_left'], [29, 30], true), 'default monthly period');
        TestKit::same('unused', $license['activation_status'], 'issued, not yet activated');
        TestKit::same(null, TestKit::scalar('SELECT paid_at FROM orders WHERE id = :id', ['id' => $license['order_id']]), 'never marked paid');
        TestKit::same(1, auditCount('license_request.approve', 'license_request', (int) $request['id']), 'one audit entry');
        TestKit::check(!str_contains((string) TestKit::scalar(
            "SELECT details FROM audit_logs WHERE action = 'license_request.approve' AND target_id = :id", ['id' => $request['id']]
        ), (string) $first['license_key']), 'audit holds only the key prefix');
        TestKit::same('license.issued', TestKit::scalar(
            "SELECT type FROM notifications WHERE customer_id = :id ORDER BY id DESC LIMIT 1", ['id' => $customer]
        ), 'customer notified');
    },

    'the email copy records its real outcome, never a pretend send' => static function (): void {
        [, $customer] = TestKit::approvedPlan();
        // The test mailer points at a closed port, so delivery fails honestly.
        TestKit::same('failed', TestKit::scalar(
            "SELECT email_status FROM notifications WHERE customer_id = :id AND type = 'license.issued'", ['id' => $customer]
        ), 'failed, not sent');
    },

    'a rejected request cannot be approved, and re-requesting follows the setting' => static function (): void {
        $service = new LicenseRequestService();
        $admin = TestKit::admin();
        $customer = TestKit::verifiedCustomer();
        $request = $service->submit($customer, 'advance', []);
        $service->reject((int) $request['id'], $admin, 'Please call us first.');
        TestKit::same('rejected', $service->find((int) $request['id'])['status'], 'rejected');
        TestKit::throws(UserFacingException::class, static fn () => $service->approve((int) $request['id'], $admin), 'no approval after rejection', 'rejected');
        TestKit::same(0, licenseCountFor($customer), 'rejection issues nothing');
        TestKit::same('Please call us first.', $service->forCustomer($customer)[0]['decision_note'], 'reason shown to the customer');

        $service->submit($customer, 'basic', []);   // allowed by default
        $second = TestKit::verifiedCustomer();
        $r2 = $service->submit($second, 'basic', []);
        $service->reject((int) $r2['id'], $admin, null);
        Database::transaction(static fn (PDO $pdo) => AdminSettings::set('allow_request_after_rejection', false, (int) $admin['id'], $pdo));
        try {
            TestKit::throws(UserFacingException::class, static fn () => $service->submit($second, 'basic', []), 'refused when disabled', 'declined');
        } finally {
            Database::transaction(static fn (PDO $pdo) => AdminSettings::set('allow_request_after_rejection', true, (int) $admin['id'], $pdo));
        }
    },

    'a customer can cancel only their own pending request' => static function (): void {
        $service = new LicenseRequestService();
        $owner = TestKit::verifiedCustomer();
        $request = $service->submit($owner, 'basic', []);
        TestKit::throws(UserFacingException::class, static fn () => $service->cancel(TestKit::verifiedCustomer(), (int) $request['id']), 'not yours', 'not found');
        TestKit::same('cancelled', $service->cancel($owner, (int) $request['id'])['status'], 'cancelled');
        TestKit::throws(UserFacingException::class, static fn () => $service->approve((int) $request['id'], TestKit::admin()), 'cancelled cannot be approved');
    },

    'an upgrade moves the existing licence to the new plan and extends it, keeping the key' => static function (): void {
        [$first, $customer] = TestKit::approvedPlan('basic');
        $service = new LicenseRequestService();
        $upgrade = $service->submit($customer, 'all_in_one', []);
        $result = $service->approve((int) $upgrade['id'], TestKit::admin());
        TestKit::same('plan_changed', $result['mode'], 'moved, not duplicated');
        TestKit::same($first['license_id'], $result['license_id'], 'same licence');
        TestKit::same(null, $result['license_key'], 'no new key');
        TestKit::same(1, licenseCountFor($customer), 'still one licence');
        TestKit::same('all_in_one', TestKit::scalar('SELECT p.code FROM licenses l JOIN plans p ON p.id = l.plan_id WHERE l.id = :id', ['id' => $result['license_id']]), 'new plan');
        TestKit::check((int) TestKit::scalar('SELECT DATEDIFF(expires_at, NOW()) FROM licenses WHERE id = :id', ['id' => $result['license_id']]) >= 59, 'extended from the old expiry');
    },

    'the bound device is told its plan; a revoked, suspended or expired licence cannot be activated' => static function (): void {
        [$approved] = TestKit::approvedPlan('basic');
        $key = (string) $approved['license_key'];
        $licenses = new LicenseService();
        $device = hash('sha256', 'till-a');
        TestKit::same('activated', $licenses->activate($key, $device, 'Till A')['result'], 'activated');
        $status = $licenses->statusForDevice($key, $device);
        TestKit::same('active', $status['status'], 'active');
        TestKit::same((int) TestKit::scalar("SELECT id FROM plans WHERE code = 'basic'"), $status['plan_id'], 'plan reported');

        $admin = TestKit::admin();
        $admin2 = new LicenseAdmin();
        $admin2->apply($approved['license_id'], 'suspend', ['reason' => 'Overdue'], $admin);
        TestKit::same('suspended', $licenses->statusForDevice($key, $device)['status'], 'suspension reaches the device');
        TestKit::same('invalid_key', $licenses->activate($key, hash('sha256', 'till-b'), null)['result'], 'no activation while suspended');
        TestKit::same(null, $licenses->findActive($key), 'no downloads while suspended');
        $admin2->apply($approved['license_id'], 'reactivate', [], $admin);
        TestKit::same('active', $licenses->statusForDevice($key, $device)['status'], 'reactivated');

        TestKit::exec('UPDATE licenses SET expires_at = NOW() - INTERVAL 1 DAY WHERE id = :id', ['id' => $approved['license_id']]);
        TestKit::same('expired', $licenses->statusForDevice($key, $device)['status'], 'expired');
        $admin2->apply($approved['license_id'], 'extend', ['days' => 30], $admin);
        TestKit::same('active', $licenses->statusForDevice($key, $device)['status'], 'extended from today');

        $admin2->apply($approved['license_id'], 'revoke', ['reason' => 'Fraud'], $admin);
        TestKit::same('revoked', $licenses->statusForDevice($key, $device)['status'], 'revocation reaches the device');
        TestKit::same('invalid_key', $licenses->activate($key, hash('sha256', 'till-c'), null)['result'], 'revoked key looks invalid');
        TestKit::throws(UserFacingException::class, static fn () => $admin2->apply($approved['license_id'], 'reactivate', [], $admin), 'revocation is final', 'revoked');
        TestKit::throws(UserFacingException::class, static fn () => $admin2->apply($approved['license_id'], 'extend', ['days' => 5], $admin), 'cannot extend revoked');
        foreach (['suspend', 'reactivate', 'extend', 'revoke'] as $action) {
            TestKit::check(auditCount("license.{$action}", 'license', $approved['license_id']) >= 1, "{$action} audited");
        }
    },

    'the device limit holds, and freeing a slot lets a new device in' => static function (): void {
        [$approved] = TestKit::approvedPlan('basic');
        $key = (string) $approved['license_key'];
        $licenses = new LicenseService();
        $admin = TestKit::admin();
        $licenseAdmin = new LicenseAdmin();

        TestKit::same('activated', $licenses->activate($key, hash('sha256', 'd1'), null)['result'], 'first device');
        $refused = $licenses->activate($key, hash('sha256', 'd2'), null);
        TestKit::same(['device_mismatch', 1], [$refused['result'], $refused['max_devices']], 'limit of one');

        $licenseAdmin->apply($approved['license_id'], 'set_devices', ['max_devices' => 2], $admin);
        TestKit::same('activated', $licenses->activate($key, hash('sha256', 'd2'), null)['result'], 'second device after raising the limit');
        TestKit::same('device_mismatch', $licenses->activate($key, hash('sha256', 'd3'), null)['result'], 'third refused');

        $d1 = (int) TestKit::scalar('SELECT id FROM license_activations WHERE license_id = :id AND machine_hash = :fp',
            ['id' => $approved['license_id'], 'fp' => hash('sha256', 'd1')]);
        $licenseAdmin->apply($approved['license_id'], 'deactivate_device', ['activation_id' => $d1], $admin);
        TestKit::same('unknown', $licenses->statusForDevice($key, hash('sha256', 'd1'))['result'], 'freed device is shut out');
        TestKit::same('activated', $licenses->activate($key, hash('sha256', 'd3'), null)['result'], 'the freed slot is reusable');

        $licenseAdmin->apply($approved['license_id'], 'reset_devices', [], $admin);
        TestKit::same(0, $licenses->activeDeviceCount(Database::pdo(), $approved['license_id']), 'reset frees every slot');
    },

    'rotating a key retires the old one and keeps the plan and devices' => static function (): void {
        [$approved] = TestKit::approvedPlan('basic');
        $licenses = new LicenseService();
        $device = hash('sha256', 'rot');
        $licenses->activate((string) $approved['license_key'], $device, null);
        $rotated = (new LicenseAdmin())->apply($approved['license_id'], 'rotate_key', [], TestKit::admin());
        $newKey = (string) $rotated['license_key'];
        TestKit::check($newKey !== $approved['license_key'], 'new key');
        TestKit::same('unknown', $licenses->statusForDevice((string) $approved['license_key'], $device)['result'], 'old key dead');
        TestKit::same('already_active_same_device', $licenses->activate($newKey, $device, null)['result'], 'device keeps its slot');
        TestKit::same('active', $licenses->statusForDevice($newKey, $device)['status'], 'new key works');
    },

    'suspending an account suspends its licences and ends its sessions until reactivated' => static function (): void {
        [$approved, $customer] = TestKit::approvedPlan('basic');
        $licenses = new LicenseService();
        $device = hash('sha256', 'acct');
        $licenses->activate((string) $approved['license_key'], $device, null);
        $epoch = (int) TestKit::scalar('SELECT session_epoch FROM customers WHERE id = :id', ['id' => $customer]);
        $accounts = new CustomerAdmin();
        $admin = TestKit::admin();

        $accounts->suspend($customer, $admin, 'Chargeback');
        TestKit::same('suspended', $licenses->statusForDevice((string) $approved['license_key'], $device)['status'], 'licence suspended');
        TestKit::same($epoch + 1, (int) TestKit::scalar('SELECT session_epoch FROM customers WHERE id = :id', ['id' => $customer]), 'sessions revoked');
        TestKit::same(null, $licenses->findLicenseForDevice((string) $approved['license_key'], $device), 'cloud sync refused');
        $accounts->suspend($customer, $admin, null);   // idempotent
        $accounts->reactivate($customer, $admin, null);
        TestKit::same('active', $licenses->statusForDevice((string) $approved['license_key'], $device)['status'], 'restored');
        TestKit::same('active', TestKit::scalar('SELECT status FROM licenses WHERE id = :id', ['id' => $approved['license_id']]), 'licence row never touched');
    },

    'licences issued before plans keep every module (no plan attached)' => static function (): void {
        [, $key] = TestKit::paidLicense(TestKit::customer());
        $licenses = new LicenseService();
        $device = hash('sha256', 'legacy');
        $licenses->activate($key, $device, null);
        $status = $licenses->statusForDevice($key, $device);
        TestKit::same(['ok', 'active', null], [$status['result'], $status['status'], $status['plan_id']], 'unrestricted legacy licence');
    },

    'downloads are recorded as requested, then served; refusals as failed' => static function (): void {
        [$approved, $customer] = TestKit::approvedPlan('basic');
        $version = TestKit::RELEASE_PREFIX . bin2hex(random_bytes(3));
        TestKit::exec("INSERT INTO releases (platform, variant, version, filename, storage_path, source, is_active, released_at)
                       VALUES ('windows', 'x64', :v, 'Diwan.exe', 'missing.exe', 'local', 1, NOW() + INTERVAL 1 MINUTE)", ['v' => $version]);
        $license = (new LicenseService())->findActive((string) $approved['license_key']);
        $service = new DownloadService();
        $issued = $service->issueToken($license, 'windows', TestKit::ip(), 'x64', 'dashboard');
        $event = Database::pdo()->query('SELECT * FROM download_events WHERE customer_id = ' . $customer)->fetch();
        TestKit::same(['requested', 'dashboard', 'windows', 'x64', $version, 'active'],
            [$event['status'], $event['channel'], $event['platform'], $event['variant'], $event['version'], $event['license_status']], 'requested event');
        TestKit::same((int) $license['plan_id'], (int) $event['plan_id'], 'plan at download time');

        // The file is missing, so redemption is refused and recorded as failed — not as a download.
        TestKit::throws(Diwan\Download\DownloadDenied::class, static fn () => $service->redeem($issued['token'], TestKit::ip()), 'missing file refused');
        TestKit::same(['failed', 'file_missing'], array_values(Database::pdo()->query(
            'SELECT status, failure_reason FROM download_events WHERE customer_id = ' . $customer)->fetch()), 'failed, with the reason');

        $dir = DIWAN_PRIVATE_STORAGE . '/releases';
        file_put_contents("{$dir}/{$version}.exe", 'installer');
        TestKit::exec('UPDATE releases SET storage_path = :p WHERE version = :v', ['p' => "{$version}.exe", 'v' => $version]);
        $second = $service->issueToken($license, 'windows', TestKit::ip(), 'x64', 'licence_key');
        $service->redeem($second['token'], TestKit::ip());
        TestKit::same(['served', 'licence_key'], array_values(Database::pdo()->query(
            "SELECT status, channel FROM download_events WHERE customer_id = {$customer} ORDER BY id DESC LIMIT 1")->fetch()), 'served');

        $dash = (new Diwan\Admin\AdminQueries())->downloads(['q' => TestKit::emailOf($customer)]);
        TestKit::same(['requested' => 2, 'delivered' => 1, 'completed' => 0, 'failed' => 1, 'unique_users' => 1], $dash['summary'], 'analytics count only real downloads');
    },

    'administrator sign-in refuses wrong passwords and rate-limits' => static function (): void {
        $admin = TestKit::admin('a long admin password');
        $ip = TestKit::ip();
        TestKit::same((int) $admin['id'], (int) AdminAuth::signIn($admin['email'], 'a long admin password', $ip)['id'], 'right password');
        for ($i = 0; $i < 5; $i++) {
            TestKit::throws(UserFacingException::class, static fn () => AdminAuth::signIn($admin['email'], 'wrong', $ip), "wrong password {$i}", 'incorrect');
        }
        TestKit::throws(UserFacingException::class, static fn () => AdminAuth::signIn($admin['email'], 'a long admin password', $ip),
            'locked even with the right password', 'Too many');
        TestKit::throws(UserFacingException::class, static fn () => AdminAuth::signIn(TestKit::email(), 'whatever', TestKit::ip()), 'unknown admin', 'incorrect');
        TestKit::throws(UserFacingException::class, static fn () => AdminAuth::createOrReset(TestKit::email(), 'X', 'short', false), 'short password refused', 'at least');
        TestKit::same(0, (int) TestKit::scalar('SELECT COUNT(*) FROM admin_users WHERE email = :e', ['e' => TestKit::emailOf(TestKit::customer())]),
            'customers are never admins');
    },

    'expiry reminders go out once per licence and expiry' => static function (): void {
        [$approved, $customer] = TestKit::approvedPlan('basic');
        TestKit::exec('UPDATE licenses SET expires_at = NOW() + INTERVAL 3 DAY WHERE id = :id', ['id' => $approved['license_id']]);
        $reminders = new ExpiryReminders();
        $reminders->run();
        $reminders->run();
        TestKit::same(1, (int) TestKit::scalar("SELECT COUNT(*) FROM notifications WHERE customer_id = :id AND type = 'license.expiring'", ['id' => $customer]), 'one reminder');
        TestKit::exec('UPDATE licenses SET expires_at = NOW() - INTERVAL 1 HOUR WHERE id = :id', ['id' => $approved['license_id']]);
        $reminders->run();
        $reminders->run();
        TestKit::same(1, (int) TestKit::scalar("SELECT COUNT(*) FROM notifications WHERE customer_id = :id AND type = 'license.expired'", ['id' => $customer]), 'one expiry notice');
    },
];
