<?php
/**
 * GET  /api/notifications.php              the signed-in customer's notifications
 * POST /api/notifications.php  { ids?: [] }  mark read (all when ids is absent)
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Auth\Session;
use Diwan\Notification\NotificationService;
use Diwan\Support\Http;

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    Http::fail('Method not allowed', 405);
}
$customerId = Session::customerId();
if ($customerId === null) {
    Http::fail('Not logged in.', 401);
}

$notifications = new NotificationService();
if ($method === 'POST') {
    $ids = Http::input()['ids'] ?? null;
    $notifications->markCustomerRead($customerId, is_array($ids) ? $ids : null);
}
Http::json([
    'notifications' => $notifications->forCustomer($customerId),
    'unread'        => $notifications->unreadCountForCustomer($customerId),
]);
