<?php
declare(strict_types=1);

namespace Diwan\Workspace;

use Diwan\Database\Database;
use PDO;

/**
 * The authoritative store behind every counter of a business.
 *
 * Records: each counter pushes whole rows (with foreign keys as gids) and the
 * server keeps the latest per (entity, gid) — last write to reach the server
 * wins, deletes are tombstones. Every accepted change takes the next value
 * of the business's `sync_version` while holding the business row lock, so
 * versions commit in order and "everything after N" is a complete diff.
 *
 * Stock is never a pushed value. A counter sends signed changes, each keyed;
 * the ledger's unique (business, event key, item) makes a replay a no-op, and
 * the balance is `qty + change` under the row lock, so concurrent sales from
 * several counters all land.
 *
 * Online sales take their stock first through reserve(): the counter gets a
 * yes/no against the central balance before it records the sale, so two
 * counters cannot both sell the last unit. The sale's own stock changes are
 * later pushed under the reservation key, which the ledger already holds for
 * those items, so they are not applied twice. An offline sale has no
 * reservation; its changes apply when it reconnects even if that takes the
 * balance below zero — the money was already taken — and that is recorded
 * as a conflict for a person to look at, never silently overwritten.
 */
final class SyncEngine
{
    /** Must match the POS's replicated tables (src-tauri/src/sync/spec.rs). */
    public const ENTITIES = [
        'app_config', 'enabled_modules', 'categories', 'counters', 'items', 'tables', 'users', 'shifts',
        'customers', 'suppliers', 'delivery_platforms', 'employees', 'expenses', 'sales', 'sale_items',
        'sale_payments', 'refunds', 'refund_items', 'table_orders', 'tokens', 'token_items', 'orders',
        'order_items', 'order_status_events', 'attendance', 'salary_payments', 'customer_payments',
        'purchase_orders', 'purchase_order_items', 'goods_receipts', 'goods_receipt_items',
        'supplier_payments', 'stock_movements', 'stock_takes', 'stock_take_lines',
    ];

    public const MAX_CHANGES = 500;
    public const MAX_STOCK = 5000;
    public const MAX_RELEASES = 200;
    public const MAX_PULL = 1000;
    private const MAX_ROW_BYTES = 262144;

