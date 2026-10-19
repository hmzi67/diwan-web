<?php
declare(strict_types=1);

namespace Diwan\Payment;

use Diwan\Support\Logger;
use PDO;

/**
 * The webhook_events ledger shared by payment-webhook.php and
 * apple-notifications.php. The unique index on (gateway, event_id) is what
 * makes a redelivered event a no-op.
 *
 * Payloads are stored through Logger::redact(), so signatures, merchant
 * secrets and customer contact fields never sit in the table in clear.
 */
final class WebhookEvents
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** True if inserted, false if this event was already recorded. */
    public function record(string $gateway, string $eventId, ?string $orderRef, array $payload): bool
    {
        $insert = $this->pdo->prepare(
            'INSERT IGNORE INTO webhook_events (gateway, event_id, order_ref, payload, received_at)
             VALUES (:gateway, :event, :ref, :payload, NOW())'
        );
        $insert->execute([
            'gateway' => $gateway,
            'event'   => $eventId,
            'ref'     => $orderRef,
            'payload' => mb_substr((string) json_encode(Logger::redact($payload), JSON_UNESCAPED_SLASHES), 0, 65535),
        ]);
        return $insert->rowCount() > 0;
    }

    public function seen(string $gateway, string $eventId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM webhook_events WHERE gateway = :gateway AND event_id = :event LIMIT 1'
        );
        $stmt->execute(['gateway' => $gateway, 'event' => $eventId]);
        return $stmt->fetchColumn() !== false;
    }
}
