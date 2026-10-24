<?php
declare(strict_types=1);

namespace Diwan\License;

use Diwan\Database\Database;
use Diwan\Notification\NotificationService;
use Diwan\Plan\PlanCatalog;
use Diwan\Support\AuditLog;
use Diwan\Support\UserFacingException;
use PDO;

/**
 * What an administrator can do to an existing licence. Each action locks the
 * licence row, checks the transition is allowed, writes the change and its
 * audit entry in one transaction, then notifies the customer.
 *
 * Status transitions (anything else is refused):
 *
 *   active ──suspend──▶ suspended ──reactivate──▶ active
 *     │                    │
 *     └──────revoke────────┴──▶ revoked   (final: issue a new licence instead)
 *
 *   An expired licence (status active/expired, expiry passed) comes back
 *   with `extend`. Revoked licences cannot be extended, rotated or changed.
 *
 * The app learns about any of these at its next licence check-in (on launch
 * and every few hours while online). A device that stays offline keeps its
 * last signed status until `max_offline_days` runs out, then locks — the
 * documented limit of offline-capable licensing.
 */
final class LicenseAdmin
{
    public const ACTIONS = ['suspend', 'reactivate', 'revoke', 'extend', 'change_plan', 'set_devices',
                            'deactivate_device', 'reset_devices', 'rotate_key'];

