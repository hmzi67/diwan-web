<?php
declare(strict_types=1);

use Diwan\Database\Database;
use Diwan\Plan\PlanCatalog;
use Diwan\Support\UserFacingException;

/** The public plan with this code. */
function publicPlan(string $code): array
{
    foreach ((new PlanCatalog())->publicPlans() as $plan) {
        if ($plan['code'] === $code) {
            return $plan;
        }
    }
    throw new TestFailure("plan {$code} is not public");
}

function planId(string $code): int
{
    return (int) TestKit::scalar('SELECT id FROM plans WHERE code = :code', ['code' => $code]);
}

return [
    'the three official plans have the published names, monthly prices and colours' => static function (): void {
        $expected = [
            'basic'      => ['BASIC', 1000000, 'blue'],
            'advance'    => ['ADVANCE', 1500000, 'green'],
            'all_in_one' => ['ALL-IN-ONE', 2000000, 'purple'],
        ];
        foreach ($expected as $code => [$name, $price, $theme]) {
            $plan = publicPlan($code);
            TestKit::same($name, $plan['name'], "{$code} name");
            TestKit::same($price, $plan['price_minor'], "{$code} price in paisa");
            TestKit::same('PKR', $plan['currency'], "{$code} currency");
            TestKit::same('month', $plan['billing_period'], "{$code} billed monthly");
            TestKit::same($theme, $plan['theme'], "{$code} colour");
        }
    },

    'each card lists exactly the features on the reference pricing' => static function (): void {
        $cards = [
            'basic' => ['POS System', 'Inventory Management', 'Sales & Purchase Reports', 'Expense Management',
                        'Daily Sales Tracking', 'User-Friendly Dashboard', 'Basic Customer Support'],
            'advance' => ['Complete POS System', 'Full Inventory Management', 'Warehouse Management',
                          'Advanced Reports & Analytics', 'AI Features', 'Multi-User Access',
                          'Purchase & Sales Management', 'Expense Tracking', 'Priority Support'],
            'all_in_one' => ['Complete Supply Chain Management', 'Warehouse & Branch Management', 'Business Website',
                             'Mobile Application (Android / iOS)', 'Online Order Management',
                             'Customer Management System', 'Dedicated Technical Support'],
        ];
        foreach ($cards as $code => $labels) {
            TestKit::same($labels, array_column(publicPlan($code)['features'], 'label'), "{$code} card");
        }
    },

    'BASIC unlocks only its own modules' => static function (): void {
        $keys = (new PlanCatalog())->entitlementsForPlan(planId('basic'));
        foreach (['billing', 'inventory', 'reports', 'expenses', 'dashboard', 'settings'] as $key) {
            TestKit::check(in_array($key, $keys, true), "BASIC has {$key}");
        }
        foreach (['inventory_advanced', 'reports_advanced', 'multi_user', 'employees', 'purchasing', 'ledger',
                  'tables', 'customers', 'delivery', 'mobile'] as $key) {
            TestKit::check(!in_array($key, $keys, true), "BASIC lacks {$key}");
        }
    },

    'ADVANCE adds its features but not ALL-IN-ONE ones' => static function (): void {
        $keys = (new PlanCatalog())->entitlementsForPlan(planId('advance'));
        foreach (['billing', 'inventory', 'inventory_advanced', 'reports_advanced', 'multi_user', 'purchasing',
                  'ledger', 'tables', 'expenses', 'dashboard'] as $key) {
            TestKit::check(in_array($key, $keys, true), "ADVANCE has {$key}");
        }
        foreach (['customers', 'delivery', 'mobile'] as $key) {
            TestKit::check(!in_array($key, $keys, true), "ADVANCE lacks {$key}");
        }
    },

    'ALL-IN-ONE unlocks every app module and capability' => static function (): void {
        $keys = (new PlanCatalog())->entitlementsForPlan(planId('all_in_one'));
        TestKit::same([], array_values(array_diff(PlanCatalog::POS_KEYS, $keys)), 'nothing missing');
    },

    'upgrade hints name the cheapest plan that includes a missing key' => static function (): void {
        $catalog = new PlanCatalog();
        $hints = $catalog->upgradeHints($catalog->entitlementsForPlan(planId('basic')));
        TestKit::same('ADVANCE', $hints['multi_user'] ?? null, 'multi-user');
        TestKit::same('ADVANCE', $hints['purchasing'] ?? null, 'purchasing');
        TestKit::same('ALL-IN-ONE', $hints['mobile'] ?? null, 'mobile app');
        TestKit::same('ALL-IN-ONE', $hints['customers'] ?? null, 'customers');
        TestKit::check(!isset($hints['billing']), 'nothing hinted for an owned key');
    },

    'a deactivated feature unlocks nothing on any plan' => static function (): void {
        $catalog = new PlanCatalog();
        Database::transaction(static fn (PDO $pdo) => $catalog->saveFeature($pdo, [
            'code' => 'test_extra', 'name' => 'Test extra', 'pos_entitlements' => 'customers', 'availability' => 'app',
        ]));
        $planId = Database::transaction(static fn (PDO $pdo) => $catalog->createPlan($pdo, [
            'code' => 'test_plan', 'name' => 'Test', 'price_minor' => 100, 'theme' => 'slate', 'is_active' => 0,
        ]));
        Database::transaction(static fn (PDO $pdo) => $catalog->setPlanFeatures($pdo, $planId, [['code' => 'test_extra', 'show_on_card' => 1]]));
        TestKit::same(['customers'], $catalog->entitlementsForPlan($planId), 'active feature counts');
        Database::transaction(static fn (PDO $pdo) => $catalog->saveFeature($pdo, [
            'code' => 'test_extra', 'name' => 'Test extra', 'pos_entitlements' => 'customers', 'availability' => 'app', 'is_active' => 0,
        ]));
        TestKit::same([], $catalog->entitlementsForPlan($planId), 'deactivated feature counts for nothing');
    },

    'editing one plan changes only that plan, and an inactive plan is hidden and unrequestable' => static function (): void {
        $catalog = new PlanCatalog();
        $before = $catalog->entitlementsForPlan(planId('basic'));
        $planId = Database::transaction(static fn (PDO $pdo) => $catalog->createPlan($pdo, [
            'code' => 'test_hidden', 'name' => 'Hidden', 'price_minor' => 5, 'theme' => 'blue', 'is_active' => 0,
        ]));
        Database::transaction(static fn (PDO $pdo) => $catalog->setPlanFeatures($pdo, $planId, [['code' => 'mobile_application', 'show_on_card' => 1]]));
        TestKit::same($before, $catalog->entitlementsForPlan(planId('basic')), 'BASIC unchanged');
        TestKit::check(!in_array('test_hidden', array_column($catalog->publicPlans(), 'code'), true), 'not on the pricing page');
        TestKit::throws(UserFacingException::class,
            static fn () => (new Diwan\License\LicenseRequestService())->submit(TestKit::verifiedCustomer(), 'test_hidden', []),
            'an inactive plan cannot be requested', 'not available');
    },

    'plan and feature input is validated, never trusted' => static function (): void {
        $catalog = new PlanCatalog();
        foreach ([
            ['code' => 'test_bad', 'name' => 'X', 'price_minor' => -1],
            ['code' => 'test_bad', 'name' => 'X', 'price_minor' => '12.5'],
            ['code' => 'test_bad', 'name' => '', 'price_minor' => 1],
            ['code' => 'Bad Code', 'name' => 'X', 'price_minor' => 1],
            ['code' => 'test_bad', 'name' => 'X', 'price_minor' => 1, 'theme' => 'red'],
        ] as $i => $input) {
            TestKit::throws(UserFacingException::class,
                static fn () => Database::transaction(static fn (PDO $pdo) => $catalog->createPlan($pdo, $input)), "bad plan {$i}");
        }
        TestKit::throws(UserFacingException::class, static fn () => Database::transaction(static fn (PDO $pdo) =>
            $catalog->saveFeature($pdo, ['code' => 'test_f', 'name' => 'F', 'pos_entitlements' => 'billing,admin_panel'])),
            'unknown app key refused', 'Unknown app module');
        TestKit::throws(UserFacingException::class, static fn () => Database::transaction(static fn (PDO $pdo) =>
            $catalog->updatePlan($pdo, planId('basic'), ['price_minor' => 'free'])), 'price must be an integer');
        TestKit::same(1000000, publicPlan('basic')['price_minor'], 'BASIC price untouched by the refused edits');
    },
];
