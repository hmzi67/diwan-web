<?php
declare(strict_types=1);

namespace Diwan\Admin;

use Diwan\Database\Database;
use Diwan\Notification\NotificationService;
use Diwan\Support\AuditLog;
use Diwan\Support\UserFacingException;
use PDO;

/**
 * Account-level actions on a customer. Suspending signs the customer out
 * everywhere (Session reads a suspended account as missing) and makes every
 * licence they own report `suspended` to the app and refuse downloads —
 * without changing the licences, so reactivating restores all of it.
 */
final class CustomerAdmin
{
    public function suspend(int $customerId, array $admin, ?string $reason): void
    {
        $this->transition($customerId, $admin, 'active', 'suspended', $reason);
        (new NotificationService())->toCustomer($customerId, 'account.suspended', 'Your Diwan account has been suspended',
            'Your account has been suspended, so its licences stop working at the next check-in.'
            . ($reason !== null ? "\n\nReason: {$reason}" : '') . "\n\nPlease contact support.", null);
    }

    public function reactivate(int $customerId, array $admin, ?string $reason): void
    {
        $this->transition($customerId, $admin, 'suspended', 'active', $reason);
        (new NotificationService())->toCustomer($customerId, 'account.reactivated', 'Your Diwan account is active again',
            'Your account has been reactivated. Sign in again on the website; the app unlocks at its next check-in.', '/dashboard.html');
    }

    private function transition(int $customerId, array $admin, string $from, string $to, ?string $reason): void
    {
        if ($reason !== null && mb_strlen($reason) > 255) {
            throw new UserFacingException('Reason can be at most 255 characters.');
        }
        Database::transaction(static function (PDO $pdo) use ($customerId, $admin, $from, $to, $reason): void {
            $stmt = $pdo->prepare('SELECT status, email FROM customers WHERE id = :id AND deleted_at IS NULL FOR UPDATE');
            $stmt->execute(['id' => $customerId]);
            $customer = $stmt->fetch();
            if (!$customer) {
                throw new UserFacingException('User not found.', 404);
            }
            if ($customer['status'] === $to) {
                return;   // already there: idempotent
            }
            if ($customer['status'] !== $from) {
                throw new UserFacingException("This account is {$customer['status']}.", 409);
            }
            $pdo->prepare(
                'UPDATE customers SET status = :to, suspended_at = ' . ($to === 'suspended' ? 'NOW()' : 'NULL')
                . ($to === 'suspended' ? ', session_epoch = session_epoch + 1' : '') . ' WHERE id = :id'
            )->execute(['to' => $to, 'id' => $customerId]);
            AuditLog::record('admin', (int) $admin['id'], (string) $admin['email'],
                $to === 'suspended' ? 'customer.suspend' : 'customer.reactivate', 'customer', $customerId,
                ['email' => $customer['email']] + ($reason !== null ? ['reason' => $reason] : []), $pdo);
        });
    }
}
