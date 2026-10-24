<?php
/**
 * GET  /api/admin-plans.php → { plans, features, pos_keys }
 * POST /api/admin-plans.php  { action: "create_plan", plan: {...} }
 * POST /api/admin-plans.php  { action: "update_plan", id, plan: {...} }
 * POST /api/admin-plans.php  { action: "set_features", id, features: [{code, show_on_card, display_label?, sort_order?}] }
 * POST /api/admin-plans.php  { action: "save_feature", feature: {code, name, description?, pos_entitlements, availability, is_active} }
 *
 * Feature changes apply to every licence on the plan at its next check-in;
 * the response's `affected_licenses` says how many that is, and the audit log
 * records exactly what was added and removed.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Admin\AdminGuard;
use Diwan\Database\Database;
use Diwan\Plan\PlanCatalog;
use Diwan\Support\AuditLog;
use Diwan\Support\Http;

$admin = AdminGuard::require(['GET', 'POST']);
$catalog = new PlanCatalog();

$respond = static function () use ($catalog): never {
    Http::json([
        'plans'    => $catalog->adminPlans(),
        'features' => $catalog->features(),
        'pos_keys' => PlanCatalog::POS_KEYS,
    ]);
};
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $respond();
}

$input = Http::input();
$audit = static fn (string $action, ?string $type, ?int $id, array $details, PDO $pdo) =>
    AuditLog::record('admin', (int) $admin['id'], (string) $admin['email'], $action, $type, $id, $details, $pdo);

switch ($input['action'] ?? '') {
    case 'create_plan':
        Database::transaction(static function (PDO $pdo) use ($catalog, $input, $audit): void {
            $plan = is_array($input['plan'] ?? null) ? $input['plan'] : [];
            $id = $catalog->createPlan($pdo, $plan);
            $audit('plan.create', 'plan', $id, ['code' => $plan['code'] ?? null, 'price_minor' => $plan['price_minor'] ?? null], $pdo);
        });
        break;

    case 'update_plan':
        $id = AdminGuard::id($input['id'] ?? null);
        Database::transaction(static function (PDO $pdo) use ($catalog, $input, $audit, $id): void {
            $changed = $catalog->updatePlan($pdo, $id, is_array($input['plan'] ?? null) ? $input['plan'] : []);
            if ($changed !== []) {
                $audit('plan.update', 'plan', $id, $changed, $pdo);
            }
        });
        break;

    case 'set_features':
        $id = AdminGuard::id($input['id'] ?? null);
        Database::transaction(static function (PDO $pdo) use ($catalog, $input, $audit, $id): void {
            $diff = $catalog->setPlanFeatures($pdo, $id, is_array($input['features'] ?? null) ? $input['features'] : []);
            $audit('plan.set_features', 'plan', $id, $diff, $pdo);
        });
        break;

    case 'save_feature':
        Database::transaction(static function (PDO $pdo) use ($catalog, $input, $audit): void {
            $feature = is_array($input['feature'] ?? null) ? $input['feature'] : [];
            $saved = $catalog->saveFeature($pdo, $feature);
            $audit($saved['created'] ? 'feature.create' : 'feature.update', 'feature', $saved['id'], [
                'code'             => $feature['code'] ?? null,
                'pos_entitlements' => $feature['pos_entitlements'] ?? null,
                'is_active'        => $feature['is_active'] ?? null,
            ], $pdo);
        });
        break;

    default:
        Http::fail('Unknown action.', 422);
}
$respond();
