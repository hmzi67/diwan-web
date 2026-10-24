<?php
/**
 * GET  /api/admin-businesses.php?q=&attention=&page=   business workspaces
 * GET  /api/admin-businesses.php?id=N                  one business: counters, conflicts, totals, history
 * GET  /api/admin-businesses.php?load=1                sync requests per hour (server load) and stored images
 * POST /api/admin-businesses.php  { id, action, ... }
 *   rename_terminal        { terminal_id, name }
 *   revoke_terminal        { terminal_id, reason? }   disconnects the counter and frees its device slot
 *   resolve_conflict       { conflict_id }
 *   resolve_all_conflicts
 *
 * See Diwan\Workspace\WorkspaceAdmin and docs/MULTI-COUNTER.md.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Admin\AdminGuard;
use Diwan\Support\Http;
use Diwan\Workspace\WorkspaceAdmin;

$admin = AdminGuard::require(['GET', 'POST']);
$workspaces = new WorkspaceAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['load'])) {
        Http::json(['load' => $workspaces->load()]);
    }
    if (isset($_GET['id'])) {
        Http::json(['business' => $workspaces->detail(AdminGuard::id($_GET['id']))]);
    }
    Http::json($workspaces->businesses($_GET));
}

$input = Http::input();
Http::json($workspaces->apply(AdminGuard::id($input['id'] ?? null), (string) ($input['action'] ?? ''), $input, $admin));
