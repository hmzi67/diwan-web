<?php
declare(strict_types=1);

namespace Diwan\Workspace;

use Diwan\Database\Database;
use Diwan\Support\UserFacingException;
use PDO;

/**
 * The owner editing their business from the web dashboard.
 *
 * The session decides the customer and the customer decides the business; a
 * business id in a request only picks among the customer's own. Every write
 * goes through SyncEngine::push, the same path as a counter's change, so it
 * takes the next sync version and every counter pulls it like any other
 * change. Only the fields the form changed are sent, so an owner's edit never
 * overwrites what a counter changed meanwhile.
 *
 * A record can be saved here only when counters will accept it: the checks
 * below mirror the POS's own rules (unique names and barcodes, valid money,
 * purchasing). Staff accounts, sales and orders are not editable here: their
 * logic lives on the counters, and PIN hashes stay sealed.
 */
final class OwnerWorkspace
{
    /** Field => type. "!" is required on create; "blank" is text whose column is NOT NULL. See clean(). */
    private const SCHEMA = [
        'departments'        => ['name' => 'text!', 'is_active' => 'flag'],
        'categories'         => ['name' => 'text!', 'department_id' => 'ref:departments', 'is_topping' => 'flag', 'is_active' => 'flag'],
        'sub_categories'     => ['name' => 'text!', 'category_id' => 'ref:categories!', 'is_active' => 'flag'],
        'manufacturers'      => ['name' => 'text!', 'is_active' => 'flag'],
        'locations'          => ['name' => 'text!', 'notes' => 'text', 'is_active' => 'flag'],
        'bank_accounts'      => ['name' => 'text!', 'account_title' => 'text', 'account_number' => 'text', 'branch' => 'text', 'is_active' => 'flag'],
        'suppliers'          => ['name' => 'text!', 'contact_person' => 'text', 'phone' => 'text', 'email' => 'text', 'address' => 'text', 'notes' => 'text', 'is_active' => 'flag'],
        'customers'          => ['name' => 'text!', 'phone' => 'text', 'email' => 'text', 'address' => 'text', 'notes' => 'text', 'is_active' => 'flag'],
        'items'              => [
            'name' => 'text!', 'price_minor' => 'money!', 'cost_minor' => 'money', 'barcode' => 'text', 'short_code' => 'text',
            'description' => 'text', 'category_id' => 'ref:categories', 'sub_category_id' => 'ref:sub_categories',
            'manufacturer_id' => 'ref:manufacturers', 'location_id' => 'ref:locations', 'low_stock_threshold' => 'count',
            'sold_by_amount' => 'flag', 'unit' => 'text', 'counter_id' => 'ref:counters', 'is_service' => 'flag', 'is_active' => 'flag',
        ],
        'tables'             => ['name' => 'text!', 'seats' => 'seats!'],
        'expenses'           => ['expense_date' => 'date!', 'category' => 'text!', 'amount_minor' => 'money!', 'note' => 'text'],
        'counters'           => ['name' => 'text!', 'is_active' => 'flag'],
        'delivery_platforms' => ['name' => 'text!', 'is_active' => 'flag'],
        'app_config'         => [
            'business_name' => 'text!', 'phone' => 'text', 'delivery_number' => 'text', 'business_address' => 'text', 'business_website' => 'text',
            'tax_percent' => 'percent', 'service_charge_percent' => 'percent', 'receipt_tagline' => 'text', 'receipt_footer' => 'blank',
        ],
    ];

    /** Must stay unique per business, or a counter parks the record (the POS has UNIQUE on these). */
    private const UNIQUE = [
        'departments' => ['name'], 'categories' => ['name'], 'manufacturers' => ['name'], 'locations' => ['name'],
        'bank_accounts' => ['name'], 'suppliers' => ['name'], 'tables' => ['name'], 'counters' => ['name'],
        'delivery_platforms' => ['name'], 'items' => ['barcode', 'short_code'],
    ];

