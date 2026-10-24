<?php
/**
 * End-to-end check of plan licensing over real HTTP, run by
 * scripts/http-check.sh against `php -S`:
 *
 *   php scripts/tests/http-flow.php <base-url> <app .env used by the server>
 *
 * Drives the endpoints the way the browser and the POS do — cookies, the
 * admin CSRF header, the signed licence payload — so it proves the security
 * boundary itself (a customer session cannot reach admin APIs, a POST
 * without the CSRF token is refused, nothing is issued before approval) and
 * not only the services behind it. Fixtures are TestKit's, removed at the end.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !isset($argv[2])) {
    fwrite(STDERR, "Usage: php scripts/tests/http-flow.php <base-url> <env-file>\n");
    exit(2);
}

require dirname(__DIR__, 2) . '/backend/src/bootstrap-autoload.php';
require __DIR__ . '/TestKit.php';

use Diwan\Auth\PasswordService;
use Diwan\Config\Env;

$base = rtrim($argv[1], '/');
Env::load($argv[2]);
if (!str_starts_with(Env::require('DB_NAME'), 'diwan_test_')) {
    fwrite(STDERR, "Refusing to run: DB_NAME must start with diwan_test_.\n");
    exit(2);
}

final class Client
{
    private string $jar;
    public array $headers = [];

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'diwan-jar');
    }

    /** @return array{0:int, 1:array} status and decoded body */
    public function call(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init($this->base . $path);
        $headers = $this->headers;
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR      => $this->jar,
            CURLOPT_COOKIEFILE     => $this->jar,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $body !== null ? json_encode($body) : null,
        ]);
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$status, json_decode($raw, true) ?? ['raw' => $raw]];
    }

    /** @return array{0:int, 1:string, 2:string} status, body, content type */
    public function raw(string $method, string $path, ?string $body = null): array
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => array_merge($this->headers, ['Content-Type: application/octet-stream']),
            CURLOPT_POSTFIELDS     => $body,
        ]);
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        return [$status, $raw, $type];
    }
}

$passed = 0;
$failed = 0;
function step(string $name, callable $check): void
{
    global $passed, $failed;
    try {
        $check();
        $passed++;
        echo "PASS  {$name}\n";
    } catch (Throwable $e) {
        $failed++;
        echo "FAIL  {$name}\n      {$e->getMessage()}\n";
    }
}
function expectStatus(int $want, array $response, string $what): array
{
    if ($response[0] !== $want) {
        throw new TestFailure("{$what}: HTTP {$response[0]}, expected {$want} — " . json_encode($response[1]));
    }
    return $response[1];
}

