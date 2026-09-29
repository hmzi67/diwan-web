<?php
/**
 * POST /api/apple-notifications.php — App Store Server Notifications V2.
 *
 * Set this URL (Production and Sandbox) in App Store Connect → App
 * Information → App Store Server Notifications, version 2.
 *
 * Same three rules as payment-webhook.php: verify before trusting anything
 * (the whole body is an Apple-signed JWS), never act on the same event twice
 * (webhook_events, keyed by notificationUUID), and answer non-2xx only for
 * failures worth retrying — Apple redelivers those for up to three days.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Apple\AppStoreJws;
use Diwan\Apple\AppStoreService;
use Diwan\Database\Database;
use Diwan\Support\Http;
use Diwan\Support\Logger;

Http::requireMethod('POST');

$rawBody = Http::rawBody();
$body    = json_decode($rawBody, true);
$signed  = is_array($body) ? ($body['signedPayload'] ?? null) : null;
if (!is_string($signed) || $signed === '') {
    Http::fail('Invalid request', 400);
}

try {
    $notification = AppStoreJws::verify($signed);
} catch (RuntimeException $e) {
    Logger::warning('App Store notification signature rejected', ['reason' => $e->getMessage(), 'ip' => Http::clientIp()]);
    Http::fail('Invalid request', 400);
}

$eventId = (string) ($notification['notificationUUID'] ?? '');
if ($eventId === '') {
    Http::fail('Invalid request', 400);
}

$pdo  = Database::pdo();
$seen = $pdo->prepare('SELECT 1 FROM webhook_events WHERE gateway = "apple" AND event_id = :id LIMIT 1');
$seen->execute(['id' => $eventId]);
if ($seen->fetchColumn() !== false) {
    Http::json(['status' => 'duplicate']);
}

try {
    $outcome = (new AppStoreService())->applyNotification($notification);
} catch (RuntimeException $e) {
    // Verified, but not ours or malformed: retrying can't fix it.
    Logger::warning('App Store notification not applied', ['reason' => $e->getMessage(), 'uuid' => $eventId]);
    $outcome = 'rejected';
}

// Recorded only after it was handled, so a crash above is retried by Apple
// instead of being swallowed as a "duplicate" next time.
$pdo->prepare(
    'INSERT IGNORE INTO webhook_events (gateway, event_id, order_ref, payload, received_at)
     VALUES ("apple", :id, NULL, :payload, NOW())'
)->execute(['id' => $eventId, 'payload' => mb_substr(json_encode($notification), 0, 65535)]);

Logger::info('App Store notification processed', [
    'type'    => $notification['notificationType'] ?? null,
    'outcome' => $outcome,
]);

Http::json(['status' => $outcome]);