    /** @return array{license: array, license_key?: string} */
    public function apply(int $licenseId, string $action, array $input, array $admin): array
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new UserFacingException('Unknown action.');
        }
        $reason = trim((string) ($input['reason'] ?? ''));
        if (mb_strlen($reason) > 255) {
            throw new UserFacingException('Reason can be at most 255 characters.');
        }
        $reason = $reason === '' ? null : $reason;

        $result = Database::transaction(function (PDO $pdo) use ($licenseId, $action, $input, $admin, $reason): array {
            $stmt = $pdo->prepare(
                'SELECT l.*, (l.expires_at IS NOT NULL AND l.expires_at <= NOW()) AS past_expiry
                   FROM licenses l WHERE l.id = :id FOR UPDATE'
            );
            $stmt->execute(['id' => $licenseId]);
            $license = $stmt->fetch();
            if (!$license) {
                throw new UserFacingException('Licence not found.', 404);
            }
            if ($license['status'] === 'revoked' && $action !== 'deactivate_device' && $action !== 'reset_devices') {
                throw new UserFacingException('This licence is revoked. Issue a new licence instead.', 409);
            }

            $details = [];
            $notify = null;
            $key = null;
            switch ($action) {
                case 'suspend':
                    if ($license['status'] !== 'active') {
                        throw new UserFacingException('Only an active licence can be suspended.', 409);
                    }
                    $this->setStatus($pdo, $licenseId, 'suspended', $reason);
                    $notify = ['license.suspended', 'Your Diwan licence has been suspended',
                        'Your licence has been suspended, so the app will stop billing at its next check-in.'
                        . ($reason !== null ? "\n\nReason: {$reason}" : '') . "\n\nPlease contact support to restore it."];
                    break;

                case 'reactivate':
                    if ($license['status'] !== 'suspended') {
                        throw new UserFacingException('Only a suspended licence can be reactivated.', 409);
                    }
                    $this->setStatus($pdo, $licenseId, 'active', $reason);
                    $notify = ['license.reactivated', 'Your Diwan licence is active again',
                        'Your licence has been restored. The app unlocks at its next check-in — reopen it while online.'];
                    break;

                case 'revoke':
                    $this->setStatus($pdo, $licenseId, 'revoked', $reason);
                    $notify = ['license.revoked', 'Your Diwan licence has been revoked',
                        'Your licence has been revoked and can no longer be used.'
                        . ($reason !== null ? "\n\nReason: {$reason}" : '')];
                    break;

                case 'extend':
                    $days = filter_var($input['days'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3660]]);
                    if ($days === false) {
                        throw new UserFacingException('Extend by 1 to 3660 days.');
                    }
                    if ($license['status'] === 'suspended') {
                        throw new UserFacingException('Reactivate the licence before extending it.', 409);
                    }
                    // Extends from today for a lapsed licence, from the current
                    // expiry otherwise, so an early renewal loses no days.
                    $pdo->prepare(
                        "UPDATE licenses SET status = 'active',
                                expires_at = DATE_ADD(GREATEST(NOW(), COALESCE(expires_at, NOW())), INTERVAL :days DAY),
                                status_changed_at = NOW(), status_reason = :reason
                          WHERE id = :id"
                    )->execute(['days' => $days, 'reason' => $reason ?? "Extended by {$days} days", 'id' => $licenseId]);
                    $details['days'] = $days;
                    $notify = ['license.extended', 'Your Diwan licence has been extended',
                        "Your licence has been extended by {$days} days."];
                    break;

                case 'change_plan':
                    $plan = (new PlanCatalog())->findById((int) ($input['plan_id'] ?? 0));
                    if ($plan === null) {
                        throw new UserFacingException('Plan not found.', 404);
                    }
                    $pdo->prepare('UPDATE licenses SET plan_id = :plan WHERE id = :id')
                        ->execute(['plan' => $plan['id'], 'id' => $licenseId]);
                    $details['from_plan_id'] = $license['plan_id'] === null ? null : (int) $license['plan_id'];
                    $details['to_plan'] = $plan['code'];
                    $notify = ['license.plan_changed', "Your licence is now on the {$plan['name']} plan",
                        "Your licence has been moved to the {$plan['name']} plan. The app picks up the change at its next check-in."];
                    break;

                case 'set_devices':
                    $devices = filter_var($input['max_devices'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
                    if ($devices === false) {
                        throw new UserFacingException('Devices must be between 1 and 1000.');
                    }
                    $pdo->prepare('UPDATE licenses SET max_devices = :devices WHERE id = :id')
                        ->execute(['devices' => $devices, 'id' => $licenseId]);
                    $details = ['from' => (int) $license['max_devices'], 'to' => $devices];
                    break;

                case 'deactivate_device':
                    $activationId = (int) ($input['activation_id'] ?? 0);
                    $stmt = $pdo->prepare(
                        'UPDATE license_activations SET deactivated_at = NOW()
                          WHERE id = :activation AND license_id = :license AND deactivated_at IS NULL'
                    );
                    $stmt->execute(['activation' => $activationId, 'license' => $licenseId]);
                    if ($stmt->rowCount() === 0) {
                        throw new UserFacingException('That device is not active on this licence.', 404);
                    }
                    $details['activation_id'] = $activationId;
                    break;

                case 'reset_devices':
                    $stmt = $pdo->prepare(
                        'UPDATE license_activations SET deactivated_at = NOW() WHERE license_id = :id AND deactivated_at IS NULL'
                    );
                    $stmt->execute(['id' => $licenseId]);
                    $details['devices_freed'] = $stmt->rowCount();
                    $notify = ['license.devices_reset', 'Your licence is ready for a new device',
                        'Your licence has been released from its devices. Enter your licence key in the app on the device you want to use.'];
                    break;

                case 'rotate_key':
                    $key = (new LicenseService())->rotateKey($pdo, $licenseId);
                    $details['new_prefix'] = substr($key, 0, 11);
                    $notify = ['license.key_rotated', 'Your Diwan licence key has changed',
                        'Your licence key has been replaced and the old key no longer works. The new key is in your dashboard: '
                        . 'enter it in the app (Settings → Licence) on each device.'];
                    break;
            }

            AuditLog::record('admin', (int) $admin['id'], (string) $admin['email'], "license.{$action}", 'license', $licenseId,
                $details + ($reason !== null ? ['reason' => $reason] : []) + ['key_prefix' => $license['license_key_prefix']], $pdo);

            return ['notify' => $notify, 'customer_id' => $license['customer_id'], 'key' => $key];
        });

        if ($result['notify'] !== null && $result['customer_id'] !== null) {
            [$type, $title, $body] = $result['notify'];
            (new NotificationService())->toCustomer((int) $result['customer_id'], $type, $title, $body, '/dashboard.html#plan');
        }

        $out = ['license' => $this->detail($licenseId)];
        if ($result['key'] !== null) {
            $out['license_key'] = $result['key'];
        }
        return $out;
    }

    /** One licence with its devices and history, for the admin detail view. */
    public function detail(int $licenseId): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT l.id, l.customer_id, l.customer_email, l.license_key_prefix, l.status, l.status_reason,
                    l.status_changed_at, l.activation_status, l.max_devices, l.expires_at, l.created_at, l.activated_at,
                    l.downloads_used, l.max_downloads, l.plan_id, p.name AS plan_name, p.code AS plan_code,
                    o.order_ref, o.gateway, l.license_request_id, a.email AS issued_by_email,
                    (l.expires_at IS NOT NULL AND l.expires_at <= NOW()) AS past_expiry
               FROM licenses l
               JOIN orders o ON o.id = l.order_id
          LEFT JOIN plans p ON p.id = l.plan_id
          LEFT JOIN admin_users a ON a.id = l.issued_by
              WHERE l.id = :id'
        );
        $stmt->execute(['id' => $licenseId]);
        $license = $stmt->fetch();
        if (!$license) {
            throw new UserFacingException('Licence not found.', 404);
        }
        $license['display_status'] = self::displayStatus($license);
        unset($license['past_expiry']);

        $devices = $pdo->prepare(
            'SELECT id, machine_hint, activated_at, last_seen_at, deactivated_at FROM license_activations
              WHERE license_id = :id ORDER BY deactivated_at IS NULL DESC, activated_at DESC'
        );
        $devices->execute(['id' => $licenseId]);
        $license['devices'] = $devices->fetchAll();

        $history = $pdo->prepare(
            "SELECT action, actor_label, details, created_at FROM audit_logs
              WHERE (target_type = 'license' AND target_id = :id)
                 OR (target_type = 'license_request' AND target_id IN
                       (SELECT r.id FROM license_requests r WHERE r.license_id = :id2))
              ORDER BY id DESC LIMIT 50"
        );
        $history->execute(['id' => $licenseId, 'id2' => $licenseId]);
        $license['history'] = $history->fetchAll();
        return $license;
    }

    /**
     * issued | active | suspended | revoked | expired — the single word the
     * admin panel shows, folding status, expiry and whether any device has
     * activated the key yet.
     */
    public static function displayStatus(array $row): string
    {
        if (in_array($row['status'], ['revoked', 'suspended'], true)) {
            return $row['status'];
        }
        if ($row['status'] === 'expired' || (bool) $row['past_expiry']) {
            return 'expired';
        }
        return $row['activation_status'] === 'activated' ? 'active' : 'issued';
    }

    private function setStatus(PDO $pdo, int $licenseId, string $status, ?string $reason): void
    {
        $pdo->prepare(
            'UPDATE licenses SET status = :status, status_changed_at = NOW(), status_reason = :reason WHERE id = :id'
        )->execute(['status' => $status, 'reason' => $reason, 'id' => $licenseId]);
    }
}
