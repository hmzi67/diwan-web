<?php
declare(strict_types=1);

namespace Diwan\Workspace;

use Diwan\Database\Database;
use Diwan\License\LicenseAdmin;
use Diwan\Support\AuditLog;
use Diwan\Support\UserFacingException;
use PDO;

/**
 * The admin panel's view of business workspaces: each business, its
 * counters (with what they last reported), its sync conflicts and its
 * server-side sales totals — and the few actions an administrator takes on
 * them. Figures only: the panel never shows a business's records, staff or
 * PIN hashes. Every action is audited in the same transaction.
 */
final class WorkspaceAdmin
{
    private const PAGE_SIZE = 25;
    public const ACTIONS = ['rename_terminal', 'revoke_terminal', 'resolve_conflict', 'resolve_all_conflicts'];

    public function businesses(array $f): array
    {
        $where = ['1 = 1'];
        $params = [];
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(b.name LIKE :q0 OR c.email LIKE :q1 OR l.license_key_prefix LIKE :q2)';
            $params += ['q0' => $like, 'q1' => $like, 'q2' => $like];
        }
        if (($f['attention'] ?? '') === '1') {
            $where[] = "(EXISTS (SELECT 1 FROM sync_conflicts x WHERE x.business_id = b.id AND x.resolved_at IS NULL)
                         OR EXISTS (SELECT 1 FROM pos_terminals y WHERE y.business_id = b.id AND y.status = 'active' AND y.last_error IS NOT NULL))";
        }
        $from = 'FROM businesses b JOIN licenses l ON l.id = b.license_id LEFT JOIN customers c ON c.id = l.customer_id
                 LEFT JOIN plans p ON p.id = l.plan_id WHERE ' . implode(' AND ', $where);

        $pdo = Database::pdo();
        $count = $pdo->prepare("SELECT COUNT(*) {$from}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $size = self::PAGE_SIZE;
        $pages = max(1, (int) ceil($total / $size));
        $page = min(max(1, (int) ($f['page'] ?? 1)), $pages);
        $offset = ($page - 1) * $size;

        $stmt = $pdo->prepare(
            "SELECT b.id, b.name, b.created_at, b.seed_completed_at, c.email AS owner_email, c.id AS customer_id,
                    l.id AS license_id, l.license_key_prefix, l.status, l.activation_status, l.max_devices, p.name AS plan_name,
                    (l.expires_at IS NOT NULL AND l.expires_at <= NOW()) AS past_expiry,
                    (SELECT COUNT(*) FROM pos_terminals t WHERE t.business_id = b.id AND t.status = 'active') AS counters,
                    (SELECT MAX(t.last_seen_at) FROM pos_terminals t WHERE t.business_id = b.id) AS last_seen_at,
                    (SELECT COUNT(*) FROM pos_terminals t WHERE t.business_id = b.id AND t.status = 'active' AND t.last_error IS NOT NULL) AS counters_with_errors,
                    (SELECT COUNT(*) FROM sync_conflicts x WHERE x.business_id = b.id AND x.resolved_at IS NULL) AS open_conflicts,
                    (SELECT COALESCE(SUM(s.total_minor), 0) FROM business_sales s WHERE s.business_id = b.id AND s.deleted = 0) AS sales_total_minor,
                    (SELECT COUNT(*) FROM business_sales s WHERE s.business_id = b.id AND s.deleted = 0) AS sales_count,
                    (SELECT COUNT(*) FROM sync_rows r WHERE r.business_id = b.id AND r.entity = 'items' AND r.deleted = 0) AS products
               {$from}
              ORDER BY b.id DESC LIMIT {$size} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $items = array_map(static function (array $row): array {
            $row['license_status'] = LicenseAdmin::displayStatus($row);
            unset($row['status'], $row['activation_status'], $row['past_expiry']);
            return $row;
        }, $stmt->fetchAll());
        return ['items' => $items, 'total' => $total, 'page' => $page, 'pages' => $pages, 'page_size' => $size];
    }

    public function detail(int $businessId): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            "SELECT b.id, b.name, b.created_at, b.seed_started_at, b.seed_completed_at, b.sync_version,
                    b.pin_key_check IS NOT NULL AS pins_protected,
                    c.email AS owner_email, c.id AS customer_id, l.id AS license_id, l.license_key_prefix, l.status,
                    l.activation_status, l.max_devices, p.name AS plan_name,
                    (l.expires_at IS NOT NULL AND l.expires_at <= NOW()) AS past_expiry,
                    seeder.name AS seeded_by
               FROM businesses b JOIN licenses l ON l.id = b.license_id
          LEFT JOIN customers c ON c.id = l.customer_id
          LEFT JOIN plans p ON p.id = l.plan_id
          LEFT JOIN pos_terminals seeder ON seeder.id = b.seeded_by_terminal_id
              WHERE b.id = :b"
        );
        $stmt->execute(['b' => $businessId]);
        $business = $stmt->fetch();
        if (!$business) {
            throw new UserFacingException('Business not found.', 404);
        }
        $business['license_status'] = LicenseAdmin::displayStatus($business);
        unset($business['status'], $business['activation_status'], $business['past_expiry']);

        $terminals = $pdo->prepare(
            'SELECT t.id, t.code, t.name, t.status, t.platform, t.app_version, t.joined_at, t.last_seen_at, t.last_push_at,
                    t.last_pull_at, t.pending_count, t.last_error, t.last_error_at, t.created_at, t.revoked_at,
                    a.machine_hint, a.deactivated_at,
                    (SELECT COUNT(*) FROM business_sales s WHERE s.business_id = t.business_id AND s.terminal_id = t.id AND s.deleted = 0) AS sales_count,
                    (SELECT COALESCE(SUM(s.total_minor), 0) FROM business_sales s
                      WHERE s.business_id = t.business_id AND s.terminal_id = t.id AND s.deleted = 0) AS sales_total_minor,
                    (SELECT COALESCE(SUM(s.total_minor), 0) FROM business_sales s
                      WHERE s.business_id = t.business_id AND s.terminal_id = t.id AND s.deleted = 0
                        AND s.sold_at >= CURDATE() - INTERVAL 29 DAY) AS sales_30d_minor
               FROM pos_terminals t JOIN license_activations a ON a.id = t.activation_id
              WHERE t.business_id = :b ORDER BY t.status, t.id'
        );
        $terminals->execute(['b' => $businessId]);

        $records = $pdo->prepare(
            'SELECT entity, COUNT(*) AS n FROM sync_rows WHERE business_id = :b AND deleted = 0 GROUP BY entity ORDER BY n DESC'
        );
        $records->execute(['b' => $businessId]);

        $conflicts = $pdo->prepare(
            'SELECT x.id, x.kind, x.entity, x.detail, x.created_at, t.name AS counter
               FROM sync_conflicts x LEFT JOIN pos_terminals t ON t.id = x.terminal_id
              WHERE x.business_id = :b AND x.resolved_at IS NULL ORDER BY x.id DESC LIMIT 50'
        );
        $conflicts->execute(['b' => $businessId]);

        $totals = $pdo->prepare(
            'SELECT COUNT(*) AS sales_count, COALESCE(SUM(total_minor), 0) AS sales_total_minor,
                    COALESCE(SUM(CASE WHEN sold_at >= CURDATE() THEN total_minor END), 0) AS today_minor,
                    COALESCE(SUM(CASE WHEN sold_at >= CURDATE() - INTERVAL 29 DAY THEN total_minor END), 0) AS last_30d_minor
               FROM business_sales WHERE business_id = :b AND deleted = 0'
        );
        $totals->execute(['b' => $businessId]);

        $history = $pdo->prepare(
            "SELECT action, actor_type, actor_label, details, created_at FROM audit_logs
              WHERE (target_type = 'business' AND target_id = :b)
                 OR (target_type = 'pos_terminal' AND target_id IN (SELECT id FROM pos_terminals WHERE business_id = :b2))
              ORDER BY id DESC LIMIT 50"
        );
        $history->execute(['b' => $businessId, 'b2' => $businessId]);

        return $business + [
            'terminals' => $terminals->fetchAll(),
            'records'   => $records->fetchAll(PDO::FETCH_KEY_PAIR),
            'conflicts' => $conflicts->fetchAll(),
            'totals'    => $totals->fetch(),
            'history'   => $history->fetchAll(),
            'media'     => MediaStore::usage($businessId),
        ];
    }

    /**
     * Sync requests the server answered, per hour: what shared hosting is
     * carrying. `peak_per_second` is the busiest hour of the last day,
     * averaged; `level` is a rough guide (see docs/MULTI-COUNTER.md §9).
     */
    public function load(): array
    {
        $pdo = Database::pdo();
        $hours = $pdo->query(
            "SELECT hour_start, SUM(requests) AS requests FROM sync_load
              WHERE hour_start >= DATE_FORMAT(NOW() - INTERVAL 23 HOUR, '%Y-%m-%d %H:00:00')
              GROUP BY hour_start ORDER BY hour_start"
        )->fetchAll();
        $byEndpoint = $pdo->query(
            "SELECT endpoint, SUM(requests) FROM sync_load
              WHERE hour_start >= DATE_FORMAT(NOW() - INTERVAL 23 HOUR, '%Y-%m-%d %H:00:00')
              GROUP BY endpoint ORDER BY endpoint"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        $total = array_sum(array_map(static fn ($h) => (int) $h['requests'], $hours));
        $peak = $hours === [] ? 0 : max(array_map(static fn ($h) => (int) $h['requests'], $hours));
        $current = (int) $pdo->query(
            "SELECT COALESCE(SUM(requests), 0) FROM sync_load WHERE hour_start = DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00')"
        )->fetchColumn();
        $perSecond = round($peak / 3600, 2);
        $files = $pdo->query('SELECT COUNT(*) AS files, COALESCE(SUM(bytes), 0) AS bytes FROM sync_media')->fetch();
        return [
            'this_hour'       => $current,
            'last_24h'        => $total,
            'peak_hour'       => $peak,
            'peak_per_second' => $perSecond,
            'level'           => $perSecond < 5 ? 'ok' : ($perSecond < 15 ? 'busy' : 'high'),
            'by_endpoint'     => array_map('intval', $byEndpoint),
            'hours'           => array_map(static fn ($h) => ['hour' => $h['hour_start'], 'requests' => (int) $h['requests']], $hours),
            'media'           => ['files' => (int) $files['files'], 'bytes' => (int) $files['bytes']],
        ];
    }

    public function apply(int $businessId, string $action, array $input, array $admin): array
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new UserFacingException('Unknown action.');
        }
        Database::transaction(function (PDO $pdo) use ($businessId, $action, $input, $admin): void {
            $exists = $pdo->prepare('SELECT id FROM businesses WHERE id = :b FOR UPDATE');
            $exists->execute(['b' => $businessId]);
            if ($exists->fetchColumn() === false) {
                throw new UserFacingException('Business not found.', 404);
            }
            $audit = static fn (string $what, string $type, int $id, array $details) =>
                AuditLog::record('admin', (int) $admin['id'], (string) $admin['email'], $what, $type, $id, $details, $pdo);

            switch ($action) {
                case 'rename_terminal':
                    $terminal = $this->terminal($pdo, $businessId, (int) ($input['terminal_id'] ?? 0));
                    $name = TerminalRegistry::name((string) ($input['name'] ?? ''));
                    $pdo->prepare('UPDATE pos_terminals SET name = :n WHERE id = :t')->execute(['n' => $name, 't' => $terminal['id']]);
                    $audit('terminal.rename', 'pos_terminal', (int) $terminal['id'], ['from' => $terminal['name'], 'to' => $name, 'business_id' => $businessId]);
                    break;

                case 'revoke_terminal':
                    // The counter stops syncing at once and its device slot is
                    // freed (its next licence check-in fails). Its data stays
                    // in the business; the device can rejoin only by entering
                    // the key again into a free slot.
                    $terminal = $this->terminal($pdo, $businessId, (int) ($input['terminal_id'] ?? 0));
                    if ($terminal['status'] === 'revoked') {
                        throw new UserFacingException('That counter is already disconnected.', 409);
                    }
                    $reason = mb_substr(trim((string) ($input['reason'] ?? '')), 0, 255);
                    $pdo->prepare("UPDATE pos_terminals SET status = 'revoked', revoked_at = NOW() WHERE id = :t")->execute(['t' => $terminal['id']]);
                    $pdo->prepare('UPDATE license_activations SET deactivated_at = COALESCE(deactivated_at, NOW()) WHERE id = :a')
                        ->execute(['a' => $terminal['activation_id']]);
                    $audit('terminal.revoke', 'pos_terminal', (int) $terminal['id'],
                        ['name' => $terminal['name'], 'business_id' => $businessId] + ($reason !== '' ? ['reason' => $reason] : []));
                    break;

                case 'resolve_conflict':
                    $stmt = $pdo->prepare(
                        'UPDATE sync_conflicts SET resolved_at = NOW(), resolved_by = :a WHERE id = :c AND business_id = :b AND resolved_at IS NULL'
                    );
                    $stmt->execute(['a' => $admin['id'], 'c' => (int) ($input['conflict_id'] ?? 0), 'b' => $businessId]);
                    if ($stmt->rowCount() === 0) {
                        throw new UserFacingException('That conflict is not open.', 404);
                    }
                    $audit('sync_conflict.resolve', 'business', $businessId, ['conflict_id' => (int) $input['conflict_id']]);
                    break;

                case 'resolve_all_conflicts':
                    $stmt = $pdo->prepare('UPDATE sync_conflicts SET resolved_at = NOW(), resolved_by = :a WHERE business_id = :b AND resolved_at IS NULL');
                    $stmt->execute(['a' => $admin['id'], 'b' => $businessId]);
                    $audit('sync_conflict.resolve_all', 'business', $businessId, ['resolved' => $stmt->rowCount()]);
                    break;
            }
        });
        return ['business' => $this->detail($businessId)];
    }

    /** Overview figures for the admin dashboard. */
    public function summary(): array
    {
        $pdo = Database::pdo();
        return [
            'businesses'       => (int) $pdo->query('SELECT COUNT(*) FROM businesses')->fetchColumn(),
            'active_counters'  => (int) $pdo->query("SELECT COUNT(*) FROM pos_terminals WHERE status = 'active'")->fetchColumn(),
            'counters_online'  => (int) $pdo->query("SELECT COUNT(*) FROM pos_terminals WHERE status = 'active' AND last_seen_at >= NOW() - INTERVAL 10 MINUTE")->fetchColumn(),
            'open_conflicts'   => (int) $pdo->query('SELECT COUNT(*) FROM sync_conflicts WHERE resolved_at IS NULL')->fetchColumn(),
        ];
    }

    private function terminal(PDO $pdo, int $businessId, int $terminalId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM pos_terminals WHERE id = :t AND business_id = :b FOR UPDATE');
        $stmt->execute(['t' => $terminalId, 'b' => $businessId]);
        $terminal = $stmt->fetch();
        if (!$terminal) {
            throw new UserFacingException('Counter not found in this business.', 404);
        }
        return $terminal;
    }
}
