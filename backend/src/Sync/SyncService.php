<?php
declare(strict_types=1);

namespace Diwan\Sync;

use Diwan\Database\Database;
use Diwan\License\LicenseService;
use Diwan\Support\DeviceInput;
use Diwan\Support\Logger;
use PDO;

final class SyncService
{
    /**
     * Ingests sales and daily summaries from an authenticated POS terminal.
     *
     * @param string $rawKey The terminal's active licence key
     * @param string $fingerprint Hex sha256 device fingerprint
     * @param string|null $deviceName Human-readable machine label, e.g. "Counter 1"
     * @param list<array<string, mixed>> $sales Recent/unsynced sale transactions
     * @param list<array<string, mixed>> $dailySummaries Aggregated daily numbers
     * @param int|null $claimedCustomerId Optional user ID sent by client
     * @return array{status: string, customer_id: int, synced_sales: int, synced_summaries: int, server_time: string}
     */
    public function sync(
        string $rawKey,
        string $fingerprint,
        ?string $deviceName,
        array $sales,
        array $dailySummaries,
        ?int $claimedCustomerId = null
    ): array {
        $fpHash = DeviceInput::fingerprintHash($fingerprint);
        $license = (new LicenseService())->findLicenseForDevice($rawKey, $fpHash);

        if (!$license) {
            throw new \RuntimeException('Invalid or unactivated licence key for this device.');
        }

        $customerId = $license['customer_id'];
        if ($customerId === null && $claimedCustomerId !== null) {
            $customerId = $claimedCustomerId;
        }

        if ($customerId === null) {
            throw new \RuntimeException('This licence is not associated with a customer account.');
        }

        $pdo = Database::pdo();

        // 1. Register / touch device
        $stmtDevice = $pdo->prepare(
            'INSERT INTO pos_devices (customer_id, license_id, device_fingerprint, device_name, last_sync_at, created_at)
             VALUES (:customer, :license, :fp, :name, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                license_id = VALUES(license_id),
                device_name = COALESCE(VALUES(device_name), device_name),
                last_sync_at = NOW()'
        );
        $stmtDevice->execute([
            'customer' => $customerId,
            'license'  => $license['id'],
            'fp'       => $fingerprint,
            'name'     => $deviceName ?: 'POS Terminal',
        ]);

        // 2. Ingest sales
        $syncedSales = 0;
        if (!empty($sales)) {
            $stmtSale = $pdo->prepare(
                'INSERT INTO pos_sales (
                    customer_id, device_fingerprint, local_sale_id, order_no,
                    table_name, cashier_name, total_minor, discount_minor,
                    tax_minor, payment_method, currency, items_count,
                    items_summary, sale_created_at, synced_at
                 ) VALUES (
                    :customer, :device, :sale_id, :order_no,
                    :table_name, :cashier_name, :total, :discount,
                    :tax, :payment_method, :currency, :items_count,
                    :items_summary, :created_at, NOW()
                 )
                 ON DUPLICATE KEY UPDATE
                    order_no = VALUES(order_no),
                    table_name = VALUES(table_name),
                    cashier_name = VALUES(cashier_name),
                    total_minor = VALUES(total_minor),
                    discount_minor = VALUES(discount_minor),
                    tax_minor = VALUES(tax_minor),
                    payment_method = VALUES(payment_method),
                    currency = VALUES(currency),
                    items_count = VALUES(items_count),
                    items_summary = VALUES(items_summary),
                    synced_at = NOW()'
            );

            foreach ($sales as $sale) {
                $saleId = $sale['local_sale_id'] ?? $sale['localSaleId'] ?? null;
                $total = $sale['total_minor'] ?? $sale['totalMinor'] ?? null;
                if ($saleId === null || $total === null) {
                    continue;
                }
                $createdAt = isset($sale['sale_created_at']) && is_string($sale['sale_created_at'])
                    ? $sale['sale_created_at']
                    : (isset($sale['saleCreatedAt']) && is_string($sale['saleCreatedAt']) ? $sale['saleCreatedAt'] : date('Y-m-d H:i:s'));

                $orderNo = $sale['order_no'] ?? $sale['orderNo'] ?? null;
                $tableName = $sale['table_name'] ?? $sale['tableName'] ?? null;
                $cashierName = $sale['cashier_name'] ?? $sale['cashierName'] ?? null;
                $discount = (int) ($sale['discount_minor'] ?? $sale['discountMinor'] ?? 0);
                $tax = (int) ($sale['tax_minor'] ?? $sale['taxMinor'] ?? 0);
                $paymentMethod = (string) ($sale['payment_method'] ?? $sale['paymentMethod'] ?? 'cash');
                $currency = (string) ($sale['currency'] ?? 'PKR');
                $itemsCount = (int) ($sale['items_count'] ?? $sale['itemsCount'] ?? 1);
                $itemsSummary = (string) ($sale['items_summary'] ?? $sale['itemsSummary'] ?? '');

                $stmtSale->execute([
                    'customer'       => $customerId,
                    'device'         => $fingerprint,
                    'sale_id'        => (int) $saleId,
                    'order_no'       => $orderNo !== null ? (int) $orderNo : null,
                    'table_name'     => is_string($tableName) ? mb_substr($tableName, 0, 100) : null,
                    'cashier_name'   => is_string($cashierName) ? mb_substr($cashierName, 0, 100) : null,
                    'total_minor'    => (int) $total,
                    'discount_minor' => $discount,
                    'tax_minor'      => $tax,
                    'payment_method' => mb_substr($paymentMethod, 0, 20),
                    'currency'       => mb_substr($currency, 0, 10),
                    'items_count'    => $itemsCount,
                    'items_summary'  => $itemsSummary !== '' ? $itemsSummary : null,
                    'created_at'     => $createdAt,
                ]);
                $syncedSales++;
            }
        }

        // 3. Ingest daily summaries
        $syncedSummaries = 0;
        if (!empty($dailySummaries)) {
            $stmtSummary = $pdo->prepare(
                'INSERT INTO pos_daily_summaries (
                    customer_id, summary_date, total_sales_minor, refunds_minor,
                    net_sales_minor, transaction_count, cash_minor, card_minor,
                    other_minor, currency, updated_at
                 ) VALUES (
                    :customer, :sdate, :total, :refunds,
                    :net, :txns, :cash, :card,
                    :other, :currency, NOW()
                 )
                 ON DUPLICATE KEY UPDATE
                    total_sales_minor = VALUES(total_sales_minor),
                    refunds_minor = VALUES(refunds_minor),
                    net_sales_minor = VALUES(net_sales_minor),
                    transaction_count = VALUES(transaction_count),
                    cash_minor = VALUES(cash_minor),
                    card_minor = VALUES(card_minor),
                    other_minor = VALUES(other_minor),
                    currency = VALUES(currency),
                    updated_at = NOW()'
            );

            foreach ($dailySummaries as $summary) {
                $sdate = $summary['summary_date'] ?? $summary['summaryDate'] ?? null;
                if ($sdate === null || !is_string($sdate)) {
                    continue;
                }
                $total = (int) ($summary['total_sales_minor'] ?? $summary['totalSalesMinor'] ?? 0);
                $refunds = (int) ($summary['refunds_minor'] ?? $summary['refundsMinor'] ?? 0);
                $net = (int) ($summary['net_sales_minor'] ?? $summary['netSalesMinor'] ?? 0);
                $txns = (int) ($summary['transaction_count'] ?? $summary['transactionCount'] ?? 0);
                $cash = (int) ($summary['cash_minor'] ?? $summary['cashMinor'] ?? 0);
                $card = (int) ($summary['card_minor'] ?? $summary['cardMinor'] ?? 0);
                $other = (int) ($summary['other_minor'] ?? $summary['otherMinor'] ?? 0);
                $currency = (string) ($summary['currency'] ?? 'PKR');

                $stmtSummary->execute([
                    'customer' => $customerId,
                    'sdate'    => $sdate,
                    'total'    => $total,
                    'refunds'  => $refunds,
                    'net'      => $net,
                    'txns'     => $txns,
                    'cash'     => $cash,
                    'card'     => $card,
                    'other'    => $other,
                    'currency' => $currency,
                ]);
                $syncedSummaries++;
            }
        }