    /**
     * @param array $input {changes: [{id, entity, gid, op, data}], stock: [{key, item_gid, qty, tag?}],
     *                      releases: [key], pending?: int, last_error?: string|null}
     */
    public function push(array $ctx, array $input): array
    {
        if (!$ctx['joined']) {
            throw new WorkspaceException('not_joined',
                'This counter has not loaded the business data yet. Connect it in Settings first.');
        }
        $changes = self::list($input['changes'] ?? [], self::MAX_CHANGES, 'changes');
        $stock = self::list($input['stock'] ?? [], self::MAX_STOCK, 'stock changes');
        $releases = self::list($input['releases'] ?? [], self::MAX_RELEASES, 'releases');

        return Database::transaction(function (PDO $pdo) use ($ctx, $changes, $stock, $releases, $input): array {
            $businessId = $ctx['business_id'];
            $version = $this->lockVersion($pdo, $businessId);
            $accepted = [];
            $rejected = [];
            $sealedWith = null;

            $upsert = $pdo->prepare(
                'INSERT INTO sync_rows (business_id, entity, gid, data, field_versions, deleted, version, origin_terminal_id, updated_by_terminal_id, created_at, updated_at)
                 VALUES (:b, :entity, :gid, :data, :fv, :deleted, :version, :t, :t2, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE data = IF(VALUES(deleted) = 1, data, VALUES(data)),
                                         field_versions = IF(VALUES(deleted) = 1, field_versions, VALUES(field_versions)),
                                         deleted = VALUES(deleted),
                                         version = VALUES(version), updated_by_terminal_id = VALUES(updated_by_terminal_id),
                                         updated_at = NOW()'
            );
            $current = $pdo->prepare('SELECT data, field_versions, deleted FROM sync_rows WHERE business_id = :b AND entity = :e AND gid = :g');
            foreach ($changes as $change) {
                $id = is_array($change) && isset($change['id']) && is_int($change['id']) ? $change['id'] : null;
                $error = self::invalidChange($change);
                if ($error !== null) {
                    if ($id !== null) {
                        $rejected[] = ['id' => $id, 'error' => $error];
                    }
                    $this->conflict($pdo, $ctx, 'rejected_change', is_array($change) ? (string) ($change['entity'] ?? '') : null,
                        is_array($change) ? (string) ($change['gid'] ?? '') : null, $error);
                    continue;
                }
                $deleted = $change['op'] === 'delete' ? 1 : 0;
                $version++;
                $merged = null;
                $fieldVersions = null;
                if (!$deleted) {
                    $current->execute(['b' => $businessId, 'e' => $change['entity'], 'g' => $change['gid']]);
                    [$merged, $fieldVersions, $overwritten] = self::merge($current->fetch() ?: null, $change, $version, $ctx['terminal_id']);
                    if ($overwritten !== []) {
                        $this->conflict($pdo, $ctx, 'concurrent_edit', $change['entity'], $change['gid'],
                            'Two counters changed ' . implode(', ', $overwritten) . ' of this record at the same time; '
                            . 'the change that reached the server last was kept.');
                    }
                }
                $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;
                $upsert->execute([
                    'b' => $businessId, 'entity' => $change['entity'], 'gid' => $change['gid'],
                    'data' => $deleted ? '{}' : json_encode((object) $merged, $flags),
                    'fv' => $fieldVersions === null ? null : json_encode($fieldVersions, $flags),
                    'deleted' => $deleted, 'version' => $version, 't' => $ctx['terminal_id'], 't2' => $ctx['terminal_id'],
                ]);
                if ($change['entity'] === 'sales') {
                    $this->projectSale($pdo, $businessId, $change['gid'], $deleted ? null : $merged);
                }
                // Counters seal staff PIN hashes with a key derived from the
                // licence key, which this server never stores: remember which
                // key, for the admin panel.
                $pin = $merged['pin_hash'] ?? null;
                if ($change['entity'] === 'users' && is_string($pin) && preg_match('/^enc1:([0-9a-f]{8}):/', $pin, $m) === 1) {
                    $sealedWith = $m[1];
                }
                $accepted[] = $id;
            }

            [$stockAccepted, $version] = $this->applyStock($pdo, $ctx, $stock, $version);

            $releasesAccepted = [];
            foreach ($releases as $key) {
                if (!is_string($key) || !self::validKey($key)) {
                    continue;
                }
                $version = $this->release($pdo, $ctx, $key, $version);
                $releasesAccepted[] = $key;
            }

            $pdo->prepare('UPDATE businesses SET sync_version = :v, pin_key_check = COALESCE(:k, pin_key_check) WHERE id = :b')
                ->execute(['v' => $version, 'k' => $sealedWith, 'b' => $businessId]);
            $this->report($pdo, $ctx, $input, 'last_push_at');

            return [
                'accepted'          => $accepted,
                'rejected'          => $rejected,
                'stock_accepted'    => $stockAccepted,
                'releases_accepted' => $releasesAccepted,
                'version'           => $version,
            ];
        });
    }

    /**
     * Everything that changed after `since`, oldest first. `cursor` is what
     * to send next time; `more` says another page is waiting.
     */
    /**
     * @param array $report what the counter says about itself: {pending?: int, last_error?: string|null}
     */
    public function pull(array $ctx, int $since, int $limit, array $report = []): array
    {
        $limit = max(1, min(self::MAX_PULL, $limit));
        $since = max(0, $since);
        $pdo = Database::pdo();

        // One snapshot for every read below: versions up to `head` are all
        // committed (writers hold the business lock until commit).
        $pdo->beginTransaction();
        try {
            $head = $pdo->prepare('SELECT sync_version, name FROM businesses WHERE id = :b');
            $head->execute(['b' => $ctx['business_id']]);
            $business = $head->fetch();
            $headVersion = (int) $business['sync_version'];

            $rows = $pdo->prepare(
                'SELECT entity, gid, data, deleted, version FROM sync_rows
                  WHERE business_id = :b AND version > :since AND version <= :head
                  ORDER BY version LIMIT ' . $limit
            );
            $rows->execute(['b' => $ctx['business_id'], 'since' => $since, 'head' => $headVersion]);
            $changes = [];
            foreach ($rows->fetchAll() as $row) {
                $changes[] = [
                    'entity'  => $row['entity'],
                    'gid'     => $row['gid'],
                    'deleted' => (bool) $row['deleted'],
                    'data'    => $row['deleted'] ? null : json_decode($row['data'], true),
                    'version' => (int) $row['version'],
                ];
            }
            $upTo = count($changes) === $limit ? $changes[$limit - 1]['version'] : $headVersion;

            $stockStmt = $pdo->prepare(
                'SELECT item_gid, qty FROM sync_stock WHERE business_id = :b AND version > :since AND version <= :upto'
            );
            $stockStmt->execute(['b' => $ctx['business_id'], 'since' => $since, 'upto' => $upTo]);
            $stock = [];
            foreach ($stockStmt->fetchAll() as $row) {
                $stock[$row['item_gid']] = (float) $row['qty'];
            }

            $terminals = $pdo->prepare(
                'SELECT code, name, install_id, status, last_seen_at FROM pos_terminals WHERE business_id = :b ORDER BY id'
            );
            $terminals->execute(['b' => $ctx['business_id']]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $this->report($pdo, $ctx, $report, 'last_pull_at');
        return [
            'changes'   => $changes,
            'stock'     => (object) $stock,
            'cursor'    => $upTo,
            'head'      => $headVersion,
            'more'      => $upTo < $headVersion,
            'business'  => ['name' => (string) $business['name']],
            'terminals' => array_map(static fn (array $t): array => [
                'code' => $t['code'], 'name' => $t['name'], 'install_id' => $t['install_id'],
                'active' => $t['status'] === 'active', 'last_seen_at' => $t['last_seen_at'],
            ], $terminals->fetchAll()),
        ];
    }

    /**
     * Takes an online sale's stock centrally before the counter records it.
     * All or nothing: if any item is short, nothing is taken. Items the
     * server has never heard of (created on this counter and not yet pushed)
     * are not checked here; their changes apply when pushed.
     *
     * Idempotent per key: a retry gets the first answer.
     *
     * @param list<array{item_gid: string, qty: float|int}> $lines
     */
    public function reserve(array $ctx, string $key, array $lines): array
    {
        if (!$ctx['joined']) {
            throw new WorkspaceException('not_joined', 'This counter has not loaded the business data yet.');
        }
        if (!self::validKey($key)) {
            throw new WorkspaceException('bad_request', 'Invalid reservation key.', 422);
        }
        $wanted = [];
        foreach (self::list($lines, 200, 'lines') as $line) {
            $gid = is_array($line) ? ($line['item_gid'] ?? null) : null;
            $qty = is_array($line) ? ($line['qty'] ?? null) : null;
            if (!is_string($gid) || !self::validGid($gid) || !is_numeric($qty) || (float) $qty <= 0 || (float) $qty > 1e9) {
                throw new WorkspaceException('bad_request', 'Invalid reservation line.', 422);
            }
            $wanted[$gid] = round(($wanted[$gid] ?? 0) + (float) $qty, 4);
        }

        return Database::transaction(function (PDO $pdo) use ($ctx, $key, $wanted): array {
            $businessId = $ctx['business_id'];
            $version = $this->lockVersion($pdo, $businessId);

            $existing = $pdo->prepare('SELECT status, `lines` FROM stock_reservations WHERE business_id = :b AND reservation_key = :k');
            $existing->execute(['b' => $businessId, 'k' => $key]);
            if ($found = $existing->fetch()) {
                return [
                    'status'   => $found['status'] === 'committed' ? 'ok' : 'released',
                    'taken'    => (object) array_map('floatval', json_decode($found['lines'], true) ?: []),
                    'balances' => (object) $this->balances($pdo, $businessId, array_keys($wanted)),
                ];
            }

            $current = [];
            $shortages = [];
            $read = $pdo->prepare('SELECT qty FROM sync_stock WHERE business_id = :b AND item_gid = :i FOR UPDATE');
            foreach ($wanted as $gid => $qty) {
                $read->execute(['b' => $businessId, 'i' => $gid]);
                $have = $read->fetchColumn();
                if ($have === false) {
                    continue;   // not known centrally yet
                }
                $current[$gid] = (float) $have;
                if ((float) $have + 1e-9 < $qty) {
                    $shortages[] = ['item_gid' => $gid, 'requested' => $qty, 'available' => (float) $have];
                }
            }
            if ($shortages !== []) {
                return ['status' => 'insufficient', 'shortages' => $shortages, 'balances' => (object) $current];
            }

            $taken = [];
            foreach ($current as $gid => $have) {
                $balance = round($have - $wanted[$gid], 4);
                $version = $this->writeBalance($pdo, $businessId, $gid, $balance, $version);
                $pdo->prepare(
                    "INSERT INTO stock_ledger (business_id, event_key, item_gid, qty_change, balance_after, kind, terminal_id)
                     VALUES (:b, :k, :i, :q, :bal, 'reservation', :t)"
                )->execute(['b' => $businessId, 'k' => $key, 'i' => $gid, 'q' => -$wanted[$gid], 'bal' => $balance, 't' => $ctx['terminal_id']]);
                $taken[$gid] = $wanted[$gid];
            }
            $pdo->prepare(
                'INSERT INTO stock_reservations (business_id, reservation_key, terminal_id, `lines`) VALUES (:b, :k, :t, :l)'
            )->execute(['b' => $businessId, 'k' => $key, 't' => $ctx['terminal_id'], 'l' => json_encode((object) $taken)]);
            $pdo->prepare('UPDATE businesses SET sync_version = :v WHERE id = :b')->execute(['v' => $version, 'b' => $businessId]);

            return ['status' => 'ok', 'taken' => (object) $taken, 'balances' => (object) $this->balances($pdo, $businessId, array_keys($wanted))];
        });
    }

    /** @return array{0: list<string>, 1: int} accepted keys and the new version */
    private function applyStock(PDO $pdo, array $ctx, array $stock, int $version): array
    {
        $businessId = $ctx['business_id'];
        $accepted = [];
        $ledger = $pdo->prepare(
            "INSERT IGNORE INTO stock_ledger (business_id, event_key, item_gid, qty_change, balance_after, kind, terminal_id)
             VALUES (:b, :k, :i, :q, 0, 'change', :t)"
        );
        $read = $pdo->prepare('SELECT qty FROM sync_stock WHERE business_id = :b AND item_gid = :i FOR UPDATE');
        foreach ($stock as $entry) {
            $key = is_array($entry) ? ($entry['key'] ?? null) : null;
            $gid = is_array($entry) ? ($entry['item_gid'] ?? null) : null;
            $qty = is_array($entry) ? ($entry['qty'] ?? null) : null;
            if (!is_string($key) || !self::validKey($key) || !is_string($gid) || !self::validGid($gid)
                || !is_numeric($qty) || abs((float) $qty) > 1e9 || !is_finite((float) $qty)) {
                continue;   // never acknowledged, so the counter keeps it and reports it
            }
            $delta = round((float) $qty, 4);
            $ledger->execute(['b' => $businessId, 'k' => $key, 'i' => $gid, 'q' => $delta, 't' => $ctx['terminal_id']]);
            if ($ledger->rowCount() === 0) {
                $accepted[] = $key;   // already applied: a retry, or a reserved sale line
                continue;
            }
            $ledgerId = (int) $pdo->lastInsertId();
            $read->execute(['b' => $businessId, 'i' => $gid]);
            $have = $read->fetchColumn();
            $balance = round(($have === false ? 0.0 : (float) $have) + $delta, 4);
            $version = $this->writeBalance($pdo, $businessId, $gid, $balance, $version);
            $pdo->prepare('UPDATE stock_ledger SET balance_after = :bal WHERE id = :id')->execute(['bal' => $balance, 'id' => $ledgerId]);
            if ($delta < 0 && $balance < 0) {
                $this->conflict($pdo, $ctx, 'negative_stock', 'items', $gid, sprintf(
                    'Stock went below zero (%s) after a change of %s recorded while %s could not check central stock.',
                    self::number($balance), self::number($delta), $ctx['name']
                ));
            }
            $accepted[] = $key;
        }
        return [array_values(array_unique($accepted)), $version];
    }

    /** Gives a reservation's stock back once; a second release is a no-op. */
    private function release(PDO $pdo, array $ctx, string $key, int $version): int
    {
        $stmt = $pdo->prepare(
            "SELECT `lines` FROM stock_reservations
              WHERE business_id = :b AND reservation_key = :k AND terminal_id = :t AND status = 'committed' FOR UPDATE"
        );
        $stmt->execute(['b' => $ctx['business_id'], 'k' => $key, 't' => $ctx['terminal_id']]);
        $lines = $stmt->fetchColumn();
        if ($lines === false) {
            return $version;
        }
        $read = $pdo->prepare('SELECT qty FROM sync_stock WHERE business_id = :b AND item_gid = :i FOR UPDATE');
        foreach (json_decode($lines, true) ?: [] as $gid => $qty) {
            $read->execute(['b' => $ctx['business_id'], 'i' => $gid]);
            $balance = round((float) $read->fetchColumn() + (float) $qty, 4);
            $version = $this->writeBalance($pdo, $ctx['business_id'], (string) $gid, $balance, $version);
            $pdo->prepare(
                "INSERT IGNORE INTO stock_ledger (business_id, event_key, item_gid, qty_change, balance_after, kind, terminal_id)
                 VALUES (:b, :k, :i, :q, :bal, 'release', :t)"
            )->execute(['b' => $ctx['business_id'], 'k' => 'release:' . $key, 'i' => $gid, 'q' => (float) $qty, 'bal' => $balance, 't' => $ctx['terminal_id']]);
        }
        $pdo->prepare(
            "UPDATE stock_reservations SET status = 'released', released_at = NOW() WHERE business_id = :b AND reservation_key = :k"
        )->execute(['b' => $ctx['business_id'], 'k' => $key]);
        return $version;
    }

    private function writeBalance(PDO $pdo, int $businessId, string $gid, float $balance, int $version): int
    {
        $version++;
        $pdo->prepare(
            'INSERT INTO sync_stock (business_id, item_gid, qty, version, updated_at) VALUES (:b, :i, :q, :v, NOW())
             ON DUPLICATE KEY UPDATE qty = VALUES(qty), version = VALUES(version), updated_at = NOW()'
        )->execute(['b' => $businessId, 'i' => $gid, 'q' => $balance, 'v' => $version]);
        return $version;
    }

    /** @param list<string> $gids */
    private function balances(PDO $pdo, int $businessId, array $gids): array
    {
        $out = [];
        $read = $pdo->prepare('SELECT qty FROM sync_stock WHERE business_id = :b AND item_gid = :i');
        foreach ($gids as $gid) {
            $read->execute(['b' => $businessId, 'i' => $gid]);
            $qty = $read->fetchColumn();
            if ($qty !== false) {
                $out[$gid] = (float) $qty;
            }
        }
        return $out;
    }

    /** Locks the business row and returns its current version. */
    private function lockVersion(PDO $pdo, int $businessId): int
    {
        $stmt = $pdo->prepare('SELECT sync_version FROM businesses WHERE id = :b FOR UPDATE');
        $stmt->execute(['b' => $businessId]);
        return (int) $stmt->fetchColumn();
    }

    /** Flat copy of a sale for server-side totals (admin panel). */
    private function projectSale(PDO $pdo, int $businessId, string $gid, ?array $data): void
    {
        if ($data === null) {
            $pdo->prepare('UPDATE business_sales SET deleted = 1 WHERE business_id = :b AND sale_gid = :g')
                ->execute(['b' => $businessId, 'g' => $gid]);
            return;
        }
        $soldAt = is_string($data['created_at'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $data['created_at']) === 1
            ? $data['created_at'] : date('Y-m-d H:i:s');
        $origin = $pdo->prepare('SELECT id FROM pos_terminals WHERE business_id = :b AND install_id = :i ORDER BY id DESC LIMIT 1');
        $origin->execute(['b' => $businessId, 'i' => strstr($gid, ':', true) ?: '']);
        $terminal = $origin->fetchColumn();
        $pdo->prepare(
            'INSERT INTO business_sales (business_id, sale_gid, terminal_id, cashier_gid, total_minor, payment_method, sold_at, deleted)
             VALUES (:b, :g, :t, :c, :total, :method, :at, 0)
             ON DUPLICATE KEY UPDATE terminal_id = VALUES(terminal_id), cashier_gid = VALUES(cashier_gid), total_minor = VALUES(total_minor),
                                     payment_method = VALUES(payment_method), sold_at = VALUES(sold_at), deleted = 0'
        )->execute([
            'b' => $businessId, 'g' => $gid, 't' => $terminal === false ? null : (int) $terminal,
            'c' => is_string($data['cashier_id'] ?? null) ? substr($data['cashier_id'], 0, 64) : null,
            'total' => (int) ($data['total_minor'] ?? 0), 'method' => substr((string) ($data['payment_method'] ?? 'cash'), 0, 20),
            'at' => $soldAt,
        ]);
    }

    private function conflict(PDO $pdo, array $ctx, string $kind, ?string $entity, ?string $gid, string $detail): void
    {
        $pdo->prepare(
            'INSERT INTO sync_conflicts (business_id, terminal_id, kind, entity, gid, detail) VALUES (:b, :t, :k, :e, :g, :d)'
        )->execute([
            'b' => $ctx['business_id'], 't' => $ctx['terminal_id'], 'k' => $kind,
            'e' => $entity === null ? null : substr($entity, 0, 40), 'g' => $gid === null ? null : substr($gid, 0, 64),
            'd' => mb_substr($detail, 0, 500),
        ]);
    }

    /** What the counter says about itself, kept for the admin panel. */
    private function report(PDO $pdo, array $ctx, array $input, string $column): void
    {
        $column = $column === 'last_push_at' ? 'last_push_at' : 'last_pull_at';
        $pending = isset($input['pending']) && is_int($input['pending']) && $input['pending'] >= 0 ? $input['pending'] : null;
        $error = isset($input['last_error']) && is_string($input['last_error']) && trim($input['last_error']) !== ''
            ? mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $input['last_error'])), 0, 255) : null;
        $hasReport = array_key_exists('last_error', $input) ? 1 : 0;
        // A counter polls every few seconds: a pull that reports nothing new
        // within 30 seconds of the last one is not written (shared hosting
        // pays for every write).
        $quiet = $column === 'last_pull_at'
            ? ' AND (last_seen_at IS NULL OR last_seen_at < NOW() - INTERVAL 30 SECOND
                     OR NOT (pending_count <=> COALESCE(:pending3, pending_count))
                     OR (:has_report3 = 1 AND NOT (last_error <=> :error3)))'
            : '';
        $params = [
            'pending' => $pending, 'has_report' => $hasReport, 'error' => $error,
            'has_report2' => $hasReport, 'error2' => $error, 't' => $ctx['terminal_id'],
        ];
        if ($quiet !== '') {
            $params += ['pending3' => $pending, 'has_report3' => $hasReport, 'error3' => $error];
        }
        $pdo->prepare(
            "UPDATE pos_terminals SET last_seen_at = NOW(), {$column} = NOW(),
                    pending_count = COALESCE(:pending, pending_count),
                    last_error = IF(:has_report = 1, :error, last_error),
                    last_error_at = IF(:has_report2 = 1 AND :error2 IS NOT NULL, NOW(), last_error_at)
              WHERE id = :t{$quiet}"
        )->execute($params);
    }

