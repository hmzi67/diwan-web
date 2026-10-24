<?php
/**
 * POST /api/pos-stock-reserve.php { key, lines: [{item_gid, qty}] }
 *
 * Takes an online sale's stock from the central balance before the counter
 * records the sale: { status: ok, taken, balances } or
 * { status: insufficient, shortages, balances } (nothing taken). Idempotent
 * per key. See Diwan\Workspace\SyncEngine::reserve().
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Support\Http;
use Diwan\Workspace\Api;
use Diwan\Workspace\SyncEngine;

Http::requireMethod('POST');

$input = Http::input();
Api::run(static fn (): array => (new SyncEngine())->reserve(
    Api::terminal(),
    (string) ($input['key'] ?? ''),
    is_array($input['lines'] ?? null) ? $input['lines'] : []
));
