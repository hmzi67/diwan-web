<?php
/**
 * GET /api/pos-sync-pull.php?since=N&limit=M[&pending=P&error=E]
 *
 * Every record and stock balance of the counter's business that changed
 * after version N, oldest first, plus the business's counters. `cursor` is
 * the N for the next call and `more` says whether to call again at once.
 * `pending` / `error` are the counter's own queue size and last sync error,
 * kept for the admin panel (an empty `error` clears it).
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Support\Http;
use Diwan\Workspace\Api;
use Diwan\Workspace\SyncEngine;

Http::requireMethod('GET');

$report = [];
if (isset($_GET['pending']) && ctype_digit((string) $_GET['pending'])) {
    $report['pending'] = (int) $_GET['pending'];
}
if (isset($_GET['error']) && is_string($_GET['error'])) {
    $report['last_error'] = $_GET['error'];
}

Api::run(static fn (): array => (new SyncEngine())->pull(
    Api::terminal(),
    max(0, (int) ($_GET['since'] ?? 0)),
    (int) ($_GET['limit'] ?? SyncEngine::MAX_PULL),
    $report
));
