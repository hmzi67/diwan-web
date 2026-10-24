<?php
/**
 * GET  /api/admin-notifications.php   admin inbox + recent customer notices with their email outcome
 * POST /api/admin-notifications.php  { ids?: [] }   mark admin notifications read (all when absent)
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Admin\AdminGuard;
use Diwan\Notification\NotificationService;
use Diwan\Support\Http;
use Diwan\Support\Mailer;

AdminGuard::require(['GET', 'POST']);
$notifications = new NotificationService();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ids = Http::input()['ids'] ?? null;
    $notifications->markAdminRead(is_array($ids) ? $ids : null);
}
Http::json([
    'inbox'            => $notifications->forAdmins(),
    'unread'           => $notifications->unreadCountForAdmins(),
    'customer_notices' => $notifications->recentCustomerNotifications(),
    'mail_configured'  => Mailer::isConfigured(),
]);
