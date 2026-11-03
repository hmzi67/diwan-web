<?php
declare(strict_types=1);

namespace Diwan\Workspace;

use Diwan\Database\Database;
use Diwan\Support\UserFacingException;

/**
 * What the owner's dashboard shows, computed from the same shared records the
 * counters hold, with the same definitions as the POS's reports (db/insights.rs).
 *
 * Dates are the counters' local dates, so ranges come from the owner's browser
 * rather than the server's clock.
 *
 * shortcut: records are stored as JSON, so the reports join them in PHP. Fine for a
 * shop's few hundred thousand sale lines; project them into a table like
 * business_sales when a report gets slow.
 */
final class OwnerReports
{
    /** Same bound as the POS's order list. */
    private const MAX_SALES = 2000;

    public function __construct(private readonly OwnerWorkspace $workspace)
    {
    }

    public function dashboard(string $today): array
    {
        $today = self::date($today);
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT DATE(sold_at) AS day, COALESCE(SUM(total_minor), 0) AS total, COUNT(*) AS orders
               FROM business_sales
              WHERE business_id = :b AND deleted = 0 AND sold_at >= :from AND sold_at < :to
              GROUP BY day ORDER BY day'
        );
        $stmt->execute([
            'b' => $this->workspace->businessId,
            'from' => date('Y-m-d', strtotime("{$today} -370 days")),
            'to' => date('Y-m-d', strtotime("{$today} +1 day")),
        ]);
        $days = array_map(static fn (array $d): array => [
            'day' => (string) $d['day'], 'total_minor' => (int) $d['total'], 'orders' => (int) $d['orders'],
        ], $stmt->fetchAll());

        $range = static function (string $from) use ($days, $today): array {
            $inRange = array_filter($days, static fn (array $d): bool => $d['day'] >= $from && $d['day'] <= $today);
            return [
                'total_minor' => array_sum(array_column($inRange, 'total_minor')),
                'orders' => array_sum(array_column($inRange, 'orders')),
            ];
        };

        $monthStart = substr($today, 0, 7) . '-01';
        $receipts = array_filter(
            $this->workspace->rows('goods_receipts'),
            static fn (array $g): bool => ($g['received_date'] ?? '') >= $monthStart && ($g['received_date'] ?? '') <= $today
        );

        $stock = $this->workspace->balances();
        $low = 0;
        foreach ($this->workspace->rows('items') as $item) {
            if (($item['is_active'] ?? 1) && empty($item['is_service']) && ($stock[$item['gid']] ?? 0.0) <= (float) ($item['low_stock_threshold'] ?? 0)) {
                $low++;
            }
        }
        $expired = 0;
        foreach ($this->workspace->rows('goods_receipt_items') as $line) {
            if (!empty($line['expiry_date']) && $line['expiry_date'] <= $today && ($stock[$line['item_id']] ?? 0.0) > 0) {
                $expired++;
            }
        }

