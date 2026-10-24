<?php
/** GET /api/admin-audit-logs.php?action=&actor_type=&q=&page= — the audit trail, newest first. */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Admin\AdminGuard;
use Diwan\Admin\AdminQueries;
use Diwan\Support\Http;

AdminGuard::require(['GET']);
Http::json((new AdminQueries())->audit($_GET));
