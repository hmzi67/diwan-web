<?php
/** GET /api/admin-dashboard.php — overview KPIs and recent activity, from live tables. */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Admin\AdminGuard;
use Diwan\Admin\AdminQueries;
use Diwan\License\ExpiryReminders;
use Diwan\Support\Http;
use Diwan\Support\Logger;

AdminGuard::require(['GET']);

// Hosts without cron still get expiry reminders: at most once an hour,
// whenever an administrator looks at the dashboard. Never blocks the page.
try {
    (new ExpiryReminders())->runIfDue();
} catch (Throwable $e) {
    Logger::exception('Expiry reminders failed', $e);
}

Http::json((new AdminQueries())->dashboard());
