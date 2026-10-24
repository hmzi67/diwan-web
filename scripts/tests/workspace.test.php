<?php
declare(strict_types=1);

use Diwan\License\LicenseService;
use Diwan\Workspace\MediaStore;
use Diwan\Workspace\SyncEngine;
use Diwan\Workspace\TerminalRegistry;
use Diwan\Workspace\WorkspaceAdmin;
use Diwan\Workspace\WorkspaceException;

/** A licence that may run on `$devices` devices: [customer id, raw key, licence id]. */
function workspaceLicense(int $devices = 3): array
{
    $customerId = TestKit::customer();
    [$order, $key] = TestKit::paidLicense($customerId);
    $licenseId = (int) TestKit::scalar('SELECT id FROM licenses WHERE order_id = :o', ['o' => $order['id']]);
    TestKit::exec('UPDATE licenses SET max_devices = :d WHERE id = :id', ['d' => $devices, 'id' => $licenseId]);
    return [$customerId, $key, $licenseId];
}

/**
 * Activates a fresh device on the licence and connects it as a counter.
 * Returns ['token', 'fp' (raw fingerprint), 'install', 'business_id'].
 */
function counterOn(string $key, ?string $name = null, ?string $fingerprint = null, ?string $installId = null): array
{
    $fingerprint ??= hash('sha256', 'device-' . bin2hex(random_bytes(6)));
    $installId ??= bin2hex(random_bytes(8));
    $activated = (new LicenseService())->activate($key, hash('sha256', $fingerprint), $name);
    TestKit::check(in_array($activated['result'], ['activated', 'already_active_same_device'], true), 'device activated: ' . $activated['result']);
    $registered = (new TerminalRegistry())->register($key, hash('sha256', $fingerprint), $installId, $name, 'test', '1.0.0');
    return ['token' => $registered['token'], 'fp' => $fingerprint, 'install' => $installId, 'business_id' => $registered['business']['id']];
}

/** The counter's current auth context (joined state changes as it seeds/joins). */
function ctxOf(array $counter): array
{
    return (new TerminalRegistry())->authenticate($counter['token']);
}

/** A counter that started the workspace with an item in stock. */
function seededCounter(string $key, string $itemGid, float $stock, string $name = 'Counter 1'): array
{
    $counter = counterOn($key, $name);
    $registry = new TerminalRegistry();
    $registry->claimSeed(ctxOf($counter));
    pushTo($counter, [row('items', $itemGid, ['name' => 'Cola', 'price_minor' => 15000])], [stockChange("open:{$itemGid}", $itemGid, $stock)]);
    $registry->completeSeed(ctxOf($counter));
    return $counter;
}

function joinedCounter(string $key, string $name = 'Counter 2'): array
{
    $counter = counterOn($key, $name);
    (new TerminalRegistry())->markJoined(ctxOf($counter));
    return $counter;
}

function row(string $entity, string $gid, array $data): array
{
    static $id = 0;
    return ['id' => ++$id, 'entity' => $entity, 'gid' => $gid, 'op' => 'upsert', 'data' => $data];
}

function stockChange(string $key, string $itemGid, float $qty): array
{
    return ['key' => $key, 'item_gid' => $itemGid, 'qty' => $qty];
}

function pushTo(array $counter, array $changes, array $stock = [], array $releases = []): array
{
    return (new SyncEngine())->push(ctxOf($counter), ['changes' => $changes, 'stock' => $stock, 'releases' => $releases, 'pending' => 0]);
}

/** Everything the counter would download from scratch: [entity:gid => data], [item gid => qty]. */
function everything(array $counter): array
{
    $rows = [];
    $stock = [];
    $since = 0;
    do {
        $page = (new SyncEngine())->pull(ctxOf($counter), $since, 7);
        foreach ($page['changes'] as $change) {
            $rows["{$change['entity']}:{$change['gid']}"] = $change['deleted'] ? null : $change['data'];
        }
        foreach ((array) $page['stock'] as $gid => $qty) {
            $stock[$gid] = $qty;
        }
        $since = $page['cursor'];
    } while ($page['more']);
    return [array_filter($rows, static fn ($r) => $r !== null), $stock];
}

