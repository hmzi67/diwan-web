<?php
/**
 * GET /api/admin-session.php → { admin, csrf_token, counts }
 *
 * What the admin panel loads first: who is signed in, the CSRF token every
 * POST must send as X-CSRF-Token, and the badge counts for the sidebar.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Admin\AdminGuard;
use Diwan\Admin\AdminSession;
use Diwan\Database\Database;
use Diwan\Notification\NotificationService;
use Diwan\Support\Http;

$admin = AdminGuard::require(['GET']);
Http::json([
    'admin'      => ['id' => (int) $admin['id'], 'email' => $admin['email'], 'name' => $admin['name'],
                     'last_login_at' => $admin['last_login_at']],
    'csrf_token' => AdminSession::csrfToken(),
    'counts'     => [
        'pending_requests'     => (int) Database::pdo()->query("SELECT COUNT(*) FROM license_requests WHERE status = 'pending'")->fetchColumn(),
        'unread_notifications' => (new NotificationService())->unreadCountForAdmins(),
        'open_sync_conflicts'  => (new Diwan\Workspace\WorkspaceAdmin())->summary()['open_conflicts'],
    ],
]);
