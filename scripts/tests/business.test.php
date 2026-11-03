<?php
declare(strict_types=1);

use Diwan\Support\UserFacingException;
use Diwan\Workspace\OwnerReports;
use Diwan\Workspace\OwnerWorkspace;
use Diwan\Workspace\SyncEngine;

/** A business with a connected counter that sold nothing yet: [owner workspace, counter, customer id, licence id]. */
function ownerBusiness(): array
{
    [$customerId, $key, $licenseId] = workspaceLicense();
    $counter = seededCounter($key, 'c1:1', 10);
    return [OwnerWorkspace::forCustomer($customerId, null), $counter, $customerId, $licenseId];
}

function requestId(): string
{
    return bin2hex(random_bytes(12));
}

function saved(OwnerWorkspace $owner, string $entity, array $fields, array $extra = []): string
{
    return $owner->apply('save', ['entity' => $entity, 'fields' => $fields, 'request_id' => requestId()] + $extra)['gid'];
}

return [
    'a record made on the web reaches a counter, with its foreign keys and stock' => static function (): void {
        [$owner, $counter] = ownerBusiness();
        $department = saved($owner, 'departments', ['name' => 'Food']);
        $category = saved($owner, 'categories', ['name' => 'Rice', 'department_id' => $department]);
        $item = saved($owner, 'items', ['name' => 'Biryani', 'price_minor' => 50000, 'category_id' => $category], ['opening_stock' => 7]);
        [$rows, $stock] = everything($counter);
        TestKit::same($department, $rows["categories:{$category}"]['department_id'], 'the category points at the department by gid');
        TestKit::same(50000, $rows["items:{$item}"]['price_minor'], 'the counter gets the price');
        TestKit::same(1, $rows["items:{$item}"]['is_active'], 'a new record is active');
        TestKit::same(7.0, $stock[$item], 'opening stock is a central balance');
        TestKit::check(!array_key_exists('stock_qty', $rows["items:{$item}"]), 'stock never travels with the row');
        $moves = array_filter($rows, static fn ($r) => ($r['item_id'] ?? null) === $item && ($r['reason'] ?? null) === 'add');
        TestKit::same(1, count($moves), 'opening stock explains itself with a movement');
    },

    'a web edit changes only its fields, so a counter edit of another field survives' => static function (): void {
        [$owner, $counter] = ownerBusiness();
        pushTo($counter, [row('items', 'c1:2', ['name' => 'Tea', 'price_minor' => 100, 'cost_minor' => 40])]);
        $base = $owner->version();
        pushTo($counter, [['id' => 99, 'entity' => 'items', 'gid' => 'c1:2', 'op' => 'upsert', 'data' => ['cost_minor' => 60], 'fields' => ['cost_minor']]]);
        $owner->apply('save', ['entity' => 'items', 'gid' => 'c1:2', 'fields' => ['price_minor' => 150], 'base' => $base]);
        $row = $owner->row('items', 'c1:2');
        TestKit::same(150, $row['price_minor'], 'the web edit landed');
        TestKit::same(60, $row['cost_minor'], 'the counter edit survived');
        TestKit::same('Tea', $row['name'], 'untouched fields stay');
    },

    'a counter pushing an item keeps the catalog fields it does not carry' => static function (): void {
        [$owner, $counter] = ownerBusiness();
        $maker = saved($owner, 'manufacturers', ['name' => 'Coca-Cola']);
        pushTo($counter, [row('items', 'c1:3', ['name' => 'Cola', 'price_minor' => 100])]);
        $owner->apply('save', ['entity' => 'items', 'gid' => 'c1:3', 'fields' => ['manufacturer_id' => $maker]]);
        pushTo($counter, [row('items', 'c1:3', ['name' => 'Cola 500ml', 'price_minor' => 100])]);   // an older POS build
        $row = $owner->row('items', 'c1:3');
        TestKit::same('Cola 500ml', $row['name'], 'the counter edit landed');
        TestKit::same($maker, $row['manufacturer_id'], 'the manufacturer stayed');
    },

    'what a counter would refuse is refused here' => static function (): void {
        [$owner] = ownerBusiness();
        saved($owner, 'categories', ['name' => 'Drinks']);
        TestKit::throws(UserFacingException::class, static fn () => saved($owner, 'categories', ['name' => 'drinks']), 'duplicate name', 'already used');
        saved($owner, 'items', ['name' => 'A', 'price_minor' => 1, 'barcode' => '111']);
        TestKit::throws(UserFacingException::class, static fn () => saved($owner, 'items', ['name' => 'B', 'price_minor' => 1, 'barcode' => '111']), 'duplicate barcode', 'already used');
        TestKit::throws(UserFacingException::class, static fn () => saved($owner, 'items', ['name' => 'C', 'price_minor' => -5]), 'negative price', 'valid amount');
        TestKit::throws(UserFacingException::class, static fn () => saved($owner, 'items', ['name' => 'D', 'price_minor' => 1, 'short_code' => 'x']), 'bad code', '2-4 digits');
        TestKit::throws(UserFacingException::class, static fn () => saved($owner, 'items', ['name' => 'E', 'price_minor' => 1, 'category_id' => 'nope:1']), 'dangling reference', 'from the list');
        TestKit::throws(UserFacingException::class, static fn () => saved($owner, 'users', ['name' => 'x']), 'staff accounts are not editable', 'Unknown record');
    },

    'fields outside the schema are dropped, so PIN hashes and stock cannot be written' => static function (): void {
        [$owner, $counter] = ownerBusiness();
        pushTo($counter, [row('app_config', 'config', ['business_name' => 'Old', 'sensitive_area_pin_hash' => 'enc1:abcd1234:sealed', 'currency' => 'PKR'])]);
        $owner->apply('save', ['entity' => 'app_config', 'gid' => 'config', 'fields' => ['business_name' => 'New', 'sensitive_area_pin_hash' => 'x', 'currency' => 'USD']]);
        $config = $owner->row('app_config', 'config');
        TestKit::same('New', $config['business_name'], 'the name changed');
        TestKit::same('enc1:abcd1234:sealed', $config['sensitive_area_pin_hash'], 'the sealed PIN is untouched');
        TestKit::same('PKR', $config['currency'], 'a field the form does not offer is untouched');
        $owner->apply('save', ['entity' => 'app_config', 'gid' => 'config', 'fields' => ['receipt_footer' => '']]);
        TestKit::same('', $owner->row('app_config', 'config')['receipt_footer'], 'a cleared footer stays a string: the column is NOT NULL on counters');
        $item = saved($owner, 'items', ['name' => 'Z', 'price_minor' => 1, 'stock_qty' => 999]);
        TestKit::check(!array_key_exists('stock_qty', $owner->row('items', $item)), 'stock cannot be written as a value');
        TestKit::throws(UserFacingException::class, static fn () => $owner->records(['users']), 'staff accounts are not readable', 'Unknown record');
    },

    'another customer reaches nothing, and no customer gets a business that is not theirs' => static function (): void {
        [$owner] = ownerBusiness();
        $stranger = TestKit::customer();
        TestKit::throws(UserFacingException::class, static fn () => OwnerWorkspace::forCustomer($stranger, null), 'no business of their own', 'Connect a counter');
        TestKit::throws(UserFacingException::class, static fn () => OwnerWorkspace::forCustomer($stranger, $owner->businessId), 'someone else\'s business', 'not on this account');
        TestKit::same([], OwnerWorkspace::context($stranger, null)['businesses'], 'an empty context');
    },

    'with an inactive licence the data can be read but not changed' => static function (): void {
        [, , $customerId, $licenseId] = ownerBusiness();
        TestKit::exec("UPDATE licenses SET status = 'revoked' WHERE id = :id", ['id' => $licenseId]);
        $owner = OwnerWorkspace::forCustomer($customerId, null);
        TestKit::same('items', array_key_first($owner->records(['items'])['records']), 'still readable');
        TestKit::throws(UserFacingException::class, static fn () => saved($owner, 'departments', ['name' => 'X']), 'writes refused', 'not active');
    },

    'a record in use cannot be deleted, an unused one reaches counters as a tombstone' => static function (): void {
        [$owner, $counter] = ownerBusiness();
        $category = saved($owner, 'categories', ['name' => 'Used']);
        saved($owner, 'items', ['name' => 'Thing', 'price_minor' => 1, 'category_id' => $category]);
        TestKit::throws(UserFacingException::class, static fn () => $owner->apply('delete', ['entity' => 'categories', 'gid' => $category]), 'used', 'inactive');
        pushTo($counter, [row('table_orders', 'c1:9', ['cart_json' => json_encode(['items' => [['itemId' => 'c1:1']]])])]);
        TestKit::throws(UserFacingException::class, static fn () => $owner->apply('delete', ['entity' => 'items', 'gid' => 'c1:1']), 'item in a parked cart', 'inactive');
        $free = saved($owner, 'departments', ['name' => 'Free']);
        $since = $owner->version();
        $owner->apply('delete', ['entity' => 'departments', 'gid' => $free]);
        $page = (new SyncEngine())->pull(ctxOf($counter), $since, 10);
        TestKit::same(true, $page['changes'][0]['deleted'], 'the counter is told');
    },

    'stock changes are signed deltas that add to sales, and damage cannot pass zero' => static function (): void {
        [$owner, $counter] = ownerBusiness();
        $business = $owner->businessId;
        $owner->apply('adjust_stock', ['item' => 'c1:1', 'kind' => 'add', 'qty' => 5, 'request_id' => requestId()]);
        TestKit::same(15.0, balance($business, 'c1:1'), 'added');
        pushTo($counter, [], [stockChange('sale:12345678', 'c1:1', -3)]);
        TestKit::same(12.0, balance($business, 'c1:1'), 'a sale adds up');
        $owner->apply('adjust_stock', ['item' => 'c1:1', 'kind' => 'correction', 'qty' => 20, 'request_id' => requestId()]);
        TestKit::same(20.0, balance($business, 'c1:1'), 'a correction sets the count');
        TestKit::throws(UserFacingException::class, static fn () => $owner->apply('adjust_stock', ['item' => 'c1:1', 'kind' => 'damage', 'qty' => 21, 'request_id' => requestId()]), 'too much damage', 'only 20');
        $request = requestId();
        $owner->apply('adjust_stock', ['item' => 'c1:1', 'kind' => 'damage', 'qty' => 1, 'request_id' => $request]);
        $owner->apply('adjust_stock', ['item' => 'c1:1', 'kind' => 'damage', 'qty' => 1, 'request_id' => $request]);
        TestKit::same(19.0, balance($business, 'c1:1'), 'a retried request applies once');
    },

    'receiving against an order moves stock, cost, quantities and status, once' => static function (): void {
        [$owner, $counter] = ownerBusiness();
        $business = $owner->businessId;
        $supplier = saved($owner, 'suppliers', ['name' => 'Metro']);
        $po = $owner->apply('create_po', [
            'supplier' => $supplier, 'order_date' => '2026-10-01', 'status' => 'ordered', 'request_id' => requestId(),
            'lines' => [['item' => 'c1:1', 'qty' => 10, 'unit_cost_minor' => 500]],
        ])['gid'];
        $poItem = array_values(array_filter($owner->rows('purchase_order_items'), static fn ($l) => $l['po_id'] === $po))[0]['gid'];

        $request = requestId();
        $grn = ['supplier' => $supplier, 'po' => $po, 'received_date' => '2026-10-02', 'request_id' => $request,
            'lines' => [['item' => 'c1:1', 'po_item' => $poItem, 'qty' => 4, 'unit_cost_minor' => 600, 'expiry_date' => '2020-01-01']]];
        $owner->apply('receive_goods', $grn);
        $owner->apply('receive_goods', $grn);   // the reply was lost, the owner pressed again
        TestKit::same(14.0, balance($business, 'c1:1'), 'stock came in once');
        TestKit::same(600, $owner->row('items', 'c1:1')['cost_minor'], 'the item takes the new cost');
        TestKit::same('partial', $owner->row('purchase_orders', $po)['status'], 'partly received');
        TestKit::same(4.0, (float) $owner->row('purchase_order_items', $poItem)['qty_received'], 'received quantity counted once');

        $owner->apply('receive_goods', ['supplier' => $supplier, 'po' => $po, 'received_date' => '2026-10-03', 'request_id' => requestId(),
            'lines' => [['item' => 'c1:1', 'po_item' => $poItem, 'qty' => 6, 'unit_cost_minor' => 600]]]);
        TestKit::same('received', $owner->row('purchase_orders', $po)['status'], 'fully received');
        TestKit::throws(UserFacingException::class, static fn () => $owner->apply('set_po_status', ['gid' => $po, 'status' => 'cancelled']), 'finished order stays', 'cannot become');

        $owner->apply('pay_supplier', ['supplier' => $supplier, 'amount_minor' => 1000, 'method' => 'bank', 'paid_date' => '2026-10-04', 'request_id' => requestId()]);
        $totals = (new OwnerReports($owner))->withSupplierTotals([['gid' => $supplier]])[0];
        TestKit::same(2, $totals['invoices'], 'two invoices');
        TestKit::same(6000 - 1000, $totals['balance_minor'], 'owed: received less paid');

        $alerts = (new OwnerReports($owner))->dashboard('2026-10-05')['alerts'];
        TestKit::same(1, $alerts['expired'], 'the expired batch is flagged');
        [$rows] = everything($counter);
        TestKit::same($po, array_values(array_filter($rows, static fn ($r) => array_key_exists('total_cost_minor', $r)))[0]['po_id'], 'the counter gets the receipt');
    },

    'reports add up like the POS, from what counters pushed' => static function (): void {
        [$owner, $counter] = ownerBusiness();
        pushTo($counter, [
            row('users', 'c1:7', ['name' => 'Ali', 'pin_hash' => 'enc1:abcd1234:x', 'role' => 'cashier']),
            row('items', 'c1:1', ['name' => 'Cola', 'price_minor' => 1000, 'cost_minor' => 400, 'low_stock_threshold' => 50]),
            row('sales', 'c1:100', ['total_minor' => 2000, 'tax_minor' => 100, 'discount_minor' => 100, 'service_charge_minor' => 0, 'payment_method' => 'split', 'cashier_id' => 'c1:7', 'created_at' => '2026-10-10 12:00:00']),
            row('sale_items', 'c1:200', ['sale_id' => 'c1:100', 'item_id' => 'c1:1', 'qty' => 2.0, 'price_at_sale_minor' => 1000, 'cost_at_sale_minor' => 400]),
            row('sale_payments', 'c1:300', ['sale_id' => 'c1:100', 'method' => 'cash', 'amount_minor' => 1000]),
            row('sale_payments', 'c1:301', ['sale_id' => 'c1:100', 'method' => 'credit', 'amount_minor' => 1000]),
            row('customers', 'c1:400', ['name' => 'Sara']),
            row('sales', 'c1:101', ['total_minor' => 500, 'payment_method' => 'cash', 'created_at' => '2026-09-01 12:00:00']),
            row('refunds', 'c1:500', ['original_sale_id' => 'c1:100', 'total_refund_amount_minor' => 1000, 'created_at' => '2026-10-11 09:00:00']),
            row('refund_items', 'c1:501', ['refund_id' => 'c1:500', 'sale_item_id' => 'c1:200', 'qty_refunded' => 1.0, 'amount_refunded_minor' => 1000]),
            row('expenses', 'c1:600', ['expense_date' => '2026-10-12', 'category' => 'Rent', 'amount_minor' => 50]),
        ]);
        $reports = new OwnerReports($owner);
        $pl = $reports->report('profit_loss', '2026-10-01', '2026-10-31');
        TestKit::same(1, $pl['transactions'], 'only the sale in range');
        TestKit::same(2000, $pl['gross_minor'], 'gross: total - tax - service + discount');
        TestKit::same(900, $pl['net_revenue_minor'], 'net: gross - discounts - refunds + service');
        TestKit::same(400, $pl['cogs_minor'], 'cost of goods: two sold, one back');
        TestKit::same(450, $pl['net_profit_minor'], 'net profit');

        $sales = $reports->sales('2026-10-01', '2026-10-31')['sales'];
        TestKit::same(1, count($sales), 'one sale listed');
        TestKit::same('Ali', $sales[0]['cashier'], 'the cashier by name');
        TestKit::check(!str_contains(json_encode($sales), 'enc1'), 'no PIN hash leaves with the name');
        TestKit::same(2, count($reports->sale('c1:100')['payments']), 'the sale shows its tenders');
        TestKit::same(1, $reports->report('terminals', '2026-10-01', '2026-10-31')['rows'][0]['orders'], 'per counter');
        TestKit::same(true, $reports->report('inventory', '', '')['rows'][0]['low'], 'low stock in the inventory report');

        $dashboard = $reports->dashboard('2026-10-10');
        TestKit::same(2000, $dashboard['today']['total_minor'], 'today');
        TestKit::same(2000, $dashboard['last_30']['total_minor'], 'the last 30 days leave out older sales');
        TestKit::same(1, $dashboard['alerts']['low_stock'], 'low stock alert');

        pushTo($counter, [['id' => 77, 'entity' => 'sales', 'gid' => 'c1:100', 'op' => 'upsert', 'data' => ['customer_id' => 'c1:400'], 'fields' => ['customer_id']]]);
        TestKit::same(0, $reports->withCustomerBalances([['gid' => 'c1:400']])[0]['balance_minor'], 'refund cleared the credit');
    },
];
