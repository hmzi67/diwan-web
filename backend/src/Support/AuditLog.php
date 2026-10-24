<?php
declare(strict_types=1);

namespace Diwan\Support;

use Diwan\Database\Database;
use PDO;

/**
 * Append-only record of every sensitive action (approvals, revocations, plan
 * edits, suspensions, sign-ins to the admin panel). Written in the same
 * transaction as the change when one is open, so a rolled-back action leaves
 * no entry. `details` must never carry a secret or a full licence key — pass
 * the key's prefix instead.
 */
final class AuditLog
{
    public static function record(
        string $actorType,
        ?int $actorId,
        ?string $actorLabel,
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        array $details = [],
        ?PDO $pdo = null
    ): void {
        ($pdo ?? Database::pdo())->prepare(
            'INSERT INTO audit_logs (actor_type, actor_id, actor_label, action, target_type, target_id, details, ip, created_at)
             VALUES (:type, :id, :label, :action, :target_type, :target_id, :details, :ip, NOW())'
        )->execute([
            'type'        => $actorType,
            'id'          => $actorId,
            'label'       => $actorLabel !== null ? mb_substr($actorLabel, 0, 190) : null,
            'action'      => $action,
            'target_type' => $targetType,
            'target_id'   => $targetId,
            'details'     => $details === [] ? null : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'ip'          => PHP_SAPI === 'cli' ? null : Http::clientIp(),
        ]);
    }
}
