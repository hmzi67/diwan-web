<?php
/** POST /api/admin-logout.php  { everywhere?: bool } — ends the admin session. */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Admin\AdminGuard;
use Diwan\Admin\AdminSession;
use Diwan\Support\AuditLog;
use Diwan\Support\Http;

$admin = AdminGuard::require(['POST']);
if (!empty(Http::input()['everywhere'])) {
    AdminSession::revokeAll((int) $admin['id']);
}
AuditLog::record('admin', (int) $admin['id'], (string) $admin['email'], 'admin.sign_out');
AdminSession::destroy();
Http::json(['status' => 'ok']);
