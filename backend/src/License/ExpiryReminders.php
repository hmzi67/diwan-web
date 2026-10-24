<?php
declare(strict_types=1);

namespace Diwan\License;

use Diwan\Admin\AdminSettings;
use Diwan\Database\Database;
use Diwan\Notification\NotificationService;
use PDO;

/**
 * "Expiring soon" and "expired" notices for plan licences. Safe to run as
 * often as you like: each notice is keyed on the licence and its expiry
 * date, so it goes out once per expiry (and again after a renewal moves the
 * date). Run by scripts/license-reminders.php from cron, and opportunistically
 * — at most hourly — when an administrator opens the dashboard, so reminders
 * still go out on a host without cron.
 */
final class ExpiryReminders
{
    /** @return array{expiring: int, expired: int} newly sent */
    public function run(): array
    {
        $pdo = Database::pdo();
        $days = AdminSettings::int('expiry_reminder_days');
        $notifications = new NotificationService();

        $expiring = $pdo->prepare(
            "SELECT l.id, l.customer_id, DATE(l.expires_at) AS expires_on, p.name AS plan_name
               FROM licenses l JOIN plans p ON p.id = l.plan_id JOIN customers c ON c.id = l.customer_id
              WHERE l.status = 'active' AND c.status = 'active'
                AND l.expires_at > NOW() AND l.expires_at <= NOW() + INTERVAL :days DAY"
        );
        $expiring->execute(['days' => $days]);
        $sentExpiring = 0;
        foreach ($expiring->fetchAll() as $l) {
            $sentExpiring += $notifications->toCustomer((int) $l['customer_id'], 'license.expiring',
                "Your {$l['plan_name']} plan expires on {$l['expires_on']}",
                "Your Diwan licence expires on {$l['expires_on']}. Request a renewal from your dashboard before then to keep billing without interruption.",
                '/dashboard.html#plan', true, "expiring:{$l['id']}:{$l['expires_on']}") !== null ? 1 : 0;
        }

        // Only recent expiries: a licence that lapsed months before this
        // feature shipped should not get a surprise email now.
        $expired = $pdo->query(
            "SELECT l.id, l.customer_id, DATE(l.expires_at) AS expires_on, p.name AS plan_name
               FROM licenses l JOIN plans p ON p.id = l.plan_id JOIN customers c ON c.id = l.customer_id
              WHERE l.status = 'active' AND c.status = 'active'
                AND l.expires_at <= NOW() AND l.expires_at > NOW() - INTERVAL 30 DAY"
        )->fetchAll();
        $sentExpired = 0;
        foreach ($expired as $l) {
            $sentExpired += $notifications->toCustomer((int) $l['customer_id'], 'license.expired',
                "Your {$l['plan_name']} plan has expired",
                "Your Diwan licence expired on {$l['expires_on']}, so the app has stopped billing. Request a renewal from your dashboard to restore it.",
                '/dashboard.html#plan', true, "expired:{$l['id']}:{$l['expires_on']}") !== null ? 1 : 0;
        }
        return ['expiring' => $sentExpiring, 'expired' => $sentExpired];
    }

    /** run(), unless it already ran in the last hour (tracked in admin_settings). */
    public function runIfDue(): ?array
    {
        $pdo = Database::pdo();
        $claim = $pdo->prepare(
            "INSERT INTO admin_settings (setting_key, setting_value, updated_at) VALUES ('reminders_last_run', '', NOW())
             ON DUPLICATE KEY UPDATE updated_at = IF(updated_at < NOW() - INTERVAL 1 HOUR, NOW(), updated_at)"
        );
        $claim->execute();
        // rowCount: 1 = inserted, 2 = updated (due), 0 = unchanged (not due)
        return $claim->rowCount() > 0 ? $this->run() : null;
    }
}
