<?php
/**
 * GET  /api/admin-users.php?q=&status=&plan=&request=&page=   list
 * GET  /api/admin-users.php?id=N                               profile, licences, requests, downloads, history
 * POST /api/admin-users.php  { id, action: suspend|reactivate, reason? }
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Admin\AdminGuard;
use Diwan\Admin\AdminQueries;
use Diwan\Admin\CustomerAdmin;
use Diwan\Support\Http;

$admin = AdminGuard::require(['GET', 'POST']);
$queries = new AdminQueries();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['id'])) {
        Http::json(['user' => $queries->user(AdminGuard::id($_GET['id']))]);
    }
    Http::json($queries->users($_GET));
}

$input = Http::input();
$id = AdminGuard::id($input['id'] ?? null);
$reason = trim((string) ($input['reason'] ?? '')) ?: null;
match ($input['action'] ?? '') {
    'suspend'    => (new CustomerAdmin())->suspend($id, $admin, $reason),
    'reactivate' => (new CustomerAdmin())->reactivate($id, $admin, $reason),
    default      => Http::fail('Unknown action.', 422),
};
Http::json(['user' => $queries->user($id)]);
