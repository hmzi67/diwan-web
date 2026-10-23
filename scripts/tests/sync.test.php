<?php
declare(strict_types=1);

use Diwan\License\LicenseService;
use Diwan\Sync\SyncService;

/**
 * A paid licence activated on a fresh device: [customer id, licence key,
 * raw device fingerprint] — what a POS terminal syncs with.
 */
function syncedTerminal(): array
{
    $customerId = TestKit::customer();
    [, $key] = TestKit::paidLicense($customerId);
    $fingerprint = hash('sha256', 'till-' . bin2hex(random_bytes(6)));
    TestKit::same('activated', (new LicenseService())->activate($key, hash('sha256', $fingerprint), 'Till')['result'], 'activation');
    return [$customerId, $key, $fingerprint];
}

/** @return list<array<string, mixed>> */
function mirrored(int $customerId, string $entity): array
{
    $records = (new SyncService())->dashboardDataFor($customerId)['records'][$entity] ?? [];
    usort($records, static fn (array $a, array $b): int => ($a['id'] ?? 0) <=> ($b['id'] ?? 0));
    return $records;
}

return [
    'records are mirrored even when a sync carries no sales or summaries' => static function (): void {
        [$customerId, $key, $fingerprint] = syncedTerminal();
        $result = (new SyncService())->sync($key, $fingerprint, 'Till', [], [], null, [
            'customers' => [['id' => 1, 'name' => 'Bilal'], ['id' => 2, 'name' => 'Sana']],
        ]);
        TestKit::same(2, $result['synced_records'], 'synced record count');
        TestKit::same(['Bilal', 'Sana'], array_column(mirrored($customerId, 'customers'), 'name'), 'stored rows');
    },

    'a resync replaces an entity, so rows deleted on the POS disappear' => static function (): void {
        [$customerId, $key, $fingerprint] = syncedTerminal();
        $sync = new SyncService();
        $sync->sync($key, $fingerprint, null, [], [], null, [
            'suppliers' => [['id' => 1, 'name' => 'Metro'], ['id' => 2, 'name' => 'Makro']],
            'expenses'  => [['id' => 9, 'amount_minor' => 500]],
        ]);
        $sync->sync($key, $fingerprint, null, [], [], null, [
            'suppliers' => [['id' => 2, 'name' => 'Makro Cash & Carry']],
        ]);
        TestKit::same(['Makro Cash & Carry'], array_column(mirrored($customerId, 'suppliers'), 'name'), 'only the surviving supplier');
        TestKit::same(1, count(mirrored($customerId, 'expenses')), 'an entity not sent this time is left alone');
    },

    'password and PIN hashes are never stored' => static function (): void {
        [$customerId, $key, $fingerprint] = syncedTerminal();
        (new SyncService())->sync($key, $fingerprint, null, [], [], null, [
            'app_config' => [['id' => 1, 'business_name' => 'Chai Spot', 'sensitive_area_pin_hash' => '$argon2id$v=19$secret']],
        ]);
        $config = mirrored($customerId, 'app_config')[0];
        TestKit::same('Chai Spot', $config['business_name'], 'ordinary fields kept');
        TestKit::check(!array_key_exists('sensitive_area_pin_hash', $config), 'the PIN hash is dropped');
        $raw = (string) TestKit::scalar(
            'SELECT record_json FROM pos_records WHERE customer_id = :id AND entity_name = :entity',
            ['id' => $customerId, 'entity' => 'app_config']
        );
        TestKit::check(!str_contains($raw, 'argon2'), 'nothing of the hash reaches the database');
    },

    'the dashboard splits a split or pay-later sale by tender, and reads older sales by method' => static function (): void {
        [$customerId, $key, $fingerprint] = syncedTerminal();
        $now = (string) TestKit::scalar('SELECT DATE_FORMAT(NOW(), "%Y-%m-%d %H:%i:%s")');
        $sale = static fn (int $id, string $method, int $total, array $tenders = []): array => array_merge([
            'local_sale_id' => $id, 'total_minor' => $total, 'payment_method' => $method,
            'currency' => 'PKR', 'items_count' => 1, 'sale_created_at' => $now,
        ], $tenders);
        (new SyncService())->sync($key, $fingerprint, null, [
            $sale(1, 'split', 10000, ['cash_minor' => 3000, 'card_minor' => 2000, 'other_minor' => 0, 'credit_minor' => 5000,
                'service_charge_minor' => 500, 'customer_name' => 'Hina']),
            $sale(2, 'card', 4000),
        ], [], null, []);

        $data = (new SyncService())->dashboardDataFor($customerId);
        $today = $data['summary']['today'];
        TestKit::same(3000, $today['cash_minor'], 'cash: only the cash part of the split bill');
        TestKit::same(6000, $today['card_minor'], 'card: the split part plus the older card sale');
        TestKit::same(0, $today['other_minor'], 'pay-later is not "other"');
        TestKit::same(5000, $today['credit_minor'], 'pay-later part');
        TestKit::same(500, $today['service_charge_minor'], 'service charge');
        $split = array_values(array_filter($data['recent_sales'], static fn (array $s): bool => $s['local_sale_id'] === 1))[0];
        TestKit::same('Hina', $split['customer_name'], 'customer name');
        TestKit::same(5000, $split['credit_minor'], 'credit on the sale row');
    },

    'a sync from a device the licence is not bound to is refused' => static function (): void {
        [, $key] = syncedTerminal();
        TestKit::throws(
            RuntimeException::class,
            static fn () => (new SyncService())->sync($key, hash('sha256', 'stranger'), null, [], [], null, ['customers' => [['id' => 1]]]),
            'unbound device',
            'Invalid or unactivated'
        );
    },
];