    /** Written by the flows below, read-only as records. Staff accounts are deliberately absent. */
    private const READ_ONLY = [
        'purchase_orders', 'purchase_order_items', 'goods_receipts', 'goods_receipt_items',
        'supplier_payments', 'customer_payments', 'refunds', 'table_orders',
    ];

    private const MAX_LINES = 100;

    private function __construct(
        public readonly int $businessId,
        private readonly bool $licenceActive,
    ) {
    }

    /** The customer's business: the one they asked for if it is theirs, else their first. */
    public static function forCustomer(int $customerId, ?int $requested): self
    {
        $own = self::businessesOf($customerId);
        foreach ($own as $business) {
            if ($requested === null || $business['id'] === $requested) {
                return new self($business['id'], $business['licence_active']);
            }
        }
        throw new UserFacingException($requested === null
            ? 'Connect a counter to your business first: Settings → Licence & Sync → Business & counters in the POS.'
            : 'That business is not on this account.');
    }

    /** Businesses whose data exists: a counter has finished uploading or loading it. */
    private static function businessesOf(int $customerId): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT b.id, b.name, l.status = 'active' AND (l.expires_at IS NULL OR l.expires_at > NOW()) AS licence_active
               FROM businesses b JOIN licenses l ON l.id = b.license_id
              WHERE b.customer_id = :c AND b.seed_completed_at IS NOT NULL
              ORDER BY b.id"
        );
        $stmt->execute(['c' => $customerId]);
        return array_map(static fn (array $b): array => [
            'id' => (int) $b['id'], 'name' => (string) $b['name'], 'licence_active' => (bool) $b['licence_active'],
        ], $stmt->fetchAll());
    }

    public static function context(int $customerId, ?int $requested): array
    {
        $businesses = self::businessesOf($customerId);
        if ($businesses === []) {
            return ['businesses' => []];
        }
        $workspace = self::forCustomer($customerId, $requested);
        $terminals = Database::pdo()->prepare(
            "SELECT name, last_seen_at, pending_count, last_seen_at > NOW() - INTERVAL 2 MINUTE AS online
               FROM pos_terminals WHERE business_id = :b AND status = 'active' ORDER BY id"
        );
        $terminals->execute(['b' => $workspace->businessId]);
        $config = $workspace->row('app_config', 'config') ?? [];
        return [
            'businesses' => $businesses,
            'business'   => $workspace->businessId,
            'version'    => $workspace->version(),
            'settings'   => array_intersect_key($config, self::SCHEMA['app_config']),
            'currency'   => (string) ($config['currency'] ?? 'PKR'),
            'terminals'  => $terminals->fetchAll(),
        ];
    }

    public function version(): int
    {
        $stmt = Database::pdo()->prepare('SELECT sync_version FROM businesses WHERE id = :b');
        $stmt->execute(['b' => $this->businessId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Live records of one type, each with its `gid`.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(string $entity): array
    {
        $stmt = Database::pdo()->prepare('SELECT gid, data FROM sync_rows WHERE business_id = :b AND entity = :e AND deleted = 0 ORDER BY version');
        $stmt->execute(['b' => $this->businessId, 'e' => $entity]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = ['gid' => (string) $row['gid']] + (json_decode((string) $row['data'], true) ?: []);
        }
        return $rows;
    }

    public function row(string $entity, string $gid): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT data FROM sync_rows WHERE business_id = :b AND entity = :e AND gid = :g AND deleted = 0');
        $stmt->execute(['b' => $this->businessId, 'e' => $entity, 'g' => $gid]);
        $data = $stmt->fetchColumn();
        return $data === false ? null : (json_decode((string) $data, true) ?: []);
    }

    /** @return array<string, float> item gid => central balance */
    public function balances(): array
    {
        $stmt = Database::pdo()->prepare('SELECT item_gid, qty FROM sync_stock WHERE business_id = :b');
        $stmt->execute(['b' => $this->businessId]);
        return array_map('floatval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /** The records screens read, with what only the server knows added to them. */
    public function records(array $entities): array
    {
        $out = ['version' => $this->version(), 'records' => []];
        foreach (array_unique($entities) as $entity) {
            if (!isset(self::SCHEMA[$entity]) && !in_array($entity, self::READ_ONLY, true) || $entity === 'app_config') {
                throw new UserFacingException('Unknown record type.');
            }
            $rows = $this->rows($entity);
            if ($entity === 'items') {
                $stock = $this->balances();
                foreach ($rows as &$row) {
                    $row['stock_qty'] = $stock[$row['gid']] ?? 0.0;
                }
                unset($row);
            }
            $out['records'][$entity] = match ($entity) {
                'suppliers' => (new OwnerReports($this))->withSupplierTotals($rows),
                'customers' => (new OwnerReports($this))->withCustomerBalances($rows),
                default     => $rows,
            };
        }
        return $out;
    }

    public function apply(string $action, array $input): array
    {
        if (!$this->licenceActive) {
            throw new UserFacingException('The business licence is not active, so changes cannot be saved. Renew it under Plan & access.');
        }
        return match ($action) {
            'save'          => $this->save($input),
            'delete'        => $this->delete($input),
            'adjust_stock'  => $this->adjustStock($input),
            'create_po'     => $this->createPurchaseOrder($input),
            'set_po_status' => $this->setPurchaseOrderStatus($input),
            'receive_goods' => $this->receiveGoods($input),
            'pay_supplier'  => $this->paySupplier($input),
            default         => throw new UserFacingException('Unknown action.'),
        };
    }

    // --- Records ---------------------------------------------------------------

    private function save(array $input): array
    {
        $entity = (string) ($input['entity'] ?? '');
        if (!isset(self::SCHEMA[$entity])) {
            throw new UserFacingException('Unknown record type.');
        }
        $gid = isset($input['gid']) && $input['gid'] !== '' ? (string) $input['gid'] : null;
        if ($gid !== null && !SyncEngine::validGid($gid)) {
            throw new UserFacingException('Unknown record.');
        }
        $data = $this->clean($entity, is_array($input['fields'] ?? null) ? $input['fields'] : [], $gid === null);
        if ($gid !== null && $this->row($entity, $gid) === null) {
            throw new UserFacingException('This record no longer exists. Refresh the page.');
        }
        $this->checkUnique($entity, $gid, $data);

        if ($gid !== null) {
            $this->commit([$this->change($entity, $gid, $data, array_keys($data), $input['base'] ?? null)]);
            return ['gid' => $gid, 'version' => $this->version()];
        }

        $gid = $this->mint($input, 0);
        if ($this->row($entity, $gid) !== null) {
            return ['gid' => $gid, 'version' => $this->version()];   // a retried request
        }
        $changes = [$this->change($entity, $gid, $data + $this->defaults($entity))];
        $stock = [];
        // A new product's opening stock, the way a counter's goods-in records it.
        $opening = (float) ($input['opening_stock'] ?? 0);
        if ($entity === 'items' && $opening !== 0.0) {
            if ($opening < 0 || $opening > 1e9 || !empty($data['is_service'])) {
                throw new UserFacingException('Opening stock must be zero or more, and a service has no stock.');
            }
            $changes[] = $this->movement($this->mint($input, 1), $gid, $opening, 'add', null, 'Opening stock');
            $stock[] = ['key' => $gid . ':opening', 'item_gid' => $gid, 'qty' => $opening];
        }
        $this->commit($changes, $stock);
        return ['gid' => $gid, 'version' => $this->version()];
    }

    private function defaults(string $entity): array
    {
        $defaults = isset(self::SCHEMA[$entity]['is_active']) ? ['is_active' => 1] : [];
        return $defaults + ['created_at' => date('Y-m-d H:i:s')];
    }

    /** A record something still points at stays: counters refuse to lose history. */
    private function delete(array $input): array
    {
        $entity = (string) ($input['entity'] ?? '');
        $gid = (string) ($input['gid'] ?? '');
        if (!isset(self::SCHEMA[$entity]) || $entity === 'app_config' || !SyncEngine::validGid($gid)) {
            throw new UserFacingException('Unknown record.');
        }
        if ($this->row($entity, $gid) === null) {
            return ['version' => $this->version()];
        }
        // Foreign keys travel as gids; a parked cart holds them inside a JSON string.
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM sync_rows WHERE business_id = :b AND deleted = 0 AND (LOCATE(:plain, data) > 0 OR LOCATE(:nested, data) > 0)'
        );
        $stmt->execute(['b' => $this->businessId, 'plain' => '"' . $gid . '"', 'nested' => '\\"' . $gid . '\\"']);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new UserFacingException(isset(self::SCHEMA[$entity]['is_active'])
                ? 'Other records use this one, so it cannot be deleted. Mark it inactive instead.'
                : 'Other records use this one, so it cannot be deleted.');
        }
        $this->commit([['id' => 1, 'entity' => $entity, 'gid' => $gid, 'op' => 'delete']]);
        return ['version' => $this->version()];
    }

    // --- Stock and purchasing (mirrors the POS's db::stock and db::purchasing) ---

    private function adjustStock(array $input): array
    {
        $gid = $this->requireRef('items', $input['item'] ?? null, 'Choose a product.');
        $item = $this->row('items', $gid);
        if (!empty($item['is_service'])) {
            throw new UserFacingException('A service has no stock.');
        }
        $qty = self::qty($input['qty'] ?? null, true);
        $kind = (string) ($input['kind'] ?? '');
        // shortcut: read outside the business lock, so a sale in the same instant can
        // skew a correction by that sale. A correction is a count taken by a person.
        $balance = $this->balances()[$gid] ?? 0.0;
        $change = match ($kind) {
            'add'        => $qty,
            'damage'     => -$qty,
            'correction' => round($qty - $balance, 4),
            default      => throw new UserFacingException('Unknown stock change.'),
        };
        if ($kind === 'damage' && $qty > $balance) {
            throw new UserFacingException('There are only ' . self::number($balance) . ' in stock.');
        }
        if ($change === 0.0) {
            return ['version' => $this->version()];
        }
        $id = $this->mint($input, 0);
        $this->commit(
            [$this->movement($id, $gid, $change, $kind, null, self::text($input['note'] ?? null))],
            [['key' => $id, 'item_gid' => $gid, 'qty' => $change]]
        );
        return ['version' => $this->version()];
    }

    private function createPurchaseOrder(array $input): array
    {
        $gid = $this->mint($input, 0);
        if ($this->row('purchase_orders', $gid) !== null) {
            return ['gid' => $gid, 'version' => $this->version()];
        }
        $status = (string) ($input['status'] ?? 'draft');
        if (!in_array($status, ['draft', 'ordered'], true)) {
            throw new UserFacingException('Unknown order status.');
        }
        $changes = [$this->change('purchase_orders', $gid, [
            'supplier_id'   => $this->requireRef('suppliers', $input['supplier'] ?? null, 'Choose a supplier.'),
            'status'        => $status,
            'order_date'    => self::date($input['order_date'] ?? null, true),
            'expected_date' => self::date($input['expected_date'] ?? null, false),
            'notes'         => self::text($input['notes'] ?? null),
            'created_at'    => date('Y-m-d H:i:s'),
        ])];
        foreach (self::lines($input) as $n => $line) {
            $changes[] = $this->change('purchase_order_items', $this->mint($input, $n + 1), [
                'po_id'           => $gid,
                'item_id'         => $this->stockedItem($line['item'] ?? null),
                'qty_ordered'     => self::qty($line['qty'] ?? null),
                'unit_cost_minor' => self::money($line['unit_cost_minor'] ?? null),
                'qty_received'    => 0,
            ]);
        }
        $this->commit($changes);
        return ['gid' => $gid, 'version' => $this->version()];
    }

    private function setPurchaseOrderStatus(array $input): array
    {
        $gid = $this->requireRef('purchase_orders', $input['gid'] ?? null, 'Unknown purchase order.');
        $current = (string) $this->row('purchase_orders', $gid)['status'];
        $status = (string) ($input['status'] ?? '');
        $allowed = match ($status) {
            'ordered'   => $current === 'draft',
            'cancelled' => in_array($current, ['draft', 'ordered', 'partial'], true),
            default     => false,
        };
        if (!$allowed) {
            throw new UserFacingException("An order that is {$current} cannot become {$status}.");
        }
        $this->commit([$this->change('purchase_orders', $gid, ['status' => $status], ['status'])]);
        return ['version' => $this->version()];
    }

    private function receiveGoods(array $input): array
    {
        $gid = $this->mint($input, 0);
        if ($this->row('goods_receipts', $gid) !== null) {
            return ['gid' => $gid, 'version' => $this->version()];
        }
        $supplier = $this->requireRef('suppliers', $input['supplier'] ?? null, 'Choose a supplier.');
        $po = null;
        if (!empty($input['po'])) {
            $po = $this->requireRef('purchase_orders', $input['po'], 'Unknown purchase order.');
            $order = $this->row('purchase_orders', $po);
            if ($order['supplier_id'] !== $supplier) {
                throw new UserFacingException('That purchase order is for a different supplier.');
            }
            if (!in_array($order['status'], ['ordered', 'partial'], true)) {
                throw new UserFacingException("That purchase order is {$order['status']}: place it first.");
            }
        }

        $poLines = [];
        foreach ($po === null ? [] : $this->rows('purchase_order_items') as $line) {
            if ($line['po_id'] === $po) {
                $poLines[$line['gid']] = $line;
            }
        }

        $total = 0;
        $changes = [];
        $stock = [];
        foreach (self::lines($input) as $n => $line) {
            $item = $this->stockedItem($line['item'] ?? null);
            $qty = self::qty($line['qty'] ?? null);
            $cost = self::money($line['unit_cost_minor'] ?? null);
            $poItem = null;
            if (!empty($line['po_item'])) {
                $poItem = (string) $line['po_item'];
                if (!isset($poLines[$poItem]) || $poLines[$poItem]['item_id'] !== $item) {
                    throw new UserFacingException("A received line doesn't match its purchase order.");
                }
                $poLines[$poItem]['qty_received'] = (float) $poLines[$poItem]['qty_received'] + $qty;
                $changes[] = $this->change('purchase_order_items', $poItem, ['qty_received' => $poLines[$poItem]['qty_received']], ['qty_received']);
            }
            $total += (int) round($cost * $qty);
            $changes[] = $this->change('goods_receipt_items', $this->mint($input, $n * 2 + 1), [
                'grn_id' => $gid, 'item_id' => $item, 'po_item_id' => $poItem, 'qty' => $qty, 'unit_cost_minor' => $cost,
                'batch_no' => self::text($line['batch_no'] ?? null), 'expiry_date' => self::date($line['expiry_date'] ?? null, false),
            ]);
            $changes[] = $this->change('items', $item, ['cost_minor' => $cost], ['cost_minor']);
            $changes[] = $this->movement($this->mint($input, $n * 2 + 2), $item, $qty, 'grn', $gid, null);
            $stock[] = ['key' => "{$gid}:{$n}", 'item_gid' => $item, 'qty' => $qty];
        }
        array_unshift($changes, $this->change('goods_receipts', $gid, [
            'supplier_id' => $supplier, 'po_id' => $po, 'received_date' => self::date($input['received_date'] ?? null, true),
            'invoice_ref' => self::text($input['invoice_ref'] ?? null), 'notes' => self::text($input['notes'] ?? null),
            'total_cost_minor' => $total, 'created_at' => date('Y-m-d H:i:s'),
        ]));
        if ($po !== null) {
            $open = array_filter($poLines, static fn (array $l): bool => (float) $l['qty_received'] < (float) $l['qty_ordered']);
            $changes[] = $this->change('purchase_orders', $po, ['status' => $open === [] ? 'received' : 'partial'], ['status']);
        }
        $this->commit($changes, $stock);
        return ['gid' => $gid, 'version' => $this->version()];
    }

    private function paySupplier(array $input): array
    {
        $gid = $this->mint($input, 0);
        if ($this->row('supplier_payments', $gid) !== null) {
            return ['gid' => $gid, 'version' => $this->version()];
        }
        $amount = self::money($input['amount_minor'] ?? null);
        if ($amount <= 0) {
            throw new UserFacingException('Enter an amount greater than zero.');
        }
        $method = (string) ($input['method'] ?? '');
        if (!in_array($method, ['cash', 'card', 'bank', 'other'], true)) {
            throw new UserFacingException('Unknown payment method.');
        }
        $this->commit([$this->change('supplier_payments', $gid, [
            'supplier_id'     => $this->requireRef('suppliers', $input['supplier'] ?? null, 'Choose a supplier.'),
            'amount_minor'    => $amount,
            'method'          => $method,
            'bank_account_id' => empty($input['bank_account']) ? null : $this->requireRef('bank_accounts', $input['bank_account'], 'Unknown bank account.'),
            'reference'       => self::text($input['reference'] ?? null),
            'note'            => self::text($input['note'] ?? null),
            'paid_date'       => self::date($input['paid_date'] ?? null, true),
            'created_at'      => date('Y-m-d H:i:s'),
        ])]);
        return ['gid' => $gid, 'version' => $this->version()];
    }

    // --- Plumbing ---------------------------------------------------------------

    /** @param list<array> $changes */
    private function commit(array $changes, array $stock = []): void
    {
        foreach ($changes as $n => &$change) {
            $change['id'] = $n + 1;
        }
        unset($change);
        $result = (new SyncEngine())->push(
            ['business_id' => $this->businessId, 'terminal_id' => null, 'joined' => true, 'name' => 'the web dashboard'],
            ['changes' => $changes, 'stock' => $stock]
        );
        if ($result['rejected'] !== []) {
            throw new \RuntimeException('Web change rejected: ' . json_encode($result['rejected']));
        }
    }

    private function change(string $entity, string $gid, array $data, ?array $fields = null, mixed $base = null): array
    {
        return ['id' => 0, 'entity' => $entity, 'gid' => $gid, 'op' => 'upsert', 'data' => $data]
            + ($fields === null ? [] : ['fields' => $fields])
            + (is_int($base) ? ['base' => $base] : []);
    }

    private function movement(string $gid, string $item, float $qty, string $reason, ?string $reference, ?string $note): array
    {
        return $this->change('stock_movements', $gid, [
            'item_id' => $item, 'qty_change' => $qty, 'reason' => $reason, 'reference_id' => $reference,
            'note' => $note, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * The n-th record a request creates. The id comes from the request, so a
     * retry after a lost reply lands on the same records instead of new ones.
     */
    private function mint(array $input, int $n): string
    {
        $request = (string) ($input['request_id'] ?? '');
        if (preg_match('/^[A-Za-z0-9-]{16,64}$/', $request) !== 1) {
            throw new UserFacingException('Please refresh the page and try again.');
        }
        return 'web:' . substr(hash('sha256', "{$this->businessId}:{$request}:{$n}"), 0, 32);
    }

    /** The fields this entity accepts, validated; everything else in the request is dropped. */
    private function clean(string $entity, array $fields, bool $creating): array
    {
        $data = [];
        foreach (self::SCHEMA[$entity] as $field => $spec) {
            $required = str_ends_with($spec, '!');
            $type = rtrim($spec, '!');
            if (!array_key_exists($field, $fields)) {
                if ($required && $creating) {
                    throw new UserFacingException(self::label($field) . ' is required.');
                }
                continue;
            }
            $value = $fields[$field];
            $data[$field] = match (true) {
                $type === 'text'    => self::text($value),
                $type === 'blank'   => self::text($value) ?? '',
                $type === 'flag'    => empty($value) ? 0 : 1,
                $type === 'money'   => self::money($value),
                $type === 'count'   => self::count($value),
                $type === 'seats'   => max(1, self::count($value)),
                $type === 'percent' => self::percent($value),
                $type === 'date'    => self::date($value, $required),
                default             => $value === null || $value === ''
                    ? null
                    : $this->requireRef(substr($type, 4), $value, 'Choose ' . self::label($field) . ' from the list.'),
            };
            if ($required && $data[$field] === null) {
                throw new UserFacingException(self::label($field) . ' is required.');
            }
        }
        if ($entity === 'items' && isset($data['short_code']) && preg_match('/^\d{2,4}$/', $data['short_code']) !== 1) {
            throw new UserFacingException('Quick code must be 2-4 digits.');
        }
        return $data;
    }

    private function checkUnique(string $entity, ?string $gid, array $data): void
    {
        $unique = array_filter(self::UNIQUE[$entity] ?? [], static fn (string $f): bool => isset($data[$f]));
        if ($unique === []) {
            return;
        }
        foreach ($this->rows($entity) as $row) {
            foreach ($unique as $field) {
                if ($row['gid'] !== $gid && mb_strtolower((string) ($row[$field] ?? '')) === mb_strtolower((string) $data[$field])) {
                    throw new UserFacingException(self::label($field) . " \"{$data[$field]}\" is already used.");
                }
            }
        }
    }

    private function requireRef(string $entity, mixed $gid, string $message): string
    {
        if (!is_string($gid) || !SyncEngine::validGid($gid) || $this->row($entity, $gid) === null) {
            throw new UserFacingException($message);
        }
        return $gid;
    }

    private function stockedItem(mixed $gid): string
    {
        $gid = $this->requireRef('items', $gid, 'Choose a product on every line.');
        if (!empty($this->row('items', $gid)['is_service'])) {
            throw new UserFacingException('A service cannot be purchased into stock.');
        }
        return $gid;
    }

    private static function lines(array $input): array
    {
        $lines = $input['lines'] ?? null;
        if (!is_array($lines) || !array_is_list($lines) || $lines === [] || count($lines) > self::MAX_LINES) {
            throw new UserFacingException('Add between 1 and ' . self::MAX_LINES . ' lines.');
        }
        return array_map(static fn (mixed $l): array => is_array($l) ? $l : [], $lines);
    }

    private static function label(string $field): string
    {
        return ucfirst(str_replace('_', ' ', preg_replace('/(_id|_minor)$/', '', $field) ?? $field));
    }

    private static function text(mixed $value): ?string
    {
        if (!is_scalar($value) && $value !== null) {
            throw new UserFacingException('Invalid value.');
        }
        $text = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $value) ?? '');
        if (mb_strlen($text) > 500) {
            throw new UserFacingException('A value is too long (500 characters at most).');
        }
        return $text === '' ? null : $text;
    }

    private static function money(mixed $value): int
    {
        if (!is_numeric($value) || (float) $value < 0 || (float) $value > 1e13 || (float) $value !== floor((float) $value)) {
            throw new UserFacingException('Enter a valid amount.');
        }
        return (int) $value;
    }

    private static function count(mixed $value): int
    {
        if (!is_numeric($value) || (float) $value < 0 || (float) $value > 1e9) {
            throw new UserFacingException('Enter a valid number.');
        }
        return (int) $value;
    }

    private static function percent(mixed $value): float
    {
        if (!is_numeric($value) || (float) $value < 0 || (float) $value > 100) {
            throw new UserFacingException('Enter a percentage from 0 to 100.');
        }
        return (float) $value;
    }

    private static function qty(mixed $value, bool $zeroAllowed = false): float
    {
        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value > 1e9
            || (float) $value < 0 || (!$zeroAllowed && (float) $value == 0)) {
            throw new UserFacingException('Quantities must be greater than zero.');
        }
        return round((float) $value, 4);
    }

    private static function date(mixed $value, bool $required): ?string
    {
        if (($value === null || $value === '') && !$required) {
            return null;
        }
        if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new UserFacingException('Enter a valid date.');
        }
        return $value;
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