TestKit::begin();
try {
    $email = TestKit::email();
    $password = 'customer password 1';
    TestKit::exec('INSERT INTO customers (email, password_hash, password_set_at, email_verified_at, created_at)
                   VALUES (:e, :h, NOW(), NOW(), NOW())', ['e' => $email, 'h' => PasswordService::hash($password)]);
    $adminPassword = 'administrator password';
    $admin = TestKit::admin($adminPassword);

    $customer = new Client($base);
    $adminClient = new Client($base);
    $key = null;
    $fingerprint = bin2hex(random_bytes(32));

    step('the pricing API serves the three plans from the database', static function () use ($customer): void {
        $plans = expectStatus(200, $customer->call('GET', '/api/plans.php'), 'plans')['plans'];
        TestKit::same(['BASIC', 'ADVANCE', 'ALL-IN-ONE'], array_column($plans, 'name'), 'names');
        TestKit::same([1000000, 1500000, 2000000], array_column($plans, 'price_minor'), 'prices');
    });

    step('a signed-in customer can request a plan; it stays pending', static function () use ($customer, $email, $password): void {
        expectStatus(200, $customer->call('POST', '/api/sign-in.php', ['email' => $email, 'password' => $password]), 'sign in');
        $request = expectStatus(201, $customer->call('POST', '/api/license-requests.php', [
            'plan' => 'basic', 'business_name' => 'HTTP Test Shop', 'status' => 'approved', 'license_id' => 1,
        ]), 'request')['request'];
        TestKit::same('pending', $request['status'], 'mass-assigned status ignored');
        TestKit::same(0, (int) TestKit::scalar('SELECT COUNT(*) FROM licenses WHERE customer_email = :e', ['e' => $email]), 'nothing issued');
    });

    step('a customer session cannot reach any admin API', static function () use ($customer): void {
        expectStatus(401, $customer->call('GET', '/api/admin-license-requests.php'), 'list requests');
        expectStatus(401, $customer->call('POST', '/api/admin-license-requests.php', ['id' => 1, 'action' => 'approve']), 'approve');
        expectStatus(401, $customer->call('POST', '/api/admin-licenses.php', ['id' => 1, 'action' => 'change_plan', 'plan_id' => 3]), 'change plan');
        expectStatus(401, $customer->call('GET', '/api/admin-downloads.php?format=csv'), 'CSV export');
    });

    $requestId = (int) TestKit::scalar('SELECT r.id FROM license_requests r JOIN customers c ON c.id = r.customer_id WHERE c.email = :e', ['e' => $email]);

    step('admin sign-in issues a session, and writes without the CSRF token are refused', static function () use ($adminClient, $admin, $adminPassword, $requestId): void {
        expectStatus(401, $adminClient->call('POST', '/api/admin-login.php', ['email' => $admin['email'], 'password' => 'wrong']), 'wrong password');
        $login = expectStatus(200, $adminClient->call('POST', '/api/admin-login.php', ['email' => $admin['email'], 'password' => $adminPassword]), 'sign in');
        TestKit::check(strlen((string) $login['csrf_token']) === 64, 'csrf token issued');
        expectStatus(403, $adminClient->call('POST', '/api/admin-license-requests.php', ['id' => $requestId, 'action' => 'approve']), 'no token');
        $adminClient->headers = ['X-CSRF-Token: ' . str_repeat('0', 64)];
        expectStatus(403, $adminClient->call('POST', '/api/admin-license-requests.php', ['id' => $requestId, 'action' => 'approve']), 'wrong token');
        $adminClient->headers = ['X-CSRF-Token: ' . $login['csrf_token']];
        TestKit::same('pending', TestKit::scalar('SELECT status FROM license_requests WHERE id = :id', ['id' => $requestId]), 'still pending');
    });

    step('the admin sees the request and approves it once', static function () use ($adminClient, $requestId, &$key): void {
        $list = expectStatus(200, $adminClient->call('GET', '/api/admin-license-requests.php?status=pending'), 'list');
        TestKit::check(in_array($requestId, array_map('intval', array_column($list['items'], 'id')), true), 'listed');
        $approved = expectStatus(200, $adminClient->call('POST', '/api/admin-license-requests.php', ['id' => $requestId, 'action' => 'approve']), 'approve');
        $key = (string) $approved['license_key'];
        TestKit::same('issued', $approved['mode'], 'issued');
        $again = expectStatus(200, $adminClient->call('POST', '/api/admin-license-requests.php', ['id' => $requestId, 'action' => 'approve']), 'approve again');
        TestKit::check($again['already'] === true && $again['license_key'] === null, 'idempotent');
        $dash = expectStatus(200, $adminClient->call('GET', '/api/admin-dashboard.php'), 'dashboard');
        TestKit::check($dash['kpis']['licenses_issued'] >= 1, 'dashboard counts the licence');
    });

    step("the customer's dashboard shows the plan and the notification", static function () use ($customer): void {
        $data = expectStatus(200, $customer->call('GET', '/api/dashboard-data.php'), 'dashboard');
        TestKit::same('BASIC', $data['plan']['current']['plan_name'], 'plan');
        TestKit::same('approved', $data['plan']['requests'][0]['status'], 'request approved');
        TestKit::check($data['notifications']['unread'] >= 1, 'unread notification');
    });

    step('the POS activates the key and receives a signed payload naming the plan and its entitlements', static function () use ($customer, &$key, $fingerprint): void {
        expectStatus(200, $customer->call('POST', '/api/activate-license.php', ['license_key' => $key, 'machine_fingerprint' => $fingerprint, 'machine_hint' => 'HTTP flow']), 'activate');
        $signed = expectStatus(200, $customer->call('POST', '/api/license-status.php', ['license_key' => $key, 'machine_fingerprint' => $fingerprint]), 'status');
        $json = base64_decode($signed['payload'], true);
        $public = sodium_crypto_sign_publickey_from_secretkey(base64_decode(Env::require('LICENSE_SIGNING_PRIVATE_KEY'), true));
        TestKit::check(sodium_crypto_sign_verify_detached(base64_decode($signed['signature'], true), $json, $public), 'signature verifies');
        $payload = json_decode($json, true);
        TestKit::same(['code' => 'basic', 'name' => 'BASIC'], $payload['plan'], 'plan');
        TestKit::check(in_array('billing', $payload['entitlements'], true) && !in_array('multi_user', $payload['entitlements'], true), 'BASIC entitlements');
        TestKit::same('ADVANCE', $payload['upgrade']['multi_user'] ?? null, 'upgrade hint');
        TestKit::same('active', $payload['status'], 'active');
    });

    step('the plan gates the Android installer, and cannot be changed by the customer', static function () use ($customer, &$key, $email): void {
        $refused = expectStatus(403, $customer->call('POST', '/api/issue-download.php', ['license_key' => $key, 'platform' => 'android']), 'android');
        TestKit::check(str_contains((string) $refused['error'], 'ALL-IN-ONE'), 'names the plan that has it');
        TestKit::same('failed', TestKit::scalar('SELECT e.status FROM download_events e JOIN customers c ON c.id = e.customer_id WHERE c.email = :e ORDER BY e.id DESC LIMIT 1', ['e' => $email]), 'refusal recorded');
        expectStatus(201, $customer->call('POST', '/api/license-requests.php', ['plan' => 'all_in_one']), 'asking for an upgrade');
        TestKit::same('basic', TestKit::scalar('SELECT p.code FROM licenses l JOIN plans p ON p.id = l.plan_id WHERE l.customer_email = :e', ['e' => $email]), 'still BASIC until approved');
    });

    $pos = new Client($base);
    step('a counter connects to its business and syncs with its own token; the token alone decides the business', static function () use ($pos, $base, $adminClient, $customer, &$key, $fingerprint): void {
        $reg = expectStatus(200, $pos->call('POST', '/api/pos-terminal-register.php', [
            'license_key' => $key, 'machine_fingerprint' => $fingerprint, 'install_id' => 'abcdef0123456789', 'terminal_name' => 'HTTP counter',
        ]), 'register');
        TestKit::check(str_starts_with($reg['token'], 'dwt_'), 'a counter token');
        TestKit::same(false, $reg['business']['has_data'], 'a new, empty business');
        $pos->headers = ['X-Terminal-Token: ' . $reg['token']];
        expectStatus(200, $pos->call('POST', '/api/pos-workspace.php', ['action' => 'claim_seed']), 'claim the first upload');
        $push = expectStatus(200, $pos->call('POST', '/api/pos-sync-push.php', [
            'changes' => [['id' => 1, 'entity' => 'items', 'gid' => 'abcdef0123456789:1', 'op' => 'upsert', 'data' => ['name' => 'HTTP Tea', 'price_minor' => 100]]],
            'stock' => [['key' => 'open:abcdef0123456789:1', 'item_gid' => 'abcdef0123456789:1', 'qty' => 10]],
            'releases' => [],
        ]), 'push');
        TestKit::same([1], $push['accepted'], 'change accepted');
        expectStatus(200, $pos->call('POST', '/api/pos-workspace.php', ['action' => 'complete_seed']), 'finish the upload');
        $pull = expectStatus(200, $pos->call('GET', '/api/pos-sync-pull.php?since=0&pending=0&error='), 'pull');
        TestKit::same('HTTP Tea', $pull['changes'][0]['data']['name'], 'the product comes back');
        TestKit::same(10.0, (float) $pull['stock']['abcdef0123456789:1'], 'with its central stock');
        TestKit::same('ok', expectStatus(200, $pos->call('POST', '/api/pos-stock-reserve.php', ['key' => 'sale:http:000001', 'lines' => [['item_gid' => 'abcdef0123456789:1', 'qty' => 3]]]), 'reserve')['status'], 'reserved');
        TestKit::same('insufficient', expectStatus(200, $pos->call('POST', '/api/pos-stock-reserve.php', ['key' => 'sale:http:000002', 'lines' => [['item_gid' => 'abcdef0123456789:1', 'qty' => 50]]]), 'over-reserve')['status'], 'refused');

        $stranger = new Client($base);
        TestKit::same('unauthenticated', expectStatus(401, $stranger->call('GET', '/api/pos-sync-pull.php?since=0'), 'no token')['code'], 'no token, no data');
        $stranger->headers = ['X-Terminal-Token: dwt_' . str_repeat('x', 43)];
        expectStatus(401, $stranger->call('POST', '/api/pos-sync-push.php', ['changes' => [], 'stock' => [], 'releases' => []]), 'made-up token');
        $customer->headers = [];
        expectStatus(401, $customer->call('GET', '/api/admin-businesses.php'), 'a customer cannot list businesses');
        $list = expectStatus(200, $adminClient->call('GET', '/api/admin-businesses.php?q=' . urlencode(substr($key, 0, 11))), 'admin list');
        TestKit::same(1, (int) $list['items'][0]['counters'], 'the admin sees the counter');
        $load = expectStatus(200, $adminClient->call('GET', '/api/admin-businesses.php?load=1'), 'admin load panel')['load'];
        TestKit::check($load['this_hour'] >= 4 && isset($load['by_endpoint']['push'], $load['by_endpoint']['pull']), 'sync requests are counted per endpoint');
    });

    step('a product photo is uploaded once and served only to the business\'s counters', static function () use ($pos, $base): void {
        $image = imagecreatetruecolor(12, 8);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        $name = 'item-' . str_repeat('cd', 16) . '.png';
        TestKit::same([$name], expectStatus(200, $pos->call('POST', '/api/pos-media.php', ['names' => [$name]]), 'check')['missing'], 'missing at first');
        [$status, $body] = $pos->raw('POST', '/api/pos-media-upload.php?name=' . $name, $png);
        TestKit::same(200, $status, 'upload: ' . $body);
        TestKit::same(true, json_decode($body, true)['stored'], 'stored');
        TestKit::same([], expectStatus(200, $pos->call('POST', '/api/pos-media.php', ['names' => [$name]]), 'check again')['missing'], 'present now');
        [$status, $bytes, $type] = $pos->raw('GET', '/api/pos-media.php?name=' . $name);
        TestKit::same([200, 'application/octet-stream'], [$status, $type], 'served as a download, never rendered');
        TestKit::same(IMAGETYPE_PNG, getimagesizefromstring($bytes)[2] ?? null, 'the image comes back');

        $stranger = new Client($base);
        TestKit::same(401, $stranger->raw('GET', '/api/pos-media.php?name=' . $name)[0], 'no token, no photo');
        TestKit::same(422, $pos->raw('POST', '/api/pos-media-upload.php?name=evil.png', '<?php phpinfo();')[0], 'a non-image is refused');
        TestKit::check((int) TestKit::scalar("SELECT COALESCE(SUM(requests), 0) FROM sync_load WHERE endpoint = 'media' AND hour_start >= NOW() - INTERVAL 2 HOUR") >= 4,
            'media requests are counted for the load panel');

        $business = (int) TestKit::scalar('SELECT business_id FROM sync_media WHERE name = :n ORDER BY created_at DESC LIMIT 1', ['n' => $name]);
        $dir = rtrim(Diwan\Config\Env::get('PRIVATE_STORAGE_PATH', dirname(__DIR__, 2) . '/backend/private-storage'), '/') . "/media/{$business}";
        array_map('unlink', glob($dir . '/*') ?: []);
        @rmdir($dir);
    });

    step('a suspended licence reaches the device and stops its sync; sign-out ends the admin session', static function () use ($adminClient, $customer, $pos, &$key, $fingerprint, $email): void {
        $licenseId = (int) TestKit::scalar('SELECT id FROM licenses WHERE customer_email = :e', ['e' => $email]);
        expectStatus(200, $adminClient->call('POST', '/api/admin-licenses.php', ['id' => $licenseId, 'action' => 'suspend', 'reason' => 'HTTP flow']), 'suspend');
        $signed = expectStatus(200, $customer->call('POST', '/api/license-status.php', ['license_key' => $key, 'machine_fingerprint' => $fingerprint]), 'status');
        TestKit::same('suspended', json_decode(base64_decode($signed['payload'], true), true)['status'], 'suspended in the payload');
        TestKit::same('license_inactive', expectStatus(403, $pos->call('GET', '/api/pos-sync-pull.php?since=0'), 'counter sync')['code'], 'sync stops too');
        expectStatus(200, $adminClient->call('POST', '/api/admin-logout.php', []), 'sign out');
        expectStatus(401, $adminClient->call('GET', '/api/admin-session.php'), 'session gone');
    });
} finally {
    TestKit::cleanup();
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
