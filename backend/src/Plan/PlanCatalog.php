<?php
declare(strict_types=1);

namespace Diwan\Plan;

use Diwan\Database\Database;
use Diwan\Support\UserFacingException;
use PDO;

/**
 * Plans, features and what they unlock — the single source of truth.
 *
 * The public pricing cards (plans.php), the admin plan editor and the signed
 * licence payload the POS enforces all read from here, so a price or a
 * feature can never be shown one way and enforced another.
 *
 * Entitlements are resolved live from the plan at every licence check-in:
 * editing a plan's features changes what every licence on that plan unlocks
 * (the admin panel says how many licences that affects and asks first).
 * A licence with no plan (issued before plans existed) is unrestricted, so
 * no existing customer silently loses a module.
 */
final class PlanCatalog
{
    /**
     * Every key a feature may unlock in the POS. Module keys mirror
     * db::modules::key in the POS app; the last four are capabilities the
     * app checks inside a module. Keep in step with the POS.
     */
    public const POS_KEYS = [
        'dashboard', 'billing', 'inventory', 'reports', 'tables', 'attendance', 'expenses',
        'salary', 'shifts', 'employees', 'users', 'settings', 'delivery', 'customers',
        'purchasing', 'ledger',
        'inventory_advanced', 'reports_advanced', 'multi_user', 'mobile',
    ];

    public const THEMES = ['blue', 'green', 'purple', 'slate'];
    public const AVAILABILITY = ['app', 'service', 'planned'];

    /** Active plans and the features each card lists, cheapest first. */
    public function publicPlans(): array
    {
        $plans = Database::pdo()->query(
            'SELECT id, code, name, tagline, price_minor, currency, billing_period, theme, cta_label
               FROM plans WHERE is_active = 1 ORDER BY sort_order, price_minor'
        )->fetchAll();
        $cards = $this->cardFeatures(array_column($plans, 'id'));
        return array_map(static fn (array $p): array => [
            'code'           => $p['code'],
            'name'           => $p['name'],
            'tagline'        => $p['tagline'],
            'price_minor'    => (int) $p['price_minor'],
            'currency'       => $p['currency'],
            'billing_period' => $p['billing_period'],
            'theme'          => $p['theme'],
            'cta_label'      => $p['cta_label'],
            'features'       => $cards[(int) $p['id']] ?? [],
        ], $plans);
    }

    /** Every plan, active or not, with its full feature list and licence counts. */
    public function adminPlans(): array
    {
        $plans = Database::pdo()->query(
            "SELECT p.*,
                    (SELECT COUNT(*) FROM licenses l WHERE l.plan_id = p.id) AS license_count,
                    (SELECT COUNT(*) FROM licenses l WHERE l.plan_id = p.id AND l.status = 'active'
                        AND (l.expires_at IS NULL OR l.expires_at > NOW())) AS active_license_count,
                    (SELECT COUNT(DISTINCT l.customer_id) FROM licenses l WHERE l.plan_id = p.id AND l.status = 'active'
                        AND (l.expires_at IS NULL OR l.expires_at > NOW())) AS active_customer_count
               FROM plans p ORDER BY p.sort_order, p.price_minor"
        )->fetchAll();
        $rows = Database::pdo()->query(
            'SELECT pf.plan_id, f.code, f.name, pf.display_label, pf.show_on_card, pf.sort_order
               FROM plan_features pf JOIN features f ON f.id = pf.feature_id
              ORDER BY pf.plan_id, pf.show_on_card DESC, pf.sort_order, f.name'
        )->fetchAll();
        $byPlan = [];
        foreach ($rows as $r) {
            $byPlan[(int) $r['plan_id']][] = [
                'code'          => $r['code'],
                'name'          => $r['name'],
                'display_label' => $r['display_label'],
                'show_on_card'  => (bool) $r['show_on_card'],
                'sort_order'    => (int) $r['sort_order'],
            ];
        }
        return array_map(function (array $p) use ($byPlan): array {
            $id = (int) $p['id'];
            return [
                'id'                    => $id,
                'code'                  => $p['code'],
                'name'                  => $p['name'],
                'tagline'               => $p['tagline'],
                'price_minor'           => (int) $p['price_minor'],
                'currency'              => $p['currency'],
                'billing_period'        => $p['billing_period'],
                'theme'                 => $p['theme'],
                'cta_label'             => $p['cta_label'],
                'max_devices'           => (int) $p['max_devices'],
                'sort_order'            => (int) $p['sort_order'],
                'is_active'             => (bool) $p['is_active'],
                'license_count'         => (int) $p['license_count'],
                'active_license_count'  => (int) $p['active_license_count'],
                'active_customer_count' => (int) $p['active_customer_count'],
                'features'              => $byPlan[$id] ?? [],
                'entitlements'          => $this->entitlementsForPlan($id),
            ];
        }, $plans);
    }

