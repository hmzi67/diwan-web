<?php
declare(strict_types=1);

namespace Diwan\Admin;

use Diwan\Database\Database;
use Diwan\License\LicenseAdmin;
use Diwan\Support\UserFacingException;
use PDO;

/**
 * Every read the admin panel makes. All figures come straight from the
 * tables — nothing is estimated or cached — and every list is paginated,
 * with filters bound as parameters (only validated integers are ever
 * spliced into SQL, for LIMIT/OFFSET).
 */
final class AdminQueries
{
    public const PAGE_SIZE = 25;

    /** Counted "downloads": attempts that actually reached the installer. */
    private const DELIVERED = "('served','completed')";

    public function dashboard(): array
    {
        $pdo = Database::pdo();
        $one = static fn (string $sql): int => (int) $pdo->query($sql)->fetchColumn();
        $entitled = "l.status = 'active' AND (l.expires_at IS NULL OR l.expires_at > NOW())";

        return [
            'workspaces' => (new \Diwan\Workspace\WorkspaceAdmin())->summary(),
            'kpis' => [
                'registered_users'     => $one('SELECT COUNT(*) FROM customers WHERE deleted_at IS NULL'),
                'active_customers'     => $one("SELECT COUNT(DISTINCT l.customer_id) FROM licenses l JOIN customers c ON c.id = l.customer_id
                                                 WHERE {$entitled} AND c.status = 'active'"),
                'pending_requests'     => $one("SELECT COUNT(*) FROM license_requests WHERE status = 'pending'"),
                'rejected_requests'    => $one("SELECT COUNT(*) FROM license_requests WHERE status = 'rejected'"),
                'suspended_accounts'   => $one("SELECT COUNT(*) FROM customers WHERE status = 'suspended' AND deleted_at IS NULL"),
                'licenses_issued'      => $one('SELECT COUNT(*) FROM licenses'),
                'licenses_active'      => $one("SELECT COUNT(*) FROM licenses l WHERE {$entitled}"),
                'licenses_expired'     => $one("SELECT COUNT(*) FROM licenses WHERE status = 'expired'
                                                 OR (status = 'active' AND expires_at IS NOT NULL AND expires_at <= NOW())"),
                'licenses_suspended'   => $one("SELECT COUNT(*) FROM licenses WHERE status = 'suspended'"),
                'licenses_revoked'     => $one("SELECT COUNT(*) FROM licenses WHERE status = 'revoked'"),
                'downloads_total'      => $one('SELECT COUNT(*) FROM download_events WHERE status IN ' . self::DELIVERED),
                'downloads_unique'     => $one('SELECT COUNT(DISTINCT customer_id) FROM download_events
                                                 WHERE status IN ' . self::DELIVERED . ' AND customer_id IS NOT NULL'),
            ],
            'downloads_by_plan' => $pdo->query(
                "SELECT COALESCE(p.name, 'No plan (legacy licence)') AS label, COUNT(*) AS value
                   FROM download_events e LEFT JOIN plans p ON p.id = e.plan_id
                  WHERE e.status IN " . self::DELIVERED . ' GROUP BY label ORDER BY value DESC'
            )->fetchAll(),
            'downloads_over_time' => $this->series(
                'SELECT DATE(created_at) AS day, COUNT(*) AS value FROM download_events
                  WHERE status IN ' . self::DELIVERED . ' AND created_at >= CURDATE() - INTERVAL 29 DAY
                  GROUP BY day', 30
            ),
            'requests_by_plan' => $pdo->query(
                "SELECT p.name AS label, COUNT(*) AS value FROM license_requests r JOIN plans p ON p.id = r.plan_id
                  WHERE r.status = 'pending' GROUP BY p.id ORDER BY p.sort_order"
            )->fetchAll(),
            'recent_registrations' => $pdo->query(
                'SELECT id, email, name, business_name, status, created_at FROM customers
                  WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 6'
            )->fetchAll(),
            'recent_requests' => $pdo->query(
                'SELECT r.id, r.status, r.created_at, c.email, r.business_name, p.name AS plan_name
                   FROM license_requests r JOIN customers c ON c.id = r.customer_id JOIN plans p ON p.id = r.plan_id
                  ORDER BY r.id DESC LIMIT 6'
            )->fetchAll(),
            'recent_downloads' => $pdo->query(
                "SELECT e.created_at, e.status, e.platform, e.variant, e.version, c.email, COALESCE(p.name, 'No plan') AS plan_name
                   FROM download_events e LEFT JOIN customers c ON c.id = e.customer_id LEFT JOIN plans p ON p.id = e.plan_id
                  ORDER BY e.id DESC LIMIT 6"
            )->fetchAll(),
        ];
    }

    // -- Users ---------------------------------------------------------------

    public function users(array $f): array
    {
        $where = ['c.deleted_at IS NULL'];
        $params = [];
        if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
            $where[] = $this->search(['c.email', 'c.name', 'c.business_name'], $q, $params);
        }
        if (in_array($f['status'] ?? '', ['active', 'suspended'], true)) {
            $where[] = 'c.status = :status';
            $params['status'] = $f['status'];
        }
        if (($f['plan'] ?? '') === 'none') {
            $where[] = 'NOT EXISTS (SELECT 1 FROM licenses l WHERE l.customer_id = c.id AND l.plan_id IS NOT NULL)';
        } elseif (($f['plan'] ?? '') !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM licenses l JOIN plans p ON p.id = l.plan_id
                                 WHERE l.customer_id = c.id AND p.code = :plan AND l.status IN (\'active\',\'suspended\'))';
            $params['plan'] = (string) $f['plan'];
        }
        if (($f['request'] ?? '') === 'pending') {
            $where[] = "EXISTS (SELECT 1 FROM license_requests r WHERE r.customer_id = c.id AND r.status = 'pending')";
        }

        $sql = 'FROM customers c WHERE ' . implode(' AND ', $where);
        $rows = $this->page(
            "SELECT c.id, c.email, c.name, c.business_name, c.phone, c.status, c.created_at, c.last_active_at,
                    c.email_verified_at IS NOT NULL AS verified,
                    (SELECT p.name FROM licenses l JOIN plans p ON p.id = l.plan_id
                      WHERE l.customer_id = c.id AND l.status IN ('active','suspended') ORDER BY l.id DESC LIMIT 1) AS current_plan,
                    (SELECT COUNT(*) FROM licenses l WHERE l.customer_id = c.id) AS license_count,
                    (SELECT r.status FROM license_requests r WHERE r.customer_id = c.id ORDER BY r.id DESC LIMIT 1) AS last_request_status,
                    (SELECT p.name FROM license_requests r JOIN plans p ON p.id = r.plan_id
                      WHERE r.customer_id = c.id ORDER BY r.id DESC LIMIT 1) AS last_requested_plan,
                    (SELECT COUNT(*) FROM download_events e WHERE e.customer_id = c.id AND e.status IN " . self::DELIVERED . ") AS downloads
             {$sql} ORDER BY c.id DESC",
            "SELECT COUNT(*) {$sql}",
            $params,
            $f
        );
        $rows['items'] = array_map(static fn (array $r): array => ['verified' => (bool) $r['verified']] + $r, $rows['items']);
        return $rows;
    }

    public function user(int $customerId): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT id, email, name, business_name, phone, status, suspended_at, created_at, last_active_at,
                    email_verified_at, apple_sub IS NOT NULL AS uses_apple
               FROM customers WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute(['id' => $customerId]);
        $user = $stmt->fetch();
        if (!$user) {
            throw new UserFacingException('User not found.', 404);
        }
        $user['uses_apple'] = (bool) $user['uses_apple'];

        $licenses = $pdo->prepare(
            "SELECT l.id, l.license_key_prefix, l.status, l.activation_status, l.expires_at, l.created_at, l.max_devices,
                    (l.expires_at IS NOT NULL AND l.expires_at <= NOW()) AS past_expiry,
                    COALESCE(p.name, CASE o.gateway WHEN 'trial' THEN 'Free trial' ELSE pr.name END) AS plan_name,
                    o.gateway,
                    (SELECT COUNT(*) FROM license_activations a WHERE a.license_id = l.id AND a.deactivated_at IS NULL) AS devices
               FROM licenses l JOIN orders o ON o.id = l.order_id JOIN products pr ON pr.id = o.product_id
          LEFT JOIN plans p ON p.id = l.plan_id
              WHERE l.customer_id = :id ORDER BY l.id DESC"
        );
        $licenses->execute(['id' => $customerId]);
        $user['licenses'] = array_map(static function (array $l): array {
            $l['display_status'] = LicenseAdmin::displayStatus($l);
            unset($l['past_expiry']);
            return $l;
        }, $licenses->fetchAll());

        $requests = $pdo->prepare(
            'SELECT r.id, r.status, r.created_at, r.decided_at, r.decision_note, r.message, p.name AS plan_name,
                    a.email AS decided_by_email
               FROM license_requests r JOIN plans p ON p.id = r.plan_id LEFT JOIN admin_users a ON a.id = r.decided_by
              WHERE r.customer_id = :id ORDER BY r.id DESC'
        );
        $requests->execute(['id' => $customerId]);
        $user['requests'] = $requests->fetchAll();

        $downloads = $pdo->prepare(
            "SELECT e.created_at, e.status, e.platform, e.variant, e.version, e.channel, e.failure_reason,
                    COALESCE(p.name, 'No plan') AS plan_name
               FROM download_events e LEFT JOIN plans p ON p.id = e.plan_id
              WHERE e.customer_id = :id ORDER BY e.id DESC LIMIT 50"
        );
        $downloads->execute(['id' => $customerId]);
        $user['downloads'] = $downloads->fetchAll();

        $history = $pdo->prepare(
            "SELECT action, actor_type, actor_label, target_type, target_id, details, created_at FROM audit_logs
              WHERE (target_type = 'customer' AND target_id = :id)
                 OR (actor_type = 'customer' AND actor_id = :id2)
                 OR (target_type = 'license' AND target_id IN (SELECT id FROM licenses WHERE customer_id = :id3))
                 OR (target_type = 'license_request' AND target_id IN (SELECT id FROM license_requests WHERE customer_id = :id4))
              ORDER BY id DESC LIMIT 50"
        );
        $history->execute(['id' => $customerId, 'id2' => $customerId, 'id3' => $customerId, 'id4' => $customerId]);
        $user['history'] = $history->fetchAll();
        return $user;
    }

    // -- Requests ------------------------------------------------------------

    public function requests(array $f): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (in_array($f['status'] ?? '', ['pending', 'approved', 'rejected', 'cancelled'], true)) {
            $where[] = 'r.status = :status';
            $params['status'] = $f['status'];
        }
        if (($f['plan'] ?? '') !== '') {
            $where[] = 'p.code = :plan';
            $params['plan'] = (string) $f['plan'];
        }
        if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
            $where[] = $this->search(['c.email', 'c.name', 'r.business_name'], $q, $params);
        }
        $sql = 'FROM license_requests r JOIN customers c ON c.id = r.customer_id JOIN plans p ON p.id = r.plan_id
           LEFT JOIN admin_users a ON a.id = r.decided_by WHERE ' . implode(' AND ', $where);
        return $this->page(
            "SELECT r.id, r.status, r.business_name, r.contact_phone, r.message, r.decision_note, r.created_at,
                    r.decided_at, r.license_id, r.customer_id, c.email, c.name AS customer_name, c.status AS customer_status,
                    p.code AS plan_code, p.name AS plan_name, p.price_minor, p.currency, a.email AS decided_by_email,
                    (SELECT pl.name FROM licenses l JOIN plans pl ON pl.id = l.plan_id
                      WHERE l.customer_id = r.customer_id AND l.status IN ('active','suspended') ORDER BY l.id DESC LIMIT 1) AS current_plan
             {$sql} ORDER BY r.status = 'pending' DESC, r.id DESC",
            "SELECT COUNT(*) {$sql}",
            $params,
            $f
        );
    }

