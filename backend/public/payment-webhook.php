<?php
/**
 * POST /api/payment-webhook.php  — server-to-server callback from the gateway.
 *
 * This is the ONLY place a licence is created. Three rules, in order:
 *   1. Verify the signature before trusting a single field.
 *   2. Record the raw event first, so a failure mid-way is replayable.
 *   3. Be idempotent — gateways retry, and a retry must not mint a second licence.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Database\Database;
use Diwan\License\LicenseService;
use Diwan\Order\OrderService;
use Diwan\Payment\GatewayFactory;
use Diwan\Support\Http;
use Diwan\Support\Logger;
use Diwan\Support\Mailer;

Http::requireMethod('POST');

$gateway = GatewayFactory::make();
$rawBody = Http::rawBody();
$payload = Http::input();
$headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];

// --- 1. Signature ---------------------------------------------------------
if (!$gateway->verifyCallback($payload, $headers, $rawBody)) {
    Logger::warning('Webhook signature rejected', [
        'gateway' => $gateway->name(),
        'ip'      => Http::clientIp(),
    ]);
    // 400, not 401: give an attacker nothing to distinguish.
    Http::fail('Invalid request', 400);
}

$orderRef = $gateway->orderRefFrom($payload);
if ($orderRef === null) {
    Http::fail('Missing order reference', 400);
}

$orders   = new OrderService();
$licenses = new LicenseService();

$order = $orders->findByRef($orderRef);
if (!$order) {
    Logger::warning('Webhook for unknown order', ['order_ref' => $orderRef]);
    // 200 so the gateway stops retrying something we can never resolve.
    Http::json(['status' => 'ignored']);
}

// --- 2 & 3. Record, then act, atomically ----------------------------------
$result = Database::transaction(static function ($pdo) use (
    $gateway, $payload, $rawBody, $order, $orderRef, $orders, $licenses
) {
    // Unique index on (gateway, event_id) makes replay a no-op.
    $eventId = (string) ($gateway->transactionIdFrom($payload) ?? $orderRef);

    $insert = $pdo->prepare(
        'INSERT IGNORE INTO webhook_events (gateway, event_id, order_ref, payload, received_at)
         VALUES (:gateway, :event, :ref, :payload, NOW())'
    );
    $insert->execute([
        'gateway' => $gateway->name(),
        'event'   => $eventId,
        'ref'     => $orderRef,
        'payload' => mb_substr($rawBody !== '' ? $rawBody : json_encode($payload), 0, 65535),
    ]);

    if ($insert->rowCount() === 0) {
        return ['status' => 'duplicate', 'license_key' => null];
    }

    if (!$gateway->isPaid($payload)) {
        return ['status' => 'not_paid', 'license_key' => null];
    }

    if (!$orders->markPaid($pdo, $orderRef, $gateway->transactionIdFrom($payload))) {
        return ['status' => 'already_paid', 'license_key' => null];
    }

    $license = $licenses->issueForOrder($pdo, (int) $order['id'], (string) $order['customer_email']);

    return ['status' => 'paid', 'license_key' => $license['license_key']];
});

if ($result['status'] === 'not_paid') {
    $orders->markFailed($orderRef, (string) ($payload['pp_ResponseMessage'] ?? 'Payment not completed'));
}

// --- Deliver the licence (outside the transaction) -------------------------
if ($result['status'] === 'paid' && $result['license_key'] !== null) {
    $sent = Mailer::send(
        (string) $order['customer_email'],
        'Your Diwan POS licence key',
        "Thank you for your purchase.\n\n"
        . "Licence key: {$result['license_key']}\n\n"
        . "Download your installer: " . rtrim((string) getenv('APP_URL'), '/') . "/#download\n\n"
        . "Keep this key safe — it is your proof of purchase.\n",
        'From: ' . (Diwan\Config\Env::get('MAIL_FROM', 'no-reply@localhost'))
    );
    if (!$sent) {
        // The licence exists; delivery is recoverable by support. Never 500 here,
        // or the gateway will retry and we will re-process a completed payment.
        Logger::error('Licence email failed to send', ['order_ref' => $orderRef]);
    }
}

Logger::info('Webhook processed', ['order_ref' => $orderRef, 'status' => $result['status']]);

Http::json(['status' => $result['status']]);