    /** @return list<array<string,mixed>> */
    public function features(): array
    {
        return array_map(static fn (array $f): array => [
            'id'               => (int) $f['id'],
            'code'             => $f['code'],
            'name'             => $f['name'],
            'description'      => $f['description'],
            'pos_entitlements' => self::splitKeys((string) $f['pos_entitlements']),
            'availability'     => $f['availability'],
            'is_active'        => (bool) $f['is_active'],
            'plan_count'       => (int) $f['plan_count'],
        ], Database::pdo()->query(
            'SELECT f.*, (SELECT COUNT(*) FROM plan_features pf WHERE pf.feature_id = f.id) AS plan_count
               FROM features f ORDER BY f.name'
        )->fetchAll());
    }

    public function findById(int $planId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM plans WHERE id = :id');
        $stmt->execute(['id' => $planId]);
        return $stmt->fetch() ?: null;
    }

    public function findActiveByCode(string $code): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM plans WHERE code = :code AND is_active = 1');
        $stmt->execute(['code' => $code]);
        return $stmt->fetch() ?: null;
    }

    /**
     * The sorted POS keys a plan unlocks: the union of its active features'
     * pos_entitlements. A deactivated feature unlocks nothing anywhere.
     *
     * @return list<string>
     */
    public function entitlementsForPlan(int $planId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT f.pos_entitlements FROM plan_features pf JOIN features f ON f.id = pf.feature_id
              WHERE pf.plan_id = :plan AND f.is_active = 1'
        );
        $stmt->execute(['plan' => $planId]);
        $keys = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $csv) {
            foreach (self::splitKeys((string) $csv) as $key) {
                $keys[$key] = true;
            }
        }
        $keys = array_keys($keys);
        sort($keys);
        return $keys;
    }

    /**
     * The feature codes a plan includes (active features only), for the
     * customer dashboard and the server-side checks that are not POS keys.
     *
     * @return list<string>
     */
    public function featureCodesForPlan(int $planId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT f.code FROM plan_features pf JOIN features f ON f.id = pf.feature_id
              WHERE pf.plan_id = :plan AND f.is_active = 1 ORDER BY f.code'
        );
        $stmt->execute(['plan' => $planId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * For each POS key the given entitlements lack, the name of the cheapest
     * active plan that unlocks it — what the app's upgrade message names.
     *
     * @param list<string> $entitled
     * @return array<string,string>
     */
    public function upgradeHints(array $entitled): array
    {
        $plans = Database::pdo()->query(
            'SELECT id, name FROM plans WHERE is_active = 1 ORDER BY sort_order, price_minor'
        )->fetchAll();
        $missing = array_diff(self::POS_KEYS, $entitled);
        $hints = [];
        foreach ($plans as $plan) {
            foreach (array_intersect($missing, $this->entitlementsForPlan((int) $plan['id'])) as $key) {
                $hints[$key] ??= (string) $plan['name'];
            }
        }
        ksort($hints);
        return $hints;
    }

    /**
     * The customer's current plan licence (newest that is not revoked), with
     * what it includes — for their dashboard. Null when they have none.
     */
    public function customerPlan(int $customerId): ?array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT l.id AS license_id, l.status, l.activation_status, l.max_devices, l.plan_id,
                    UNIX_TIMESTAMP(l.expires_at) AS expires_at,
                    (l.expires_at IS NOT NULL AND l.expires_at <= NOW()) AS past_expiry,
                    p.code AS plan_code, p.name AS plan_name, p.theme,
                    (SELECT COUNT(*) FROM license_activations a WHERE a.license_id = l.id AND a.deactivated_at IS NULL) AS devices_used
               FROM licenses l JOIN plans p ON p.id = l.plan_id
              WHERE l.customer_id = :id AND l.status <> 'revoked'
              ORDER BY l.id DESC LIMIT 1"
        );
        $stmt->execute(['id' => $customerId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $features = Database::pdo()->prepare(
            'SELECT f.code, COALESCE(pf.display_label, f.name) AS name, f.description, f.availability
               FROM plan_features pf JOIN features f ON f.id = pf.feature_id
              WHERE pf.plan_id = :plan AND f.is_active = 1
              ORDER BY pf.show_on_card DESC, pf.sort_order, f.name'
        );
        $features->execute(['plan' => $row['plan_id']]);
        $status = $row['status'] === 'active' && (bool) $row['past_expiry'] ? 'expired' : $row['status'];
        return [
            'license_id'   => (int) $row['license_id'],
            'plan_code'    => $row['plan_code'],
            'plan_name'    => $row['plan_name'],
            'theme'        => $row['theme'],
            'status'       => $status,
            'activated'    => $row['activation_status'] === 'activated',
            'expires_at'   => $row['expires_at'] === null ? null : (int) $row['expires_at'],
            'devices_used' => (int) $row['devices_used'],
            'max_devices'  => (int) $row['max_devices'],
            'features'     => $features->fetchAll(),
        ];
    }

    // -- Admin edits ---------------------------------------------------------

    /** Creates a plan; returns its id. */
    public function createPlan(PDO $pdo, array $input): int
    {
        $code = strtolower(trim((string) ($input['code'] ?? '')));
        if (preg_match('/^[a-z][a-z0-9_]{1,39}$/', $code) !== 1) {
            throw new UserFacingException('Plan code must be 2–40 lowercase letters, digits or underscores.');
        }
        $exists = $pdo->prepare('SELECT 1 FROM plans WHERE code = :code');
        $exists->execute(['code' => $code]);
        if ($exists->fetchColumn()) {
            throw new UserFacingException('A plan with that code already exists.', 409);
        }
        $fields = $this->planFields($input, true);
        $pdo->prepare(
            'INSERT INTO plans (code, name, tagline, price_minor, currency, billing_period, theme, cta_label,
                                max_devices, sort_order, is_active, created_at)
             VALUES (:code, :name, :tagline, :price_minor, :currency, :billing_period, :theme, :cta_label,
                     :max_devices, :sort_order, :is_active, NOW())'
        )->execute(['code' => $code] + $fields);
        return (int) $pdo->lastInsertId();
    }

    /** Updates the plan's own fields (only those present in $input). Returns the changed fields. */
    public function updatePlan(PDO $pdo, int $planId, array $input): array
    {
        $current = $pdo->prepare('SELECT * FROM plans WHERE id = :id FOR UPDATE');
        $current->execute(['id' => $planId]);
        $plan = $current->fetch();
        if (!$plan) {
            throw new UserFacingException('Plan not found.', 404);
        }
        $fields = $this->planFields($input + $plan, false);
        $changed = [];
        foreach ($fields as $key => $value) {
            if ((string) $plan[$key] !== (string) $value) {
                $changed[$key] = ['from' => $plan[$key], 'to' => $value];
            }
        }
        if ($changed !== []) {
            $sets = implode(', ', array_map(static fn (string $k): string => "{$k} = :{$k}", array_keys($fields)));
            $pdo->prepare("UPDATE plans SET {$sets}, updated_at = NOW() WHERE id = :id")
                ->execute($fields + ['id' => $planId]);
        }
        return $changed;
    }

    /**
     * Replaces a plan's feature list. Each item: {code, show_on_card, display_label?, sort_order?}.
     * Returns ['added' => codes, 'removed' => codes].
     */
    public function setPlanFeatures(PDO $pdo, int $planId, array $items): array
    {
        if ($this->findById($planId) === null) {
            throw new UserFacingException('Plan not found.', 404);
        }
        $ids = $pdo->query('SELECT code, id FROM features')->fetchAll(PDO::FETCH_KEY_PAIR);
        $rows = [];
        foreach ($items as $i => $item) {
            $code = (string) ($item['code'] ?? '');
            if (!isset($ids[$code])) {
                throw new UserFacingException("Unknown feature: {$code}");
            }
            $label = trim((string) ($item['display_label'] ?? ''));
            if (mb_strlen($label) > 120) {
                throw new UserFacingException('A card label can be at most 120 characters.');
            }
            $rows[$code] = [
                'feature_id'    => (int) $ids[$code],
                'display_label' => $label === '' ? null : $label,
                'show_on_card'  => !empty($item['show_on_card']) ? 1 : 0,
                'sort_order'    => (int) ($item['sort_order'] ?? $i + 1),
            ];
        }
        $before = $pdo->prepare('SELECT f.code FROM plan_features pf JOIN features f ON f.id = pf.feature_id WHERE pf.plan_id = :plan');
        $before->execute(['plan' => $planId]);
        $before = $before->fetchAll(PDO::FETCH_COLUMN);

        $pdo->prepare('DELETE FROM plan_features WHERE plan_id = :plan')->execute(['plan' => $planId]);
        $insert = $pdo->prepare(
            'INSERT INTO plan_features (plan_id, feature_id, display_label, show_on_card, sort_order)
             VALUES (:plan, :feature_id, :display_label, :show_on_card, :sort_order)'
        );
        foreach ($rows as $row) {
            $insert->execute(['plan' => $planId] + $row);
        }
        $pdo->prepare('UPDATE plans SET updated_at = NOW() WHERE id = :id')->execute(['id' => $planId]);
        return [
            'added'   => array_values(array_diff(array_keys($rows), $before)),
            'removed' => array_values(array_diff($before, array_keys($rows))),
        ];
    }

    /** Creates or updates a feature by code. Returns ['id' => int, 'created' => bool]. */
    public function saveFeature(PDO $pdo, array $input): array
    {
        $code = strtolower(trim((string) ($input['code'] ?? '')));
        if (preg_match('/^[a-z][a-z0-9_]{1,59}$/', $code) !== 1) {
            throw new UserFacingException('Feature code must be 2–60 lowercase letters, digits or underscores.');
        }
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new UserFacingException('Feature name is required (at most 120 characters).');
        }
        $description = trim((string) ($input['description'] ?? ''));
        if (mb_strlen($description) > 500) {
            throw new UserFacingException('Description can be at most 500 characters.');
        }
        $keys = is_array($input['pos_entitlements'] ?? null)
            ? array_map('strval', $input['pos_entitlements'])
            : self::splitKeys((string) ($input['pos_entitlements'] ?? ''));
        $unknown = array_diff($keys, self::POS_KEYS);
        if ($unknown !== []) {
            throw new UserFacingException('Unknown app module or capability: ' . implode(', ', $unknown));
        }
        $availability = (string) ($input['availability'] ?? 'app');
        if (!in_array($availability, self::AVAILABILITY, true)) {
            throw new UserFacingException('Availability must be app, service or planned.');
        }
        $fields = [
            'name'             => $name,
            'description'      => $description === '' ? null : $description,
            'pos_entitlements' => implode(',', array_values(array_unique($keys))),
            'availability'     => $availability,
            'is_active'        => array_key_exists('is_active', $input) ? (!empty($input['is_active']) ? 1 : 0) : 1,
        ];
        $existing = $pdo->prepare('SELECT id FROM features WHERE code = :code FOR UPDATE');
        $existing->execute(['code' => $code]);
        $id = $existing->fetchColumn();
        if ($id === false) {
            $pdo->prepare(
                'INSERT INTO features (code, name, description, pos_entitlements, availability, is_active, created_at)
                 VALUES (:code, :name, :description, :pos_entitlements, :availability, :is_active, NOW())'
            )->execute(['code' => $code] + $fields);
            return ['id' => (int) $pdo->lastInsertId(), 'created' => true];
        }
        $pdo->prepare(
            'UPDATE features SET name = :name, description = :description, pos_entitlements = :pos_entitlements,
                                 availability = :availability, is_active = :is_active, updated_at = NOW()
              WHERE id = :id'
        )->execute($fields + ['id' => (int) $id]);
        return ['id' => (int) $id, 'created' => false];
    }

    /** @return list<string> */
    public static function splitKeys(string $csv): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $csv)), static fn (string $k): bool => $k !== ''));
    }

    /** @return array<int, list<array{label:string,code:string}>> */
    private function cardFeatures(array $planIds): array
    {
        if ($planIds === []) {
            return [];
        }
        $ids = implode(',', array_map('intval', $planIds));
        $rows = Database::pdo()->query(
            "SELECT pf.plan_id, f.code, COALESCE(pf.display_label, f.name) AS label, f.description
               FROM plan_features pf JOIN features f ON f.id = pf.feature_id
              WHERE pf.plan_id IN ({$ids}) AND pf.show_on_card = 1 AND f.is_active = 1
              ORDER BY pf.sort_order, f.name"
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['plan_id']][] = ['code' => $r['code'], 'label' => $r['label'], 'description' => $r['description']];
        }
        return $out;
    }

    /** Validated plan columns from $input (create: every field must be valid). */
    private function planFields(array $input, bool $creating): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 80) {
            throw new UserFacingException('Plan name is required (at most 80 characters).');
        }
        $price = filter_var($input['price_minor'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100_000_000_000]]);
        if ($price === false) {
            throw new UserFacingException('Price must be a whole number of paisa, 0 or more.');
        }
        $tagline = trim((string) ($input['tagline'] ?? ''));
        if (mb_strlen($tagline) > 190) {
            throw new UserFacingException('Tagline can be at most 190 characters.');
        }
        $theme = (string) ($input['theme'] ?? 'slate');
        if (!in_array($theme, self::THEMES, true)) {
            throw new UserFacingException('Theme must be blue, green, purple or slate.');
        }
        $period = (string) ($input['billing_period'] ?? 'month');
        if (!in_array($period, ['month', 'year'], true)) {
            throw new UserFacingException('Billing period must be month or year.');
        }
        $cta = trim((string) ($input['cta_label'] ?? 'Request Access'));
        if ($cta === '' || mb_strlen($cta) > 40) {
            throw new UserFacingException('Button label is required (at most 40 characters).');
        }
        $devices = filter_var($input['max_devices'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($devices === false) {
            throw new UserFacingException('Devices per licence must be between 1 and 1000.');
        }
        $currency = strtoupper(trim((string) ($input['currency'] ?? 'PKR')));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new UserFacingException('Currency must be a three-letter code.');
        }
        return [
            'name'           => $name,
            'tagline'        => $tagline === '' ? null : $tagline,
            'price_minor'    => $price,
            'currency'       => $currency,
            'billing_period' => $period,
            'theme'          => $theme,
            'cta_label'      => $cta,
            'max_devices'    => $devices,
            'sort_order'     => (int) ($input['sort_order'] ?? 0),
            'is_active'      => $creating
                ? (array_key_exists('is_active', $input) ? (!empty($input['is_active']) ? 1 : 0) : 1)
                : (!empty($input['is_active']) ? 1 : 0),
        ];
    }
}