        Logger::info('POS Data Synced', [
            'customer_id' => $customerId,
            'sales_count' => $syncedSales,
            'summaries_count' => $syncedSummaries,
        ]);

        return [
            'status'           => 'ok',
            'customer_id'      => (int) $customerId,
            'synced_sales'     => $syncedSales,
            'synced_summaries' => $syncedSummaries,
            'server_time'      => gmdate('Y-m-d H:i:s'),
        ];
    }

    /**
     * Fetches all synced POS metrics and recent records for the customer dashboard.
     *
     * @param int $customerId
     * @return array<string, mixed>
     */
    public function dashboardDataFor(int $customerId): array
    {
        $pdo = Database::pdo();

        // 1. Devices registered
        $stmtDevices = $pdo->prepare(
            'SELECT device_name, device_fingerprint, last_sync_at, created_at
               FROM pos_devices
              WHERE customer_id = :id
              ORDER BY last_sync_at DESC'
        );
        $stmtDevices->execute(['id' => $customerId]);
        $devices = $stmtDevices->fetchAll();

        // 2. Performance aggregates (Today, This Month, All Time)
        $stmtToday = $pdo->prepare(
            'SELECT COALESCE(SUM(total_minor), 0) AS total_sales_minor,
                    COALESCE(SUM(discount_minor), 0) AS total_discount_minor,
                    COALESCE(SUM(tax_minor), 0) AS total_tax_minor,
                    COUNT(*) AS transaction_count,
                    COALESCE(SUM(CASE WHEN payment_method = "cash" THEN total_minor ELSE 0 END), 0) AS cash_minor,
                    COALESCE(SUM(CASE WHEN payment_method = "card" THEN total_minor ELSE 0 END), 0) AS card_minor,
                    COALESCE(SUM(CASE WHEN payment_method NOT IN ("cash", "card") THEN total_minor ELSE 0 END), 0) AS other_minor
               FROM pos_sales
              WHERE customer_id = :id AND DATE(sale_created_at) = CURRENT_DATE()'
        );
        $stmtToday->execute(['id' => $customerId]);
        $today = $stmtToday->fetch() ?: [
            'total_sales_minor' => 0,
            'total_discount_minor' => 0,
            'total_tax_minor' => 0,
            'transaction_count' => 0,
            'cash_minor' => 0,
            'card_minor' => 0,
            'other_minor' => 0,
        ];

        $stmtMonth = $pdo->prepare(
            'SELECT COALESCE(SUM(total_minor), 0) AS total_sales_minor,
                    COUNT(*) AS transaction_count
               FROM pos_sales
              WHERE customer_id = :id
                AND YEAR(sale_created_at) = YEAR(CURRENT_DATE())
                AND MONTH(sale_created_at) = MONTH(CURRENT_DATE())'
        );
        $stmtMonth->execute(['id' => $customerId]);
        $month = $stmtMonth->fetch() ?: ['total_sales_minor' => 0, 'transaction_count' => 0];

        $stmtAll = $pdo->prepare(
            'SELECT COALESCE(SUM(total_minor), 0) AS total_sales_minor,
                    COUNT(*) AS transaction_count
               FROM pos_sales
              WHERE customer_id = :id'
        );
        $stmtAll->execute(['id' => $customerId]);
        $allTime = $stmtAll->fetch() ?: ['total_sales_minor' => 0, 'transaction_count' => 0];

        // 3. Recent sales (up to 30)
        $stmtRecent = $pdo->prepare(
            'SELECT local_sale_id, order_no, table_name, cashier_name, total_minor,
                    discount_minor, tax_minor, payment_method, currency, items_count,
                    items_summary, sale_created_at
               FROM pos_sales
              WHERE customer_id = :id
              ORDER BY sale_created_at DESC, id DESC
              LIMIT 30'
        );
        $stmtRecent->execute(['id' => $customerId]);
        $recentSales = $stmtRecent->fetchAll();

        // 4. Daily trends (last 14 days)
        $stmtTrends = $pdo->prepare(
            'SELECT summary_date, total_sales_minor, refunds_minor, net_sales_minor,
                    transaction_count, cash_minor, card_minor, other_minor, currency
               FROM pos_daily_summaries
              WHERE customer_id = :id
              ORDER BY summary_date DESC
              LIMIT 14'
        );
        $stmtTrends->execute(['id' => $customerId]);
        $dailyTrends = $stmtTrends->fetchAll();

        // If daily summaries table hasn't been populated by client, fall back to aggregate from pos_sales
        if (empty($dailyTrends)) {
            $stmtFallbackTrends = $pdo->prepare(
                'SELECT DATE(sale_created_at) AS summary_date,
                        SUM(total_minor) AS total_sales_minor,
                        0 AS refunds_minor,
                        SUM(total_minor) AS net_sales_minor,
                        COUNT(*) AS transaction_count,
                        SUM(CASE WHEN payment_method = "cash" THEN total_minor ELSE 0 END) AS cash_minor,
                        SUM(CASE WHEN payment_method = "card" THEN total_minor ELSE 0 END) AS card_minor,
                        SUM(CASE WHEN payment_method NOT IN ("cash", "card") THEN total_minor ELSE 0 END) AS other_minor,
                        "PKR" AS currency
                   FROM pos_sales
                  WHERE customer_id = :id
                  GROUP BY DATE(sale_created_at)
                  ORDER BY summary_date DESC
                  LIMIT 14'
            );
            $stmtFallbackTrends->execute(['id' => $customerId]);
            $dailyTrends = $stmtFallbackTrends->fetchAll();
        }

        return [
            'has_synced_data' => !empty($devices) || !empty($recentSales),
            'devices'         => array_map(static fn (array $d): array => [
                'device_name'        => (string) ($d['device_name'] ?: 'POS Terminal'),
                'device_fingerprint' => (string) $d['device_fingerprint'],
                'last_sync_at'       => (string) $d['last_sync_at'],
            ], $devices),
            'summary'         => [
                'today'    => [
                    'total_sales_minor' => (int) $today['total_sales_minor'],
                    'transaction_count' => (int) $today['transaction_count'],
                    'cash_minor'        => (int) $today['cash_minor'],
                    'card_minor'        => (int) $today['card_minor'],
                    'other_minor'       => (int) $today['other_minor'],
                ],
                'month'    => [
                    'total_sales_minor' => (int) $month['total_sales_minor'],
                    'transaction_count' => (int) $month['transaction_count'],
                ],
                'all_time' => [
                    'total_sales_minor' => (int) $allTime['total_sales_minor'],
                    'transaction_count' => (int) $allTime['transaction_count'],
                ],
            ],
            'recent_sales'    => array_map(static fn (array $s): array => [
                'local_sale_id'   => (int) $s['local_sale_id'],
                'order_no'        => $s['order_no'] !== null ? (int) $s['order_no'] : null,
                'table_name'      => $s['table_name'] ?? null,
                'cashier_name'    => $s['cashier_name'] ?? null,
                'total_minor'     => (int) $s['total_minor'],
                'discount_minor'  => (int) $s['discount_minor'],
                'tax_minor'       => (int) $s['tax_minor'],
                'payment_method'  => (string) $s['payment_method'],
                'currency'        => (string) $s['currency'],
                'items_count'     => (int) $s['items_count'],
                'items_summary'   => $s['items_summary'] ?? '',
                'sale_created_at' => (string) $s['sale_created_at'],
            ], $recentSales),
            'daily_trends'    => array_map(static fn (array $t): array => [
                'summary_date'      => (string) $t['summary_date'],
                'total_sales_minor' => (int) $t['total_sales_minor'],
                'refunds_minor'     => (int) $t['refunds_minor'],
                'net_sales_minor'   => (int) $t['net_sales_minor'],
                'transaction_count' => (int) $t['transaction_count'],
                'cash_minor'        => (int) $t['cash_minor'],
                'card_minor'        => (int) $t['card_minor'],
                'other_minor'       => (int) $t['other_minor'],
                'currency'          => (string) $t['currency'],
            ], $dailyTrends),
        ];
    }
}
