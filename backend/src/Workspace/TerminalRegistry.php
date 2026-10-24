<?php
declare(strict_types=1);

namespace Diwan\Workspace;

use Diwan\Database\Database;
use Diwan\License\LicenseService;
use Diwan\Support\AuditLog;
use PDO;

/**
 * Counters (POS terminals) and the business workspace they share.
 *
 * A business is created the first time any device of a licence connects,
 * and is keyed by the licence — renewals, plan changes and key rotation all
 * keep the same licence row, so they keep the same workspace. A counter is
 * one device slot of that licence (license_activations), which makes the
 * licence's `max_devices` the counter limit with no second number to keep in
 * step.
 *
 * Nothing here ever takes a business id from the client: the licence key and
 * the device's activation decide it at registration, and afterwards the
 * counter's token is the only credential the sync API accepts.
 *
 * Joining rules (see docs/MULTI-COUNTER.md §3):
 *  - the first counter may *seed* an empty workspace with the data it
 *    already has (claimSeed → push → completeSeed);
 *  - every other counter, and any counter re-registered with a fresh
 *    database, starts by downloading the business data and replacing its
 *    own (the POS backs its file up first), then calls markJoined;
 *  - a counter may push only once it has joined, so a stray install can
 *    never merge an unrelated local inventory into the business.
 */
final class TerminalRegistry
{
    public const TOKEN_HEADER = 'HTTP_X_TERMINAL_TOKEN';

    /**
     * Registers (or re-registers) this device as a counter of its licence's
     * business and returns a new token — the only time it is in plaintext.
     *
     * @return array{token: string, terminal: array, business: array}
     */
    public function register(
        string $rawKey,
        string $fingerprintHash,
        string $installId,
        ?string $name,
        ?string $platform,
        ?string $appVersion
    ): array {
        $installId = self::installId($installId);
        $name = $name === null ? null : self::name($name);
        $keyHash = (new LicenseService())->lookupHash($rawKey);

        return Database::transaction(function (PDO $pdo) use ($keyHash, $fingerprintHash, $installId, $name, $platform, $appVersion): array {
            $stmt = $pdo->prepare(
                'SELECT l.id, l.customer_id, l.customer_email, a.id AS activation_id, c.business_name,
                        (l.status = "active" AND (l.expires_at IS NULL OR l.expires_at > NOW())
                         AND (c.status IS NULL OR c.status = "active")) AS entitled
                   FROM licenses l
                   JOIN license_activations a
                     ON a.license_id = l.id AND a.machine_hash = :fp AND a.deactivated_at IS NULL
              LEFT JOIN customers c ON c.id = l.customer_id
                  WHERE l.license_key_hash = :hash
                  LIMIT 1 FOR UPDATE'
            );
            $stmt->execute(['hash' => $keyHash, 'fp' => $fingerprintHash]);
            $license = $stmt->fetch();
            // One answer for a wrong key, another device's key and a lapsed
            // licence, exactly like license-status.php.
            if (!$license || !(bool) $license['entitled']) {
                throw new WorkspaceException('not_entitled',
                    'This device is not activated for an active licence. Activate the licence on this device first.', 403);
            }

            $business = $this->businessForLicense($pdo, (int) $license['id'], $license['customer_id'] === null ? null : (int) $license['customer_id'],
                (string) ($license['business_name'] ?: ''));
            $activationId = (int) $license['activation_id'];

            $clash = $pdo->prepare(
                "SELECT name FROM pos_terminals
                  WHERE business_id = :b AND install_id = :i AND status = 'active' AND activation_id <> :a LIMIT 1"
            );
            $clash->execute(['b' => $business['id'], 'i' => $installId, 'a' => $activationId]);
            if (($other = $clash->fetchColumn()) !== false) {
                throw new WorkspaceException('install_in_use',
                    "This installation is a copy of {$other}. Each counter needs its own installation.", 409);
            }

            $token = 'dwt_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $existing = $pdo->prepare('SELECT * FROM pos_terminals WHERE activation_id = :a FOR UPDATE');
            $existing->execute(['a' => $activationId]);
            $terminal = $existing->fetch();

            if ($terminal) {
                // Same database (same install id): it keeps its place. A fresh
                // database must download the business data before it may push.
                $sameInstall = $terminal['install_id'] === $installId && (int) $terminal['business_id'] === $business['id'];
                $pdo->prepare(
                    "UPDATE pos_terminals
                        SET business_id = :b, token_hash = :token, install_id = :install, status = 'active', revoked_at = NULL,
                            name = COALESCE(:name, name), platform = :platform, app_version = :version,
                            joined_at = IF(:same = 1, joined_at, NULL), last_seen_at = NOW()
                      WHERE id = :id"
                )->execute([
                    'b' => $business['id'], 'token' => hash('sha256', $token), 'install' => $installId, 'name' => $name,
                    'platform' => self::short($platform, 20), 'version' => self::short($appVersion, 32),
                    'same' => $sameInstall ? 1 : 0, 'id' => $terminal['id'],
                ]);
                $terminalId = (int) $terminal['id'];
            } else {
                $count = $pdo->prepare('SELECT COUNT(*) FROM pos_terminals WHERE business_id = :b');
                $count->execute(['b' => $business['id']]);
                $pdo->prepare(
                    'INSERT INTO pos_terminals (business_id, activation_id, code, name, install_id, token_hash, platform, app_version, last_seen_at)
                     VALUES (:b, :a, :code, :name, :install, :token, :platform, :version, NOW())'
                )->execute([
                    'b' => $business['id'], 'a' => $activationId, 'code' => self::newCode($pdo),
                    'name' => $name ?? 'Counter ' . ((int) $count->fetchColumn() + 1), 'install' => $installId,
                    'token' => hash('sha256', $token), 'platform' => self::short($platform, 20), 'version' => self::short($appVersion, 32),
                ]);
                $terminalId = (int) $pdo->lastInsertId();
            }

            $row = $this->terminalRow($pdo, $terminalId);
            AuditLog::record('terminal', $terminalId, $row['name'], 'terminal.register', 'business', $business['id'],
                ['code' => $row['code'], 'platform' => $row['platform'], 'reinstalled' => $terminal !== false && $row['joined_at'] === null], $pdo);

            return [
                'token'    => $token,
                'terminal' => self::describeTerminal($row),
                'business' => $this->describeBusiness($pdo, $business['id']),
            ];
        });
    }