    private static function invalidChange(mixed $change): ?string
    {
        if (!is_array($change) || !isset($change['id']) || !is_int($change['id'])) {
            return 'Malformed change.';
        }
        if (!is_string($change['entity'] ?? null) || !in_array($change['entity'], self::ENTITIES, true)) {
            return 'Unknown record type.';
        }
        if (!is_string($change['gid'] ?? null) || !self::validGid($change['gid'])) {
            return 'Invalid record id.';
        }
        $op = $change['op'] ?? null;
        if ($op === 'delete') {
            return null;
        }
        if ($op !== 'upsert' || !is_array($change['data'] ?? null) || array_is_list($change['data']) && $change['data'] !== []) {
            return 'Invalid record contents.';
        }
        if (strlen((string) json_encode($change['data'])) > self::MAX_ROW_BYTES) {
            return 'Record too large to sync.';
        }
        $fields = $change['fields'] ?? null;
        if ($fields !== null && (!is_array($fields) || !array_is_list($fields) || count($fields) > 200
                || array_filter($fields, static fn ($f) => !is_string($f) || preg_match('/^[a-z_][a-z0-9_]{0,63}$/', $f) !== 1) !== [])) {
            return 'Invalid list of changed fields.';
        }
        if (isset($change['base']) && !is_int($change['base'])) {
            return 'Invalid base version.';
        }
        return null;
    }

