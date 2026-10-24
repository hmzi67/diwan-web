<?php
/**
 * GET /api/plans.php
 *
 * The public pricing cards: active plans, their monthly price (integer
 * paisa) and the features each card lists. Read from the same tables the
 * licence entitlements come from (Diwan\Plan\PlanCatalog), so the website
 * can never advertise something different from what a licence unlocks.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Plan\PlanCatalog;
use Diwan\Support\Http;

Http::requireMethod('GET');
header('Cache-Control: public, max-age=60');
Http::json(['plans' => (new PlanCatalog())->publicPlans()]);
