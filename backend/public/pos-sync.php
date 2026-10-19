<?php
/**
 * POST /api/pos-sync.php
 *
 * Ingests sales and daily summaries from an authenticated POS terminal.
 * Authenticates using the active licence key and machine fingerprint.
 *
 * Request body JSON:
 *   {
 *     "license_key": "DWN-...",
 *     "machine_fingerprint": "...",
 *     "machine_hint": "Counter 1",
 *     "customer_id": 123,
 *     "sales": [ ... ],
 *     "daily_summaries": [ ... ]
 *   }
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Support\DeviceInput;
use Diwan\Support\Http;
use Diwan\Support\Logger;
use Diwan\Sync\SyncService;

Http::requireMethod('POST');

$input = Http::input();
$key = trim((string) ($input['license_key'] ?? ''));

if ($key === '') {
    Http::fail('Please provide a licence key.', 422);
}

$fingerprint = DeviceInput::fingerprint($input);
$hint = DeviceInput::hint($input);
$sales = isset($input['sales']) && is_array($input['sales']) ? $input['sales'] : [];
$dailySummaries = isset($input['daily_summaries']) && is_array($input['daily_summaries']) ? $input['daily_summaries'] : [];
$records = isset($input['records']) && is_array($input['records']) ? $input['records'] : [];
$claimedCustomerId = isset($input['customer_id']) ? (int) $input['customer_id'] : null;

try {
    $service = new SyncService();
    $result = $service->sync($key, $fingerprint, $hint, $sales, $dailySummaries, $claimedCustomerId, $records);
    Http::json($result);
} catch (\Throwable $e) {
    Logger::warning('POS sync failed', ['error' => $e->getMessage()]);
    Http::fail($e->getMessage(), 403);
}
