<?php
/**
 * GET /api/dashboard-data.php
 *
 * Everything the customer dashboard renders, in one call. Requires a valid
 * session cookie (Diwan\Auth\Session) — no cookie, no session, straight 401.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Auth\Customers;
use Diwan\Auth\Session;
use Diwan\Order\OrderService;
use Diwan\Support\Http;
use Diwan\Sync\SyncService;

Http::requireMethod('GET');

$customerId = Session::customerId();
if ($customerId === null) {
    Http::fail('Not logged in.', 401);
}

$customer = Customers::findById($customerId);
if (!$customer) {
    // Session cookie outlived the customer row (e.g. deleted account).
    Session::destroy();
    Http::fail('Session is no longer valid. Please log in again.', 401);
}

Http::json([
    'customer_id' => $customerId,
    'email'       => $customer['email'],
    'name'        => $customer['name'],
    'orders'      => (new OrderService())->dashboardRowsFor($customerId),
    'pos'         => (new SyncService())->dashboardDataFor($customerId),
]);