    /**
     * The counter behind a token, with its business and licence state.
     * Refuses a revoked counter, a freed device slot and a licence that may
     * not be used; the POS keeps everything it has and retries later.
     */
    public function authenticate(?string $token): array
    {
        $token = trim((string) $token);
        if (preg_match('/^dwt_[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            throw new WorkspaceException('unauthenticated', 'This counter is not connected to a business.', 401);
        }
        $stmt = Database::pdo()->prepare(
            'SELECT t.id, t.business_id, t.code, t.name, t.install_id, t.status, t.joined_at,
                    b.license_id, b.name AS business_name, a.deactivated_at,
                    l.status AS license_status, (l.expires_at IS NOT NULL AND l.expires_at <= NOW()) AS past_expiry,
                    c.status AS customer_status
               FROM pos_terminals t
               JOIN businesses b ON b.id = t.business_id
               JOIN license_activations a ON a.id = t.activation_id
               JOIN licenses l ON l.id = b.license_id
          LEFT JOIN customers c ON c.id = l.customer_id
              WHERE t.token_hash = :hash
              LIMIT 1'
        );
        $stmt->execute(['hash' => hash('sha256', $token)]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new WorkspaceException('unauthenticated', 'This counter is not connected to a business. Connect it again in Settings.', 401);
        }
        if ($row['status'] !== 'active' || $row['deactivated_at'] !== null) {
            throw new WorkspaceException('terminal_revoked',
                'This counter was disconnected from the business. Connect it again in Settings.', 401);
        }
        if ($row['license_status'] !== 'active' || (bool) $row['past_expiry'] || $row['customer_status'] === 'suspended') {
            throw new WorkspaceException('license_inactive',
                'The business licence is not active, so this counter cannot sync. Everything recorded here is kept and will sync once it is renewed.', 403);
        }

        return [
            'terminal_id'   => (int) $row['id'],
            'business_id'   => (int) $row['business_id'],
            'license_id'    => (int) $row['license_id'],
            'code'          => (string) $row['code'],
            'name'          => (string) $row['name'],
            'install_id'    => (string) $row['install_id'],
            'joined'        => $row['joined_at'] !== null,
        ];
    }

    /** Workspace and counter as the POS shows them. */
    public function status(array $ctx): array
    {
        $pdo = Database::pdo();
        $this->touch($pdo, $ctx['terminal_id']);
        return [
            'terminal' => self::describeTerminal($this->terminalRow($pdo, $ctx['terminal_id'])),
            'business' => $this->describeBusiness($pdo, $ctx['business_id']),
        ];
    }

    /**
     * This counter's existing data becomes the business data. Only for an
     * empty workspace, and only one counter can ever win it.
     */
    public function claimSeed(array $ctx): array
    {
        Database::transaction(function (PDO $pdo) use ($ctx): void {
            $business = $this->lockBusiness($pdo, $ctx['business_id']);
            $seeder = $business['seeded_by_terminal_id'] === null ? null : (int) $business['seeded_by_terminal_id'];
            if ($seeder === $ctx['terminal_id'] && $business['seed_completed_at'] === null) {
                return;   // a retry of our own claim
            }
            if ($business['seed_completed_at'] !== null || $seeder !== null || (int) $business['sync_version'] > 0) {
                throw new WorkspaceException('seed_taken',
                    'This business already has data from another counter. Use the business data on this counter instead.');
            }
            $pdo->prepare('UPDATE businesses SET seeded_by_terminal_id = :t, seed_started_at = NOW() WHERE id = :b')
                ->execute(['t' => $ctx['terminal_id'], 'b' => $ctx['business_id']]);
            $pdo->prepare('UPDATE pos_terminals SET joined_at = NOW() WHERE id = :t')->execute(['t' => $ctx['terminal_id']]);
            AuditLog::record('terminal', $ctx['terminal_id'], $ctx['name'], 'business.seed_started', 'business', $ctx['business_id'], [], $pdo);
        });
        return $this->status($ctx);
    }

    /** The seeding counter has uploaded everything: others may now join. */
    public function completeSeed(array $ctx): array
    {
        Database::transaction(function (PDO $pdo) use ($ctx): void {
            $business = $this->lockBusiness($pdo, $ctx['business_id']);
            if ((int) ($business['seeded_by_terminal_id'] ?? 0) !== $ctx['terminal_id']) {
                throw new WorkspaceException('not_seeder', 'Only the counter that started the business data can finish it.');
            }
            if ($business['seed_completed_at'] === null) {
                $pdo->prepare('UPDATE businesses SET seed_completed_at = NOW() WHERE id = :b')->execute(['b' => $ctx['business_id']]);
                AuditLog::record('terminal', $ctx['terminal_id'], $ctx['name'], 'business.seed_completed', 'business', $ctx['business_id'], [], $pdo);
            }
        });
        return $this->status($ctx);
    }

    /** The counter now holds the business data (it downloaded and applied all of it). */
    public function markJoined(array $ctx): array
    {
        Database::transaction(function (PDO $pdo) use ($ctx): void {
            $business = $this->lockBusiness($pdo, $ctx['business_id']);
            if ($business['seed_completed_at'] === null) {
                throw new WorkspaceException('not_seeded', 'The business data is still being uploaded by its first counter. Try again shortly.');
            }
            $pdo->prepare('UPDATE pos_terminals SET joined_at = COALESCE(joined_at, NOW()) WHERE id = :t')->execute(['t' => $ctx['terminal_id']]);
            AuditLog::record('terminal', $ctx['terminal_id'], $ctx['name'], 'terminal.joined', 'business', $ctx['business_id'], [], $pdo);
        });
        return $this->status($ctx);
    }

    public function rename(array $ctx, string $name): array
    {
        $name = self::name($name);
        Database::pdo()->prepare('UPDATE pos_terminals SET name = :n WHERE id = :t')->execute(['n' => $name, 't' => $ctx['terminal_id']]);
        AuditLog::record('terminal', $ctx['terminal_id'], $name, 'terminal.rename', 'business', $ctx['business_id'], ['from' => $ctx['name']]);
        return $this->status($ctx);
    }

    /** @return array{id: int} */
    private function businessForLicense(PDO $pdo, int $licenseId, ?int $customerId, string $name): array
    {
        $stmt = $pdo->prepare('SELECT id FROM businesses WHERE license_id = :l');
        $stmt->execute(['l' => $licenseId]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            $pdo->prepare('INSERT INTO businesses (license_id, customer_id, name) VALUES (:l, :c, :n)')
                ->execute(['l' => $licenseId, 'c' => $customerId, 'n' => trim($name) !== '' ? mb_substr(trim($name), 0, 120) : 'Business']);
            $id = (int) $pdo->lastInsertId();
            AuditLog::record('system', null, null, 'business.created', 'business', $id, ['license_id' => $licenseId], $pdo);
        }
        return ['id' => (int) $id];
    }

    private function lockBusiness(PDO $pdo, int $businessId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM businesses WHERE id = :b FOR UPDATE');
        $stmt->execute(['b' => $businessId]);
        return $stmt->fetch();
    }

    public function describeBusiness(PDO $pdo, int $businessId): array
    {
        $stmt = $pdo->prepare(
            "SELECT b.id, b.name, b.sync_version, b.seeded_by_terminal_id, b.seed_started_at, b.seed_completed_at,
                    l.max_devices, p.name AS plan_name,
                    (SELECT COUNT(*) FROM pos_terminals t WHERE t.business_id = b.id AND t.status = 'active') AS counters
               FROM businesses b
               JOIN licenses l ON l.id = b.license_id
          LEFT JOIN plans p ON p.id = l.plan_id
              WHERE b.id = :b"
        );
        $stmt->execute(['b' => $businessId]);
        $b = $stmt->fetch();
        return [
            'id'          => (int) $b['id'],
            'name'        => (string) $b['name'],
            'version'     => (int) $b['sync_version'],
            'has_data'    => $b['seed_completed_at'] !== null,
            'seeding'     => $b['seed_started_at'] !== null && $b['seed_completed_at'] === null,
            'counters'    => (int) $b['counters'],
            'max_counters'=> max(1, (int) $b['max_devices']),
            'plan'        => $b['plan_name'],
        ];
    }

    private function terminalRow(PDO $pdo, int $terminalId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM pos_terminals WHERE id = :t');
        $stmt->execute(['t' => $terminalId]);
        return $stmt->fetch();
    }

    public static function describeTerminal(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'code'       => (string) $row['code'],
            'name'       => (string) $row['name'],
            'install_id' => (string) $row['install_id'],
            'joined'     => $row['joined_at'] !== null,
        ];
    }

