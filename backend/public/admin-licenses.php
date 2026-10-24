<?php
/**
 * GET  /api/admin-licenses.php?q=&plan=&status=&page=
 * GET  /api/admin-licenses.php?id=N          detail: devices and history
 * POST /api/admin-licenses.php  { id, action, ... }
 *   suspend | reactivate | revoke   { reason? }
 *   extend                          { days, reason? }
 *   change_plan                     { plan_id }
 *   set_devices                     { max_devices }
 *   deactivate_device               { activation_id }
 *   reset_devices
 *   rotate_key                      → the new key, shown once
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Admin\AdminGuard;
use Diwan\Admin\AdminQueries;
use Diwan\License\LicenseAdmin;
use Diwan\Support\Http;

$admin = AdminGuard::require(['GET', 'POST']);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['id'])) {
        Http::json(['license' => (new LicenseAdmin())->detail(AdminGuard::id($_GET['id']))]);
    }
    Http::json((new AdminQueries())->licenses($_GET));
}

$input = Http::input();
Http::json((new LicenseAdmin())->apply(
    AdminGuard::id($input['id'] ?? null),
    (string) ($input['action'] ?? ''),
    $input,
    $admin
));