    /**
     * The record after this change, its field versions, and the fields this
     * change overwrote although the counter had not seen their latest value.
     *
     * - A change that lists `fields` (what the counter edited) replaces only
     *   those, so counters editing different fields of one record both keep
     *   their edit.
     * - A change without `fields` (a new record, or an older POS build)
     *   replaces the record, keeping fields it does not carry.
     * - `base` is the counter's sync cursor when it made the edit: a field
     *   that another counter wrote after that version was overwritten
     *   without being seen, which is recorded as a `concurrent_edit`.
     *
     * @return array{0: array, 1: ?array, 2: list<string>}
     */
    private static function merge(?array $existing, array $change, int $version, int $terminalId): array
    {
        $incoming = $change['data'];
        if ($existing === null || (int) $existing['deleted'] === 1) {
            return [$incoming, null, []];
        }
        $current = json_decode((string) $existing['data'], true);
        $current = is_array($current) ? $current : [];
        $versions = json_decode((string) ($existing['field_versions'] ?? ''), true);
        $versions = is_array($versions) ? $versions : [];

        $fields = $change['fields'] ?? null;
        $candidates = is_array($fields) ? $fields : array_keys($incoming);
        $base = isset($change['base']) && is_int($change['base']) ? $change['base'] : null;
        $merged = $current;
        $overwritten = [];
        foreach ($candidates as $field) {
            if (!array_key_exists($field, $incoming)) {
                continue;
            }
            $changed = !array_key_exists($field, $current) || $current[$field] !== $incoming[$field];
            if (!$changed) {
                continue;
            }
            $last = $versions[$field] ?? null;
            if ($base !== null && is_array($last) && (int) ($last[0] ?? 0) > $base && (int) ($last[1] ?? 0) !== $terminalId) {
                $overwritten[] = $field;
            }
            $merged[$field] = $incoming[$field];
            $versions[$field] = [$version, $terminalId];
        }
        return [$merged, $versions === [] ? null : $versions, $overwritten];
    }

    public static function validGid(string $gid): bool
    {
        return preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $gid) === 1;
    }

    public static function validKey(string $key): bool
    {
        return preg_match('/^[A-Za-z0-9_.:-]{8,100}$/', $key) === 1;
    }

    private static function list(mixed $value, int $max, string $what): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new WorkspaceException('bad_request', "Invalid {$what}.", 422);
        }
        if (count($value) > $max) {
            throw new WorkspaceException('bad_request', "Too many {$what} in one request (at most {$max}).", 413);
        }
        return $value;
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
