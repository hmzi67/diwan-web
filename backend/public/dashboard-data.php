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
use Diwan\Database\Database;
use Diwan\License\LicenseRequestService;
use Diwan\Notification\NotificationService;
use Diwan\Order\OrderService;
use Diwan\Plan\PlanCatalog;
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

Customers::touch($customerId);
$catalog = new PlanCatalog();
$notifications = new NotificationService();
$downloads = Database::pdo()->prepare(
    "SELECT e.created_at, e.status, e.platform, e.variant, e.version
       FROM download_events e WHERE e.customer_id = :id ORDER BY e.id DESC LIMIT 20"
);
$downloads->execute(['id' => $customerId]);

Http::json([
    'customer_id' => $customerId,
    'email'       => $customer['email'],
    'name'        => $customer['name'],
    'orders'      => (new OrderService())->dashboardRowsFor($customerId),
    'pos'         => (new SyncService())->dashboardDataFor($customerId),
    'plan'        => [
        'current'  => $catalog->customerPlan($customerId),
        'requests' => (new LicenseRequestService())->forCustomer($customerId),
        'plans'    => $catalog->publicPlans(),
    ],
    'notifications' => [
        'items'  => $notifications->forCustomer($customerId, 20),
        'unread' => $notifications->unreadCountForCustomer($customerId),
    ],
    'downloads' => $downloads->fetchAll(),
]);