    public function touch(PDO $pdo, int $terminalId, string $column = 'last_seen_at'): void
    {
        $column = in_array($column, ['last_push_at', 'last_pull_at'], true) ? $column : 'last_seen_at';
        $pdo->prepare("UPDATE pos_terminals SET last_seen_at = NOW(), {$column} = NOW() WHERE id = :t")->execute(['t' => $terminalId]);
    }

    public static function installId(string $installId): string
    {
        $installId = strtolower(trim($installId));
        if (preg_match('/^[a-f0-9]{16}$/', $installId) !== 1) {
            throw new WorkspaceException('bad_request', 'Invalid installation id.', 422);
        }
        return $installId;
    }

    public static function name(string $name): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name));
        if ($name === '' || mb_strlen($name) > 60) {
            throw new WorkspaceException('bad_request', 'A counter name is 1 to 60 characters.', 422);
        }
        return $name;
    }

    private static function short(?string $value, int $max): ?string
    {
        $value = trim((string) preg_replace('/[^\x20-\x7E]/', '', (string) $value));
        return $value === '' ? null : substr($value, 0, $max);
    }

    private static function newCode(PDO $pdo): string
    {
        // Unambiguous characters only: the code is read aloud to support.
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $stmt = $pdo->prepare('SELECT 1 FROM pos_terminals WHERE code = :c');
            $stmt->execute(['c' => $code]);
        } while ($stmt->fetchColumn() !== false);
        return $code;
    }
}
