<?php
/**
 * POST /api/pos-sync-push.php
 *   { changes: [{id, entity, gid, op: upsert|delete, data}],
 *     stock:   [{key, item_gid, qty}],
 *     releases: [reservation key],
 *     pending, last_error }
 *
 * A counter's local changes, applied to its business in one transaction.
 * Answers which change ids, stock keys and releases were taken; anything not
 * listed stays queued on the counter. See Diwan\Workspace\SyncEngine.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Support\Http;
use Diwan\Workspace\Api;
use Diwan\Workspace\SyncEngine;

Http::requireMethod('POST');

Api::run(static fn (): array => (new SyncEngine())->push(Api::terminal(), Http::input()));