function balance(int $businessId, string $itemGid): float
{
    return (float) TestKit::scalar('SELECT qty FROM sync_stock WHERE business_id = :b AND item_gid = :i', ['b' => $businessId, 'i' => $itemGid]);
}

function pngBytes(int $w = 20, int $h = 10): string
{
    $image = imagecreatetruecolor($w, $h);
    imagefilledrectangle($image, 0, 0, $w - 1, $h - 1, imagecolorallocate($image, 200, 40, 40));
    ob_start();
    imagepng($image);
    return (string) ob_get_clean();
}

function mediaRoot(): string
{
    return sys_get_temp_dir() . '/diwan-media-test-' . bin2hex(random_bytes(4));
}

/** The business's current version: what a counter that is up to date has as its cursor. */
function headOf(array $counter): int
{
    return (int) TestKit::scalar('SELECT sync_version FROM businesses WHERE id = :b', ['b' => $counter['business_id']]);
}

return [
    'scenario A: a product added on one counter appears on another with the same stock' => static function (): void {
        [, $key] = workspaceLicense(2);
        $first = seededCounter($key, 'aaaa000000000001:1', 100);
        $second = joinedCounter($key);
        TestKit::same($first['business_id'], $second['business_id'], 'one business for both counters');

        [$rows, $stock] = everything($second);
        TestKit::same('Cola', $rows['items:aaaa000000000001:1']['name'] ?? null, 'counter 2 sees the product');
        TestKit::same(100.0, $stock['aaaa000000000001:1'] ?? null, 'counter 2 sees its stock');

        // A price change on counter 2 reaches counter 1.
        pushTo($second, [row('items', 'aaaa000000000001:1', ['name' => 'Cola', 'price_minor' => 16000])]);
        [$rows] = everything($first);
        TestKit::same(16000, $rows['items:aaaa000000000001:1']['price_minor'], 'counter 1 sees the new price');
    },

    'scenario B: a sale is in the shared records and its stock is deducted exactly once, even when retried' => static function (): void {
        [, $key] = workspaceLicense(2);
        $first = seededCounter($key, 'bbbb000000000001:1', 100);
        $second = joinedCounter($key);

        $sale = [
            row('sales', 'bbbb000000000001:7', ['total_minor' => 45000, 'payment_method' => 'cash', 'created_at' => '2026-10-24 12:00:00', 'cashier_id' => 'bbbb000000000001:1']),
            row('sale_items', 'bbbb000000000001:9', ['sale_id' => 'bbbb000000000001:7', 'item_id' => 'bbbb000000000001:1', 'qty' => 3, 'price_at_sale_minor' => 15000]),
        ];
        $change = stockChange('d:bbbb000000000001:41', 'bbbb000000000001:1', -3);
        pushTo($first, $sale, [$change]);
        $retry = pushTo($first, $sale, [$change]);   // the reply was lost: the counter sends it all again
        TestKit::same(['d:bbbb000000000001:41'], $retry['stock_accepted'], 'the retry is acknowledged');

        TestKit::same(97.0, balance($first['business_id'], 'bbbb000000000001:1'), 'deducted once');
        [$rows, $stock] = everything($second);
        TestKit::same(45000, $rows['sales:bbbb000000000001:7']['total_minor'] ?? null, 'counter 2 sees the sale');
        TestKit::same(97.0, $stock['bbbb000000000001:1'], 'counter 2 sees the deducted stock');
        TestKit::same(1, (int) TestKit::scalar('SELECT COUNT(*) FROM business_sales WHERE business_id = :b', ['b' => $first['business_id']]), 'one sale in the totals');
    },

    'scenario C: concurrent sales on two counters both land, and the last unit cannot be sold twice' => static function (): void {
        [, $key] = workspaceLicense(2);
        $first = seededCounter($key, 'cccc000000000001:1', 100);
        $second = joinedCounter($key);
        $engine = new SyncEngine();

        TestKit::same('ok', $engine->reserve(ctxOf($first), 'sale:cccc:0001', [['item_gid' => 'cccc000000000001:1', 'qty' => 5]])['status'], 'counter 1 takes 5');
        TestKit::same('ok', $engine->reserve(ctxOf($second), 'sale:dddd:0001', [['item_gid' => 'cccc000000000001:1', 'qty' => 3]])['status'], 'counter 2 takes 3');
        TestKit::same(92.0, balance($first['business_id'], 'cccc000000000001:1'), '100 - 5 - 3');

        // Each sale's own stock changes arrive later under its reservation key: not applied again.
        pushTo($first, [], [stockChange('sale:cccc:0001', 'cccc000000000001:1', -5)]);
        pushTo($second, [], [stockChange('sale:dddd:0001', 'cccc000000000001:1', -3)]);
        TestKit::same(92.0, balance($first['business_id'], 'cccc000000000001:1'), 'still 92 after both sales sync');

        // One unit left: the first counter gets it, the second is told no and nothing is taken.
        pushTo($first, [row('items', 'cccc000000000001:2', ['name' => 'Last cake'])], [stockChange('open:cccc:2', 'cccc000000000001:2', 1)]);
        TestKit::same('ok', $engine->reserve(ctxOf($first), 'sale:cccc:0002', [['item_gid' => 'cccc000000000001:2', 'qty' => 1]])['status'], 'first buyer');
        $refused = $engine->reserve(ctxOf($second), 'sale:dddd:0002', [
            ['item_gid' => 'cccc000000000001:1', 'qty' => 1], ['item_gid' => 'cccc000000000001:2', 'qty' => 1],
        ]);
        TestKit::same('insufficient', $refused['status'], 'second buyer refused');
        TestKit::same(0.0, $refused['shortages'][0]['available'], 'told what is left');
        TestKit::same(92.0, balance($first['business_id'], 'cccc000000000001:1'), 'all or nothing: the other line was not taken');

        // A sale that fails on the counter after reserving gives the stock back, once.
        $engine->reserve(ctxOf($first), 'sale:cccc:0003', [['item_gid' => 'cccc000000000001:1', 'qty' => 2]]);
        TestKit::same(90.0, balance($first['business_id'], 'cccc000000000001:1'), 'reserved');
        pushTo($second, [], [], ['sale:cccc:0003']);
        TestKit::same(90.0, balance($first['business_id'], 'cccc000000000001:1'), 'another counter cannot release it');
        pushTo($first, [], [], ['sale:cccc:0003']);
        pushTo($first, [], [], ['sale:cccc:0003']);
        TestKit::same(92.0, balance($first['business_id'], 'cccc000000000001:1'), 'released exactly once');

        $again = $engine->reserve(ctxOf($first), 'sale:cccc:0001', [['item_gid' => 'cccc000000000001:1', 'qty' => 5]]);
        TestKit::same(['cccc000000000001:1' => 5.0], (array) $again['taken'], 'a retried reservation gets the first answer');
        TestKit::same(92.0, balance($first['business_id'], 'cccc000000000001:1'), 'and takes nothing more');
    },

    'scenario D: a reinstalled counter keeps its business but reloads the business data before pushing' => static function (): void {
        [, $key] = workspaceLicense(2);
        $first = seededCounter($key, 'eeee000000000001:1', 10);

        // Same device, fresh database (new install id), e.g. after a reinstall.
        $again = counterOn($key, null, $first['fp']);
        TestKit::same($first['business_id'], $again['business_id'], 'same business');
        TestKit::same(1, (int) TestKit::scalar('SELECT COUNT(*) FROM pos_terminals WHERE business_id = :b', ['b' => $first['business_id']]), 'still one counter');
        TestKit::throws(WorkspaceException::class, static fn () => ctxOf($first), 'the old token stopped working', 'not connected');
        TestKit::throws(WorkspaceException::class, static fn () => pushTo($again, [row('items', 'ffff000000000001:1', ['name' => 'Stray'])]),
            'a fresh database cannot push before it holds the business data', 'not loaded the business data');

        [$rows, $stock] = everything($again);
        TestKit::same('Cola', $rows['items:eeee000000000001:1']['name'], 'existing products are still there');
        TestKit::same(10.0, $stock['eeee000000000001:1'], 'and their stock');
        (new TerminalRegistry())->markJoined(ctxOf($again));
        pushTo($again, [row('items', $again['install'] . ':1', ['name' => 'New'])]);
        TestKit::same(2, (int) TestKit::scalar("SELECT COUNT(*) FROM sync_rows WHERE business_id = :b AND entity = 'items'", ['b' => $first['business_id']]), 'pushes once joined');
    },

    'joining is explicit: one seed per business, no joining mid-seed, no pushing before joining' => static function (): void {
        [, $key] = workspaceLicense(3);
        $registry = new TerminalRegistry();
        $first = counterOn($key, 'Counter 1');
        $second = counterOn($key, 'Counter 2');
        $registry->claimSeed(ctxOf($first));
        $registry->claimSeed(ctxOf($first));   // a retry is fine
        TestKit::throws(WorkspaceException::class, static fn () => $registry->claimSeed(ctxOf($second)), 'second seed refused', 'already has data');
        TestKit::throws(WorkspaceException::class, static fn () => $registry->markJoined(ctxOf($second)), 'cannot join mid-seed', 'still being uploaded');
        TestKit::throws(WorkspaceException::class, static fn () => pushTo($second, [row('items', 'abcd000000000001:1', ['name' => 'x'])]),
            'cannot push before joining', 'not loaded');
        $registry->completeSeed(ctxOf($first));
        TestKit::throws(WorkspaceException::class, static fn () => $registry->completeSeed(ctxOf($second)), 'only the seeder completes', 'Only the counter');
        $registry->markJoined(ctxOf($second));
        TestKit::check(ctxOf($second)['joined'], 'joined after the seed completed');
        $copy = counterOn($key, 'Copy');
        TestKit::throws(WorkspaceException::class, static fn () => $registry->register($key, hash('sha256', $copy['fp']), $first['install'], null, null, null),
            'a copied installation cannot register as a second counter', 'is a copy of Counter 1');
    },

    'scenario F: a counter only ever reads and writes its own business' => static function (): void {
        [, $keyA] = workspaceLicense(1);
        [, $keyB] = workspaceLicense(1);
        $a = seededCounter($keyA, 'shared0000000001:1', 50);
        $b = seededCounter($keyB, 'shared0000000001:1', 7);
        TestKit::check($a['business_id'] !== $b['business_id'], 'two businesses');

        // The same gid in both businesses stays two separate products.
        pushTo($b, [row('items', 'shared0000000001:1', ['name' => 'B only'])] , [], []);
        $hostile = (new SyncEngine())->push(ctxOf($b), [
            'business_id' => $a['business_id'], 'customer_id' => 1,
            'changes' => [row('items', 'shared0000000001:2', ['name' => 'Injected'])], 'stock' => [stockChange('d:evil:1', 'shared0000000001:1', -50)],
        ]);
        TestKit::same(1, count($hostile['accepted']), 'accepted — into its own business');
        [$rowsA, $stockA] = everything($a);
        TestKit::same('Cola', $rowsA['items:shared0000000001:1']['name'], "A's product untouched");
        TestKit::check(!isset($rowsA['items:shared0000000001:2']), "B's write did not land in A");
        TestKit::same(50.0, $stockA['shared0000000001:1'], "A's stock untouched");
        TestKit::same(-43.0, balance($b['business_id'], 'shared0000000001:1'), "B's change hit B's own stock");
        TestKit::same(0, count((array) (new SyncEngine())->reserve(ctxOf($b), 'sale:evil:0001', [['item_gid' => 'nope000000000001:1', 'qty' => 1]])['taken']),
            'an unknown item reserves nothing');

        $registry = new TerminalRegistry();
        TestKit::throws(WorkspaceException::class, static fn () => $registry->authenticate('dwt_' . str_repeat('A', 43)), 'a made-up token', 'not connected');
        TestKit::throws(WorkspaceException::class, static fn () => $registry->authenticate(null), 'no token', 'not connected');
        TestKit::throws(WorkspaceException::class, static fn () => $registry->register($keyA, hash('sha256', $b['fp']), bin2hex(random_bytes(8)), null, null, null),
            "B's device cannot join A's business with A's key", 'not activated');
    },

    'scenario G: an offline sale that oversold is applied and flagged, never dropped or applied twice' => static function (): void {
        [, $key] = workspaceLicense(2);
        $first = seededCounter($key, 'gggg000000000001:1', 2);
        $second = joinedCounter($key);
        $offline = stockChange('d:' . $second['install'] . ':12', 'gggg000000000001:1', -3);
        pushTo($second, [], [$offline]);
        pushTo($second, [], [$offline]);
        TestKit::same(-1.0, balance($first['business_id'], 'gggg000000000001:1'), 'applied once: 2 - 3');
        TestKit::same(1, (int) TestKit::scalar(
            "SELECT COUNT(*) FROM sync_conflicts WHERE business_id = :b AND kind = 'negative_stock' AND gid = 'gggg000000000001:1'",
            ['b' => $first['business_id']]
        ), 'flagged once for review');
    },

    'the counter limit is the licence device limit, and revoking a counter or the licence stops its sync' => static function (): void {
        [$customerId, $key, $licenseId] = workspaceLicense(1);
        $only = seededCounter($key, 'hhhh000000000001:1', 1);
        $extra = hash('sha256', 'another-device');
        TestKit::same('device_mismatch', (new LicenseService())->activate($key, hash('sha256', $extra), null)['result'], 'no second device on a one-device licence');
        TestKit::throws(WorkspaceException::class, static fn () => (new TerminalRegistry())->register($key, hash('sha256', $extra), bin2hex(random_bytes(8)), null, null, null),
            'nor a second counter', 'not activated');

        TestKit::exec("UPDATE licenses SET status = 'suspended' WHERE id = :id", ['id' => $licenseId]);
        TestKit::throws(WorkspaceException::class, static fn () => ctxOf($only), 'suspended licence', 'not active');
        TestKit::exec("UPDATE licenses SET status = 'active' WHERE id = :id", ['id' => $licenseId]);
        TestKit::exec("UPDATE customers SET status = 'suspended' WHERE id = :id", ['id' => $customerId]);
        TestKit::throws(WorkspaceException::class, static fn () => ctxOf($only), 'suspended account', 'not active');
        TestKit::exec("UPDATE customers SET status = 'active' WHERE id = :id", ['id' => $customerId]);
        ctxOf($only);

        TestKit::exec('UPDATE license_activations SET deactivated_at = NOW() WHERE license_id = :id', ['id' => $licenseId]);
        TestKit::throws(WorkspaceException::class, static fn () => ctxOf($only), 'freed slot', 'disconnected');
        TestKit::same(1, (int) TestKit::scalar("SELECT COUNT(*) FROM sync_rows WHERE business_id = :b", ['b' => $only['business_id']]), 'its data stays');
    },

    'pull pages through every change in order without skipping any' => static function (): void {
        [, $key] = workspaceLicense(1);
        $counter = seededCounter($key, 'iiii000000000001:1', 0);
        $rows = [];
        for ($i = 2; $i <= 26; $i++) {
            $rows[] = row('customers', "iiii000000000001:{$i}", ['name' => "Customer {$i}"]);
        }
        pushTo($counter, $rows);
        [$all] = everything($counter);   // pages of 7
        TestKit::same(25, count(array_filter(array_keys($all), static fn ($k) => str_starts_with($k, 'customers:'))), 'all 25 customers');
        $last = (new SyncEngine())->pull(ctxOf($counter), 0, 1000);
        TestKit::same(false, $last['more'], 'one big page is complete');
        TestKit::same($last['head'], $last['cursor'], 'cursor at head');
        $empty = (new SyncEngine())->pull(ctxOf($counter), $last['cursor'], 10);
        TestKit::same([], $empty['changes'], 'nothing after the head');
    },

    'a bad change is refused on its own and logged; the rest of the push lands' => static function (): void {
        [, $key] = workspaceLicense(1);
        $counter = seededCounter($key, 'jjjj000000000001:1', 0);
        $bad = row('license_status', 'jjjj000000000001:1', ['license_key' => 'x']);   // not a shared table
        $good = row('expenses', 'jjjj000000000001:2', ['amount_minor' => 500]);
        $result = pushTo($counter, [$bad, $good]);
        TestKit::same([$good['id']], $result['accepted'], 'good change accepted');
        TestKit::same($bad['id'], $result['rejected'][0]['id'] ?? null, 'bad change rejected by id');
        TestKit::same(1, (int) TestKit::scalar(
            "SELECT COUNT(*) FROM sync_conflicts WHERE business_id = :b AND kind = 'rejected_change'", ['b' => $counter['business_id']]
        ), 'logged for the admin panel');
        $deleted = pushTo($counter, [['id' => 99999, 'entity' => 'expenses', 'gid' => 'jjjj000000000001:2', 'op' => 'delete']]);
        TestKit::same([99999], $deleted['accepted'], 'deletes are accepted');
        [$rows] = everything($counter);
        TestKit::check(!isset($rows['expenses:jjjj000000000001:2']), 'and reach other counters as deletions');
    },
    'admin: figures per business and counter, disconnecting a counter, and no record contents' => static function (): void {
        [, $key, $licenseId] = workspaceLicense(2);
        $first = seededCounter($key, 'kkkk000000000001:1', 10);
        $second = joinedCounter($key, 'Takeaway');
        pushTo($first, [
            row('users', 'kkkk000000000001:9', ['name' => 'Sana', 'pin_hash' => '$argon2id$secret', 'role' => 'cashier']),
            row('sales', $first['install'] . ':20', ['total_minor' => 1500, 'payment_method' => 'cash', 'created_at' => date('Y-m-d H:i:s')]),
        ], [stockChange('d:kkkk:1', 'kkkk000000000001:1', -12)]);
        pushTo($second, [row('sales', $second['install'] . ':3', ['total_minor' => 700, 'payment_method' => 'card', 'created_at' => date('Y-m-d H:i:s')])]);

        $admin = TestKit::admin();
        $workspaces = new WorkspaceAdmin();
        $list = $workspaces->businesses(['q' => (string) TestKit::scalar('SELECT license_key_prefix FROM licenses WHERE id = :l', ['l' => $licenseId])]);
        TestKit::same(1, $list['total'], 'found by key prefix');
        $row = $list['items'][0];
        TestKit::same([2, 2200, 1, 1], [(int) $row['counters'], (int) $row['sales_total_minor'], (int) $row['products'], (int) $row['open_conflicts']],
            'counters, server-side sales, products, conflicts');

        $detail = $workspaces->detail($first['business_id']);
        $bySales = array_column($detail['terminals'], 'sales_total_minor', 'name');
        TestKit::same(['Counter 1' => 1500, 'Takeaway' => 700], array_map('intval', $bySales), 'sales split by counter');
        TestKit::check(!str_contains(json_encode($detail), 'argon2'), 'no PIN hash or record contents in the admin view');

        $takeaway = array_values(array_filter($detail['terminals'], static fn ($t) => $t['name'] === 'Takeaway'))[0];
        $workspaces->apply($first['business_id'], 'revoke_terminal', ['terminal_id' => $takeaway['id'], 'reason' => 'Stolen tablet'], $admin);
        TestKit::throws(WorkspaceException::class, static fn () => ctxOf($second), 'a disconnected counter cannot sync', 'disconnected');
        TestKit::same(1, (int) TestKit::scalar('SELECT COUNT(*) FROM license_activations WHERE license_id = :l AND deactivated_at IS NULL', ['l' => $licenseId]),
            'its device slot is free');
        TestKit::same(1, (int) TestKit::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'terminal.revoke' AND target_id = :t", ['t' => $takeaway['id']]), 'audited');
        TestKit::throws(Diwan\Support\UserFacingException::class,
            static fn () => (new WorkspaceAdmin())->apply($first['business_id'] + 100000, 'resolve_all_conflicts', [], $admin), 'unknown business', 'not found');

        $conflict = $detail['conflicts'][0]['id'];
        $workspaces->apply($first['business_id'], 'resolve_conflict', ['conflict_id' => $conflict], $admin);
        TestKit::same(0, count($workspaces->detail($first['business_id'])['conflicts']), 'conflict reviewed');
    },

    'photos: uploaded once, fetched by every counter of the business and by no other business' => static function (): void {
        [, $key] = workspaceLicense(2);
        $first = seededCounter($key, 'pppp000000000001:1', 5);
        $second = joinedCounter($key);
        [, $otherKey] = workspaceLicense(1);
        $stranger = seededCounter($otherKey, 'qqqq000000000001:1', 5, 'Elsewhere');
        $store = new MediaStore(mediaRoot());
        $name = 'item-' . str_repeat('ab', 16) . '.png';

        TestKit::same(['missing' => [$name]], $store->check(ctxOf($second), [$name]), 'the business does not have it yet');
        TestKit::same(['name' => $name, 'stored' => true], $store->put(ctxOf($first), $name, pngBytes()), 'counter 1 uploads it');
        TestKit::same(['name' => $name, 'stored' => false], $store->put(ctxOf($second), $name, pngBytes()), 'a second upload is a no-op');
        TestKit::same(['missing' => ['other.png']], $store->check(ctxOf($second), [$name, 'other.png', '../x.png']), 'check names only the missing, valid names');
        $info = getimagesize($store->fetch(ctxOf($second), $name));
        TestKit::same([20, 10, IMAGETYPE_PNG], [$info[0], $info[1], $info[2]], 'counter 2 gets the (re-encoded) image');
        TestKit::same(['files' => 1], array_intersect_key(MediaStore::usage($first['business_id']), ['files' => 0]), 'indexed once');

        TestKit::throws(WorkspaceException::class, static fn () => $store->fetch(ctxOf($stranger), $name), 'another business cannot fetch it', 'no such image');
        TestKit::same(['missing' => [$name]], $store->check(ctxOf($stranger), [$name]), 'nor see that it exists');
        TestKit::throws(WorkspaceException::class, static fn () => $store->put(ctxOf($first), '../escape.png', pngBytes()), 'path traversal', 'Invalid file name');
        TestKit::throws(WorkspaceException::class, static fn () => $store->put(ctxOf($first), 'fake.png', "<?php echo 'x';"), 'not an image', 'not a valid PNG');
        TestKit::throws(WorkspaceException::class, static fn () => $store->put(ctxOf($first), 'mismatch.jpg', pngBytes()), 'type must match the name', 'not a valid JPG');
        TestKit::throws(WorkspaceException::class,
            static fn () => $store->put(ctxOf($first), 'logo-x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'scripted SVG', 'cannot be used');
        TestKit::same(true, $store->put(ctxOf($first), 'logo-y.svg', '<svg xmlns="http://www.w3.org/2000/svg"><rect width="4" height="4"/></svg>')['stored'], 'a plain SVG logo is kept');
        TestKit::throws(WorkspaceException::class, static fn () => $store->put(ctxOf($first), 'big.png', str_repeat('x', MediaStore::MAX_BYTES + 1)), 'size cap', 'at most 3 MB');
    },

    'photos: files no record uses are deleted after the grace period, used ones stay' => static function (): void {
        [, $key] = workspaceLicense(1);
        $counter = seededCounter($key, 'gggg000000000001:1', 5);
        $store = new MediaStore(mediaRoot());
        pushTo($counter, [
            row('items', 'gggg000000000001:1', ['name' => 'Cola', 'price_minor' => 15000, 'image_path' => 'item-used.png']),
            row('app_config', 'config', ['business_name' => 'Cafe', 'logo_path' => 'logo-used.png']),
        ]);
        foreach (['item-used.png', 'logo-used.png', 'item-replaced.png', 'item-fresh.png'] as $name) {
            $store->put(ctxOf($counter), $name, pngBytes());
        }
        TestKit::exec("UPDATE sync_media SET created_at = NOW() - INTERVAL 8 DAY WHERE business_id = :b AND name <> 'item-fresh.png'",
            ['b' => $counter['business_id']]);

        $result = $store->collectGarbage(7, $counter['business_id']);
        TestKit::same(1, $result['deleted'], 'only the old, unused file goes');
        TestKit::check(!is_file($store->path($counter['business_id'], 'item-replaced.png')), 'its file is gone');
        foreach (['item-used.png', 'logo-used.png', 'item-fresh.png'] as $name) {
            TestKit::check(is_file($store->path($counter['business_id'], $name)), "{$name} stays");
        }
    },

    'merge: edits of different fields on two counters both survive; the same field edited unseen is flagged' => static function (): void {
        [, $key] = workspaceLicense(2);
        $gid = 'mmmm000000000001:1';
        $first = seededCounter($key, $gid, 10);
        $second = joinedCounter($key);
        $base = headOf($first);
        $edit = static fn (array $data, array $fields, int $base): array => row('items', $gid, $data) + ['fields' => $fields, 'base' => $base];
        $workspaces_pins = static fn (array $counter): int => (int) (new WorkspaceAdmin())->detail($counter['business_id'])['pins_protected'];

        pushTo($first, [$edit(['name' => 'Cola', 'price_minor' => 16000], ['price_minor'], $base)]);
        pushTo($second, [$edit(['name' => 'Cola Zero', 'price_minor' => 15000], ['name'], $base)]);
        [$rows] = everything($first);
        TestKit::same(['Cola Zero', 16000], [$rows["items:{$gid}"]['name'], $rows["items:{$gid}"]['price_minor']], 'both edits kept');
        $conflicts = static fn (): int => (int) TestKit::scalar("SELECT COUNT(*) FROM sync_conflicts WHERE business_id = :b AND kind = 'concurrent_edit'",
            ['b' => $first['business_id']]);
        TestKit::same(0, $conflicts(), 'different fields: no conflict');

        // Counter 2 changes the price without having seen counter 1's change.
        pushTo($second, [$edit(['name' => 'Cola Zero', 'price_minor' => 17000], ['price_minor'], $base)]);
        [$rows] = everything($first);
        TestKit::same(17000, $rows["items:{$gid}"]['price_minor'], 'the later write wins');
        TestKit::same(1, $conflicts(), 'and the overwritten edit is flagged');

        // Counter 1 has seen everything now: its next price change is no conflict.
        pushTo($first, [$edit(['name' => 'Cola Zero', 'price_minor' => 18000], ['price_minor'], headOf($first))]);
        TestKit::same(1, $conflicts(), 'an edit made on the latest value is not flagged');
        // An older POS build sends the whole row: it replaces, keeping fields it lacks.
        pushTo($second, [row('items', $gid, ['name' => 'Cola Classic'])]);
        [$rows] = everything($first);
        TestKit::same(['Cola Classic', 18000], [$rows["items:{$gid}"]['name'], $rows["items:{$gid}"]['price_minor']], 'whole-row change merges too');
        TestKit::same(0, (int) $workspaces_pins($first), 'no sealed PIN seen yet');
        pushTo($second, [row('users', 'mmmm000000000001:9', ['name' => 'Sana', 'role' => 'cashier', 'pin_hash' => 'enc1:0a1b2c3d:bm9uY2U='])]);
        TestKit::same(1, (int) $workspaces_pins($first), 'sealed PINs are recorded for the admin panel');
        $refused = pushTo($second, [$edit(['name' => 'x'], ['bad field'], 1)]);
        TestKit::same('Invalid list of changed fields.', $refused['rejected'][0]['error'] ?? null, 'invalid field names are refused');
    },

    'admin: sync requests per hour and stored images are reported' => static function (): void {
        TestKit::exec("INSERT INTO sync_load (hour_start, endpoint, requests) VALUES (DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00'), 'zz_test', 7200)
                       ON DUPLICATE KEY UPDATE requests = 7200");
        try {
            $load = (new WorkspaceAdmin())->load();
            TestKit::same(7200, $load['by_endpoint']['zz_test'] ?? null, 'counted per endpoint');
            TestKit::check($load['this_hour'] >= 7200 && $load['peak_per_second'] >= 2.0, 'this hour and the peak include it');
            TestKit::check(in_array($load['level'], ['ok', 'busy', 'high'], true), 'a load level');
        } finally {
            TestKit::exec("DELETE FROM sync_load WHERE endpoint = 'zz_test'");
        }
    },
];