    // -- Licences ------------------------------------------------------------

    public function licenses(array $f): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
            // A pasted full key is matched by its first group (the stored
            // prefix), never by sending the key anywhere else.
            $params['prefix'] = $this->like(strtoupper(substr($q, 0, 11))) . '%';
            $where[] = '(' . $this->search(['l.customer_email', 'c.name', 'c.business_name'], $q, $params)
                . ' OR l.license_key_prefix LIKE :prefix)';
        }
        if (($f['plan'] ?? '') === 'none') {
            $where[] = 'l.plan_id IS NULL';
        } elseif (($f['plan'] ?? '') !== '') {
            $where[] = 'p.code = :plan';
            $params['plan'] = (string) $f['plan'];
        }
        $where[] = match ($f['status'] ?? '') {
            'revoked'   => "l.status = 'revoked'",
            'suspended' => "l.status = 'suspended'",
            'expired'   => "(l.status = 'expired' OR (l.status = 'active' AND l.expires_at IS NOT NULL AND l.expires_at <= NOW()))",
            'active'    => "l.status = 'active' AND (l.expires_at IS NULL OR l.expires_at > NOW()) AND l.activation_status = 'activated'",
            'issued'    => "l.status = 'active' AND (l.expires_at IS NULL OR l.expires_at > NOW()) AND l.activation_status = 'unused'",
            'expiring'  => "l.status = 'active' AND l.expires_at > NOW() AND l.expires_at <= NOW() + INTERVAL 14 DAY",
            default     => '1 = 1',
        };
        $sql = 'FROM licenses l JOIN orders o ON o.id = l.order_id JOIN products pr ON pr.id = o.product_id
           LEFT JOIN plans p ON p.id = l.plan_id LEFT JOIN customers c ON c.id = l.customer_id
               WHERE ' . implode(' AND ', $where);
        $page = $this->page(
            "SELECT l.id, l.customer_id, l.customer_email, c.business_name, l.license_key_prefix, l.status, l.activation_status,
                    l.expires_at, l.created_at, l.activated_at, l.status_changed_at, l.max_devices, o.gateway,
                    (l.expires_at IS NOT NULL AND l.expires_at <= NOW()) AS past_expiry,
                    COALESCE(p.name, CASE o.gateway WHEN 'trial' THEN 'Free trial' ELSE CONCAT(pr.name, ' (legacy)') END) AS plan_name,
                    p.code AS plan_code,
                    (SELECT COUNT(*) FROM license_activations a WHERE a.license_id = l.id AND a.deactivated_at IS NULL) AS devices
             {$sql} ORDER BY l.id DESC",
            "SELECT COUNT(*) {$sql}",
            $params,
            $f
        );
        $page['items'] = array_map(static function (array $l): array {
            $l['display_status'] = LicenseAdmin::displayStatus($l);
            unset($l['past_expiry']);
            return $l;
        }, $page['items']);
        return $page;
    }

    // -- Downloads -----------------------------------------------------------

    /** Filters shared by the downloads table, its CSV and its charts. */
    private function downloadWhere(array $f, array &$params): string
    {
        $where = ['1 = 1'];
        foreach (['from' => '>=', 'to' => '<'] as $key => $op) {
            $date = (string) ($f[$key] ?? '');
            if ($date !== '') {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                    throw new UserFacingException('Dates must be YYYY-MM-DD.');
                }
                $where[] = $key === 'from' ? "e.created_at >= :from" : "e.created_at < :to + INTERVAL 1 DAY";
                $params[$key] = $date;
            }
        }
        if (($f['plan'] ?? '') === 'none') {
            $where[] = 'e.plan_id IS NULL';
        } elseif (($f['plan'] ?? '') !== '') {
            $where[] = 'p.code = :plan';
            $params['plan'] = (string) $f['plan'];
        }
        foreach (['platform', 'version', 'status', 'channel'] as $key) {
            if (($f[$key] ?? '') !== '') {
                $where[] = "e.{$key} = :{$key}";
                $params[$key] = (string) $f[$key];
            }
        }
        if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
            $where[] = 'c.email LIKE :q';
            $params['q'] = '%' . $this->like($q) . '%';
        }
        return 'FROM download_events e LEFT JOIN customers c ON c.id = e.customer_id
           LEFT JOIN plans p ON p.id = e.plan_id WHERE ' . implode(' AND ', $where);
    }

    public function downloads(array $f): array
    {
        $params = [];
        $from = $this->downloadWhere($f, $params);
        $page = $this->page(
            "SELECT e.event_uid, e.created_at, e.updated_at, e.status, e.failure_reason, e.platform, e.variant, e.version,
                    e.channel, e.license_status, e.customer_id, c.email, COALESCE(p.name, 'No plan') AS plan_name
             {$from} ORDER BY e.id DESC",
            "SELECT COUNT(*) {$from}",
            $params,
            $f
        );

        $pdo = Database::pdo();
        $delivered = "{$from} AND e.status IN " . self::DELIVERED;
        $scalar = function (string $sql) use ($pdo, $params): int {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        };
        $grouped = function (string $expr) use ($pdo, $params, $delivered): array {
            $stmt = $pdo->prepare("SELECT {$expr} AS label, COUNT(*) AS value {$delivered} GROUP BY label ORDER BY value DESC LIMIT 20");
            $stmt->execute($params);
            return $stmt->fetchAll();
        };
        $daily = $pdo->prepare("SELECT DATE(e.created_at) AS day, COUNT(*) AS value {$delivered} GROUP BY day ORDER BY day DESC LIMIT 90");
        $daily->execute($params);

        $page['summary'] = [
            'requested'    => $scalar("SELECT COUNT(*) {$from}"),
            'delivered'    => $scalar("SELECT COUNT(*) {$delivered}"),
            'completed'    => $scalar("SELECT COUNT(*) {$from} AND e.status = 'completed'"),
            'failed'       => $scalar("SELECT COUNT(*) {$from} AND e.status = 'failed'"),
            'unique_users' => $scalar("SELECT COUNT(DISTINCT e.customer_id) {$delivered} AND e.customer_id IS NOT NULL"),
        ];
        $page['by_plan'] = $grouped("COALESCE(p.name, 'No plan')");
        $page['by_platform'] = $grouped("CONCAT(e.platform, IF(e.variant IS NULL, '', CONCAT(' · ', e.variant)))");
        $page['by_version'] = $grouped("COALESCE(e.version, 'unknown')");
        $page['by_day'] = array_reverse($daily->fetchAll());
        $page['facets'] = [
            'versions'  => $pdo->query('SELECT DISTINCT version FROM download_events WHERE version IS NOT NULL ORDER BY version DESC')->fetchAll(PDO::FETCH_COLUMN),
            'platforms' => $pdo->query('SELECT DISTINCT platform FROM download_events WHERE platform IS NOT NULL ORDER BY platform')->fetchAll(PDO::FETCH_COLUMN),
        ];
        return $page;
    }

    /** Every matching row (capped), for CSV export. */
    public function downloadsForExport(array $f, int $cap = 50000): array
    {
        $params = [];
        $from = $this->downloadWhere($f, $params);
        $stmt = Database::pdo()->prepare(
            "SELECT e.event_uid, e.created_at, e.status, e.failure_reason, e.platform, e.variant, e.version, e.channel,
                    e.license_status, c.email, COALESCE(p.name, 'No plan') AS plan_name
             {$from} ORDER BY e.id DESC LIMIT {$cap}"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // -- Versions, audit -----------------------------------------------------

    public function releases(): array
    {
        return array_map(static fn (array $r): array => ['is_active' => (bool) $r['is_active']] + $r, Database::pdo()->query(
            'SELECT r.id, r.platform, r.variant, r.version, r.filename, r.source, r.github_tag, r.is_active, r.released_at,
                    r.release_notes, r.size_bytes,
                    (SELECT COUNT(*) FROM download_events e WHERE e.release_id = r.id AND e.status IN ' . self::DELIVERED . ') AS downloads
               FROM releases r ORDER BY r.released_at DESC, r.platform, r.variant'
        )->fetchAll());
    }

    public function audit(array $f): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (($f['action'] ?? '') !== '') {
            $where[] = 'action LIKE :action';
            $params['action'] = $this->like((string) $f['action']) . '%';
        }
        if (in_array($f['actor_type'] ?? '', ['admin', 'customer', 'system', 'terminal'], true)) {
            $where[] = 'actor_type = :actor_type';
            $params['actor_type'] = $f['actor_type'];
        }
        if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
            $where[] = $this->search(['actor_label', 'details'], $q, $params);
        }
        $sql = 'FROM audit_logs WHERE ' . implode(' AND ', $where);
        return $this->page(
            "SELECT id, actor_type, actor_id, actor_label, action, target_type, target_id, details, ip, created_at {$sql} ORDER BY id DESC",
            "SELECT COUNT(*) {$sql}",
            $params,
            $f
        );
    }

    /** @return array{items: list<array>, total: int, page: int, pages: int, page_size: int} */
    private function page(string $select, string $count, array $params, array $f): array
    {
        $size = (int) ($f['page_size'] ?? self::PAGE_SIZE);
        $size = $size >= 5 && $size <= 100 ? $size : self::PAGE_SIZE;
        $pageNo = max(1, (int) ($f['page'] ?? 1));
        $pdo = Database::pdo();

        $total = $pdo->prepare($count);
        $total->execute($params);
        $total = (int) $total->fetchColumn();
        $pages = max(1, (int) ceil($total / $size));
        $pageNo = min($pageNo, $pages);
        $offset = ($pageNo - 1) * $size;

        $stmt = $pdo->prepare("{$select} LIMIT {$size} OFFSET {$offset}");
        $stmt->execute($params);
        return ['items' => $stmt->fetchAll(), 'total' => $total, 'page' => $pageNo, 'pages' => $pages, 'page_size' => $size];
    }

    /** A daily series with zeroes filled in, oldest first. */
    private function series(string $sql, int $days): array
    {
        $rows = Database::pdo()->query($sql)->fetchAll(PDO::FETCH_KEY_PAIR);
        $today = Database::pdo()->query('SELECT CURDATE()')->fetchColumn();
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("{$today} -{$i} days"));
            $out[] = ['day' => $day, 'value' => (int) ($rows[$day] ?? 0)];
        }
        return $out;
    }

    /**
     * "(col1 LIKE :q0 OR col2 LIKE :q1 ...)" — one placeholder per column,
     * because native prepared statements refuse a repeated named parameter.
     */
    private function search(array $columns, string $q, array &$params): string
    {
        $parts = [];
        foreach (array_values($columns) as $i => $column) {
            $params["q{$i}"] = '%' . $this->like($q) . '%';
            $parts[] = "{$column} LIKE :q{$i}";
        }
        return '(' . implode(' OR ', $parts) . ')';
    }

    private function like(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}