        return [
            'version'   => $this->workspace->version(),
            'today'     => $range($today),
            'last_30'   => $range(date('Y-m-d', strtotime("{$today} -29 days"))),
            'purchases' => ['count' => count($receipts), 'total_minor' => array_sum(array_column($receipts, 'total_cost_minor'))],
            'alerts'    => ['low_stock' => $low, 'expired' => $expired],
            'days'      => $days,
        ];
    }

    public function sales(string $from, string $to): array
    {
        [$from, $to] = self::range($from, $to);
        $rows = $this->salesIn($from, $to, self::MAX_SALES + 1);
        $truncated = count($rows) > self::MAX_SALES;
        $rows = array_slice($rows, 0, self::MAX_SALES);

        $names = static function (array $records): array {
            return array_column($records, 'name', 'gid');
        };
        $cashiers = $names($this->workspace->rows('users'));   // names only: nothing else of a staff account leaves here
        $customers = $names($this->workspace->rows('customers'));
        $tables = $names($this->workspace->rows('tables'));
        $terminals = $this->terminalNames();

        return [
            'truncated' => $truncated,
            'sales' => array_map(static fn (array $s): array => [
                'gid' => $s['gid'],
                'sold_at' => $s['sold_at'],
                'total_minor' => (int) ($s['total_minor'] ?? 0),
                'discount_minor' => (int) ($s['discount_minor'] ?? 0),
                'tax_minor' => (int) ($s['tax_minor'] ?? 0),
                'payment_method' => (string) ($s['payment_method'] ?? ''),
                'cashier' => $cashiers[$s['cashier_id'] ?? ''] ?? null,
                'customer' => $customers[$s['customer_id'] ?? ''] ?? null,
                'table' => $tables[$s['table_id'] ?? ''] ?? null,
                'terminal' => $terminals[$s['terminal_id'] ?? 0] ?? null,
            ], $rows),
        ];
    }

    public function sale(string $gid): array
    {
        if (!SyncEngine::validGid($gid) || $this->workspace->row('sales', $gid) === null) {
            throw new UserFacingException('Unknown sale.');
        }
        $items = array_column($this->workspace->rows('items'), 'name', 'gid');
        $lines = [];
        foreach ($this->workspace->rows('sale_items') as $line) {
            if (($line['sale_id'] ?? null) === $gid) {
                $lines[] = [
                    'item' => $items[$line['item_id'] ?? ''] ?? 'Removed item',
                    'qty' => (float) ($line['qty'] ?? 0),
                    'price_minor' => (int) ($line['price_at_sale_minor'] ?? 0),
                    'notes' => $line['notes'] ?? null,
                ];
            }
        }
        $payments = [];
        foreach ($this->workspace->rows('sale_payments') as $payment) {
            if (($payment['sale_id'] ?? null) === $gid) {
                $payments[] = ['method' => (string) ($payment['method'] ?? ''), 'amount_minor' => (int) ($payment['amount_minor'] ?? 0)];
            }
        }
        return ['lines' => $lines, 'payments' => $payments];
    }

    /** Orders the counters are still working on: neither paid in full nor cancelled. */
    public function ordersInProgress(): array
    {
        $tables = array_column($this->workspace->rows('tables'), 'name', 'gid');
        $orders = array_values(array_filter(
            $this->workspace->rows('orders'),
            static fn (array $o): bool => empty($o['completed_at']) && empty($o['cancelled_at']) && ($o['status'] ?? '') !== 'cancelled'
        ));
        $totals = [];
        $counts = [];
        foreach ($this->workspace->rows('order_items') as $line) {
            $order = (string) ($line['order_id'] ?? '');
            if (($line['status'] ?? 'active') === 'active') {
                $totals[$order] = ($totals[$order] ?? 0) + (int) round((float) ($line['qty'] ?? 0) * (int) ($line['price_at_order_minor'] ?? 0));
                $counts[$order] = ($counts[$order] ?? 0) + 1;
            }
        }
        return ['version' => $this->workspace->version(), 'orders' => array_map(static fn (array $o): array => [
            'gid' => $o['gid'], 'order_no' => $o['order_no'] ?? null, 'order_type' => $o['order_type'] ?? '',
            'status' => $o['status'] ?? '', 'table' => $tables[$o['table_id'] ?? ''] ?? null,
            'customer' => $o['customer_name'] ?? null, 'created_at' => $o['created_at'] ?? null,
            'lines' => $counts[$o['gid']] ?? 0, 'total_minor' => $totals[$o['gid']] ?? 0,
        ], $orders)];
    }

    public function report(string $name, string $from, string $to): array
    {
        if ($name === 'inventory') {
            return $this->inventory();
        }
        [$from, $to] = self::range($from, $to);
        return match ($name) {
            'terminals'   => $this->byTerminal($from, $to),
            'profit_loss' => $this->profitLoss($from, $to),
            'suppliers'   => $this->bySupplier($from, $to),
            default       => throw new UserFacingException('Unknown report.'),
        };
    }

    private function byTerminal(string $from, string $to): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT terminal_id, COUNT(*) AS orders, COALESCE(SUM(total_minor), 0) AS total
               FROM business_sales
              WHERE business_id = :b AND deleted = 0 AND sold_at >= :from AND sold_at < :to
              GROUP BY terminal_id ORDER BY total DESC'
        );
        $stmt->execute(['b' => $this->workspace->businessId, 'from' => $from, 'to' => self::dayAfter($to)]);
        $names = $this->terminalNames();
        return ['rows' => array_map(static fn (array $r): array => [
            'terminal' => $names[$r['terminal_id'] ?? 0] ?? 'Unknown counter',
            'orders' => (int) $r['orders'],
            'total_minor' => (int) $r['total'],
        ], $stmt->fetchAll())];
    }

    /** The POS's Profit & Loss: net revenue − cost of goods − expenses − salaries. */
    private function profitLoss(string $from, string $to): array
    {
        $sales = $this->salesIn($from, $to, PHP_INT_MAX);
        $saleIds = array_flip(array_column($sales, 'gid'));
        $sum = static fn (string $field): int => array_sum(array_map(static fn (array $s): int => (int) ($s[$field] ?? 0), $sales));
        $discounts = $sum('discount_minor');
        $service = $sum('service_charge_minor');
        $tax = $sum('tax_minor');
        $gross = $sum('total_minor') - $tax - $service + $discounts;

        $itemCost = array_column($this->workspace->rows('items'), 'cost_minor', 'gid');
        $saleItems = [];
        $cogs = 0;
        foreach ($this->workspace->rows('sale_items') as $line) {
            $saleItems[$line['gid']] = $line;
            if (isset($saleIds[$line['sale_id'] ?? ''])) {
                $cogs += self::lineCost($line, (float) ($line['qty'] ?? 0), $itemCost);
            }
        }

        // Refunds count when they happened, like the POS.
        $refundIds = [];
        foreach ($this->workspace->rows('refunds') as $refund) {
            $day = substr((string) ($refund['created_at'] ?? ''), 0, 10);
            if ($day >= $from && $day <= $to) {
                $refundIds[$refund['gid']] = true;
            }
        }
        $refunds = 0;
        foreach ($this->workspace->rows('refund_items') as $line) {
            if (isset($refundIds[$line['refund_id'] ?? ''])) {
                $refunds += (int) ($line['amount_refunded_minor'] ?? 0);
                $sold = $saleItems[$line['sale_item_id'] ?? ''] ?? null;
                if ($sold !== null) {
                    $cogs -= self::lineCost($sold, (float) ($line['qty_refunded'] ?? 0), $itemCost);
                }
            }
        }

        $inDates = static fn (array $rows, string $field, string $amount): int => array_sum(array_map(
            static fn (array $r): int => ($r[$field] ?? '') >= $from && ($r[$field] ?? '') <= $to ? (int) ($r[$amount] ?? 0) : 0,
            $rows
        ));
        $expenses = $inDates($this->workspace->rows('expenses'), 'expense_date', 'amount_minor');
        $salaries = $inDates($this->workspace->rows('salary_payments'), 'paid_date', 'paid_amount_minor');
        $revenue = $gross - $discounts - $refunds + $service;
        return [
            'transactions' => count($sales), 'gross_minor' => $gross, 'discounts_minor' => $discounts, 'refunds_minor' => $refunds,
            'service_minor' => $service, 'tax_minor' => $tax, 'net_revenue_minor' => $revenue, 'cogs_minor' => $cogs,
            'gross_profit_minor' => $revenue - $cogs, 'expenses_minor' => $expenses, 'salaries_minor' => $salaries,
            'net_profit_minor' => $revenue - $cogs - $expenses - $salaries,
        ];
    }

    private static function lineCost(array $line, float $qty, array $itemCost): int
    {
        return (int) round($qty * (int) ($line['cost_at_sale_minor'] ?? $itemCost[$line['item_id'] ?? ''] ?? 0));
    }

    private function inventory(): array
    {
        $stock = $this->workspace->balances();
        $categories = array_column($this->workspace->rows('categories'), 'name', 'gid');
        $rows = [];
        foreach ($this->workspace->rows('items') as $item) {
            if (!($item['is_active'] ?? 1) || !empty($item['is_service'])) {
                continue;
            }
            $qty = $stock[$item['gid']] ?? 0.0;
            $rows[] = [
                'name' => (string) ($item['name'] ?? ''), 'category' => $categories[$item['category_id'] ?? ''] ?? null, 'qty' => $qty,
                'low' => $qty <= (float) ($item['low_stock_threshold'] ?? 0),
                'cost_value_minor' => (int) round($qty * (int) ($item['cost_minor'] ?? 0)),
                'retail_value_minor' => (int) round($qty * (int) ($item['price_minor'] ?? 0)),
            ];
        }
        return ['rows' => $rows];
    }

    private function bySupplier(string $from, string $to): array
    {
        $rows = [];
        $inRange = static fn (array $r, string $field): bool => ($r[$field] ?? '') >= $from && ($r[$field] ?? '') <= $to;
        $receipts = $this->workspace->rows('goods_receipts');
        $payments = $this->workspace->rows('supplier_payments');
        foreach ($this->withSupplierTotals($this->workspace->rows('suppliers')) as $supplier) {
            $mine = static fn (array $r): bool => ($r['supplier_id'] ?? null) === $supplier['gid'];
            $bought = array_filter($receipts, static fn (array $r): bool => $mine($r) && $inRange($r, 'received_date'));
            $paid = array_filter($payments, static fn (array $r): bool => $mine($r) && $inRange($r, 'paid_date'));
            $rows[] = [
                'name' => $supplier['name'] ?? '', 'invoices' => count($bought),
                'purchased_minor' => array_sum(array_column($bought, 'total_cost_minor')),
                'paid_minor' => array_sum(array_column($paid, 'amount_minor')),
                'balance_minor' => $supplier['balance_minor'],
            ];
        }
        return ['rows' => $rows];
    }

    /** The POS's supplier statement: goods received less payments, all time. */
    public function withSupplierTotals(array $suppliers): array
    {
        $invoices = [];
        $purchased = [];
        foreach ($this->workspace->rows('goods_receipts') as $receipt) {
            $id = (string) ($receipt['supplier_id'] ?? '');
            $invoices[$id] = ($invoices[$id] ?? 0) + 1;
            $purchased[$id] = ($purchased[$id] ?? 0) + (int) ($receipt['total_cost_minor'] ?? 0);
        }
        $paid = [];
        foreach ($this->workspace->rows('supplier_payments') as $payment) {
            $id = (string) ($payment['supplier_id'] ?? '');
            $paid[$id] = ($paid[$id] ?? 0) + (int) ($payment['amount_minor'] ?? 0);
        }
        return array_map(static fn (array $s): array => $s + [
            'invoices' => $invoices[$s['gid']] ?? 0,
            'purchased_minor' => $purchased[$s['gid']] ?? 0,
            'paid_minor' => $paid[$s['gid']] ?? 0,
            'balance_minor' => ($purchased[$s['gid']] ?? 0) - ($paid[$s['gid']] ?? 0),
        ], $suppliers);
    }

    /** The POS's customer balance: per sale, credit − paid − refunded, never below zero. */
    public function withCustomerBalances(array $customers): array
    {
        $credit = [];
        foreach ($this->workspace->rows('sale_payments') as $payment) {
            if (($payment['method'] ?? '') === 'credit') {
                $sale = (string) ($payment['sale_id'] ?? '');
                $credit[$sale] = ($credit[$sale] ?? 0) + (int) ($payment['amount_minor'] ?? 0);
            }
        }
        $owed = [];
        if ($credit !== []) {
            $paid = [];
            foreach ($this->workspace->rows('customer_payments') as $payment) {
                $sale = (string) ($payment['sale_id'] ?? '');
                $paid[$sale] = ($paid[$sale] ?? 0) + (int) ($payment['amount_minor'] ?? 0);
            }
            $refunded = [];
            foreach ($this->workspace->rows('refunds') as $refund) {
                $sale = (string) ($refund['original_sale_id'] ?? '');
                $refunded[$sale] = ($refunded[$sale] ?? 0) + (int) ($refund['total_refund_amount_minor'] ?? 0);
            }
            foreach ($this->workspace->rows('sales') as $sale) {
                $gid = $sale['gid'];
                if (isset($credit[$gid]) && !empty($sale['customer_id'])) {
                    $owed[$sale['customer_id']] = ($owed[$sale['customer_id']] ?? 0)
                        + max(0, $credit[$gid] - ($paid[$gid] ?? 0) - ($refunded[$gid] ?? 0));
                }
            }
        }
        return array_map(static fn (array $c): array => $c + ['balance_minor' => $owed[$c['gid']] ?? 0], $customers);
    }

    /** Sale records in a date range, newest first, with the counter that rang each up. */
    private function salesIn(string $from, string $to, int $limit): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT r.gid, r.data, b.terminal_id, b.sold_at
               FROM business_sales b
               JOIN sync_rows r ON r.business_id = b.business_id AND r.entity = 'sales' AND r.gid = b.sale_gid AND r.deleted = 0
              WHERE b.business_id = :b AND b.deleted = 0 AND b.sold_at >= :from AND b.sold_at < :to
              ORDER BY b.sold_at DESC"
        );
        $stmt->execute(['b' => $this->workspace->businessId, 'from' => $from, 'to' => self::dayAfter($to)]);
        $rows = [];
        while (count($rows) < $limit && ($row = $stmt->fetch()) !== false) {
            $rows[] = ['gid' => $row['gid'], 'terminal_id' => $row['terminal_id'], 'sold_at' => $row['sold_at']] + (json_decode((string) $row['data'], true) ?: []);
        }
        $stmt->closeCursor();
        return $rows;
    }

    private function terminalNames(): array
    {
        $stmt = Database::pdo()->prepare('SELECT id, name FROM pos_terminals WHERE business_id = :b');
        $stmt->execute(['b' => $this->workspace->businessId]);
        return $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    private static function range(string $from, string $to): array
    {
        $from = self::date($from);
        $to = self::date($to);
        if ($from > $to) {
            throw new UserFacingException('The start date is after the end date.');
        }
        return [$from, $to];
    }

    private static function dayAfter(string $day): string
    {
        return date('Y-m-d', strtotime("{$day} +1 day"));
    }

    private static function date(string $value): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new UserFacingException('Enter a valid date.');
        }
        return $value;
    }
}
