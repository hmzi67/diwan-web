<?php
declare(strict_types=1);

namespace Diwan\Notification;

use Diwan\Admin\AdminSettings;
use Diwan\Config\Env;
use Diwan\Database\Database;
use Diwan\Support\Logger;
use Diwan\Support\Mailer;
use PDO;
use PDOException;

/**
 * In-app notifications, with an optional email copy.
 *
 * The in-app row is the record that always exists; the email is best-effort
 * and its real outcome is stored in `email_status` — `not_configured` when
 * MAIL_* is unset, `failed` when the transport refused it — so nothing ever
 * claims an email went out that did not. Email is sent after the row is
 * written and never throws, so a mail outage cannot undo an approval.
 */
final class NotificationService
{
    /**
     * Notifies one customer. Returns the new id, or null when $dedupeKey
     * matched an existing notification (a reminder that already went out).
     */
    public function toCustomer(
        int $customerId,
        string $type,
        string $title,
        string $body,
        ?string $link = null,
        bool $email = true,
        ?string $dedupeKey = null
    ): ?int {
        $id = $this->insert('customer', $customerId, $type, $title, $body, $link, $dedupeKey);
        if ($id !== null && $email) {
            $address = $this->customerEmail($customerId);
            $this->deliver($id, $address, $title, $body, $link);
        }
        return $id;
    }

    /** Notifies the administrators (in-app, plus the configured address if any). */
    public function toAdmins(string $type, string $title, string $body, ?string $link = null, ?string $dedupeKey = null): ?int
    {
        $id = $this->insert('admin', null, $type, $title, $body, $link, $dedupeKey);
        if ($id !== null) {
            $address = AdminSettings::string('admin_notification_email');
            if ($address !== '') {
                $this->deliver($id, $address, $title, $body, $link);
            }
        }
        return $id;
    }

    /** @return list<array<string,mixed>> newest first */
    public function forCustomer(int $customerId, int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = Database::pdo()->prepare(
            "SELECT id, type, title, body, link, created_at, read_at IS NOT NULL AS is_read
               FROM notifications WHERE audience = 'customer' AND customer_id = :id
              ORDER BY id DESC LIMIT {$limit}"
        );
        $stmt->execute(['id' => $customerId]);
        return array_map(static fn (array $r): array => ['is_read' => (bool) $r['is_read']] + $r, $stmt->fetchAll());
    }

    public function unreadCountForCustomer(int $customerId): int
    {
        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(*) FROM notifications WHERE audience = 'customer' AND customer_id = :id AND read_at IS NULL"
        );
        $stmt->execute(['id' => $customerId]);
        return (int) $stmt->fetchColumn();
    }

    /** Marks the customer's own notifications read; ids belonging to anyone else are ignored. */
    public function markCustomerRead(int $customerId, ?array $ids): int
    {
        return $this->markRead("audience = 'customer' AND customer_id = :owner", ['owner' => $customerId], $ids);
    }

    /** @return list<array<string,mixed>> newest first */
    public function forAdmins(int $limit = 50, bool $unreadOnly = false): array
    {
        $limit = max(1, min(200, $limit));
        $where = $unreadOnly ? 'AND read_at IS NULL' : '';
        return array_map(
            static fn (array $r): array => ['is_read' => (bool) $r['is_read']] + $r,
            Database::pdo()->query(
                "SELECT id, type, title, body, link, email_status, created_at, read_at IS NOT NULL AS is_read
                   FROM notifications WHERE audience = 'admin' {$where} ORDER BY id DESC LIMIT {$limit}"
            )->fetchAll()
        );
    }

    public function unreadCountForAdmins(): int
    {
        return (int) Database::pdo()->query(
            "SELECT COUNT(*) FROM notifications WHERE audience = 'admin' AND read_at IS NULL"
        )->fetchColumn();
    }

    public function markAdminRead(?array $ids): int
    {
        return $this->markRead("audience = 'admin'", [], $ids);
    }

    /** Recent notifications sent to customers, with what happened to each email. */
    public function recentCustomerNotifications(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        return Database::pdo()->query(
            "SELECT n.id, n.type, n.title, n.email_status, n.created_at, n.read_at, c.email
               FROM notifications n JOIN customers c ON c.id = n.customer_id
              WHERE n.audience = 'customer' ORDER BY n.id DESC LIMIT {$limit}"
        )->fetchAll();
    }

    private function insert(string $audience, ?int $customerId, string $type, string $title, string $body, ?string $link, ?string $dedupeKey): ?int
    {
        $pdo = Database::pdo();
        try {
            $pdo->prepare(
                'INSERT INTO notifications (audience, customer_id, type, title, body, link, dedupe_key, created_at)
                 VALUES (:audience, :customer, :type, :title, :body, :link, :dedupe, NOW())'
            )->execute([
                'audience' => $audience,
                'customer' => $customerId,
                'type'     => $type,
                'title'    => mb_substr($title, 0, 190),
                'body'     => mb_substr($body, 0, 2000),
                'link'     => $link,
                'dedupe'   => $dedupeKey,
            ]);
        } catch (PDOException $e) {
            if ($dedupeKey !== null && ($e->errorInfo[1] ?? null) === 1062) {
                return null;   // already notified about exactly this
            }
            throw $e;
        }
        return (int) $pdo->lastInsertId();
    }

    private function deliver(int $notificationId, ?string $address, string $title, string $body, ?string $link): void
    {
        if ($address === null || $address === '') {
            return;
        }
        if (!Mailer::isConfigured()) {
            $status = 'not_configured';
        } else {
            try {
                $text = $body . ($link !== null ? "\n\n" . Env::appUrl($link) : '') . "\n\n— Diwan\n";
                $status = Mailer::send($address, $title, $text) ? 'sent' : 'failed';
            } catch (\Throwable $e) {
                Logger::warning('Notification email failed', ['notification_id' => $notificationId, 'error' => $e->getMessage()]);
                $status = 'failed';
            }
        }
        Database::pdo()->prepare('UPDATE notifications SET email_status = :status WHERE id = :id')
            ->execute(['status' => $status, 'id' => $notificationId]);
    }

    private function customerEmail(int $customerId): ?string
    {
        $stmt = Database::pdo()->prepare('SELECT email FROM customers WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute(['id' => $customerId]);
        $email = $stmt->fetchColumn();
        return $email === false ? null : (string) $email;
    }

    private function markRead(string $scope, array $params, ?array $ids): int
    {
        $sql = "UPDATE notifications SET read_at = NOW() WHERE {$scope} AND read_at IS NULL";
        if ($ids !== null) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
            if ($ids === []) {
                return 0;
            }
            $sql .= ' AND id IN (' . implode(',', $ids) . ')';
        }
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }
}
