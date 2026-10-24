<?php
/**
 * GET  /api/admin-license-requests.php?status=&plan=&q=&page=
 * POST /api/admin-license-requests.php  { id, action: "approve", plan_code?, period_days?, max_devices?, note? }
 * POST /api/admin-license-requests.php  { id, action: "reject", note? }
 *
 * Approving is transactional and idempotent (LicenseRequestService): a
 * repeated approval returns the licence the first one issued. A newly issued
 * key appears in this response once, for the administrator to hand over if
 * needed; it is also in the customer's own dashboard.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Admin\AdminGuard;
use Diwan\Admin\AdminQueries;
use Diwan\License\LicenseRequestService;
use Diwan\Support\Http;

$admin = AdminGuard::require(['GET', 'POST']);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    Http::json((new AdminQueries())->requests($_GET));
}

$input = Http::input();
$id = AdminGuard::id($input['id'] ?? null);
$requests = new LicenseRequestService();
match ($input['action'] ?? '') {
    'approve' => Http::json($requests->approve($id, $admin, [
        'plan_code'   => $input['plan_code'] ?? null,
        'period_days' => $input['period_days'] ?? null,
        'max_devices' => $input['max_devices'] ?? null,
        'note'        => $input['note'] ?? null,
    ])),
    'reject'  => Http::json(['request' => $requests->reject($id, $admin, isset($input['note']) ? (string) $input['note'] : null)]),
    default   => Http::fail('Unknown action.', 422),
};
