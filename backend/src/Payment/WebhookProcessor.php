<?php
declare(strict_types=1);

namespace Diwan\Payment;

use Diwan\Database\Database;
use Diwan\License\LicenseService;
use Diwan\Order\OrderService;
use Diwan\Support\Logger;
use PDO;

/**
 * Handles one gateway callback. This is the ONLY place a paid licence is
 * created. Rules, in order:
 *   1. Verify the signature before trusting a single field.
 *   2. Record the event first, so a failure mid-way is visible and replayable.
 *   3. Be idempotent — gateways retry, and a retry must not mint a second licence.
 *   4. Never mark an order paid for a different amount than it was created for.
 *
 * process() returns a status for the HTTP response:
 *   invalid_signature | missing_order_ref | unknown_order | duplicate |
 *   not_paid | amount_mismatch | already_paid | paid
 */
final class WebhookProcessor
{
    public function __construct(
        private readonly GatewayInterface $gateway,
        private readonly OrderService $orders = new OrderService(),
        private readonly LicenseService $licenses = new LicenseService(),
    ) {
    }

    /** @param array<string,string> $headers raw request headers, any case */
    public function process(array $payload, array $headers, string $rawBody, string $ip): string
    {
        // HTTP header names are case-insensitive, and getallheaders() returns
        // them as the client sent them; gateways look them up lower-cased.
        $headers = array_change_key_case($headers, CASE_LOWER);

        if (!$this->gateway->verifyCallback($payload, $headers, $rawBody)) {
            Logger::warning('Webhook signature rejected', ['gateway' => $this->gateway->name(), 'ip' => $ip]);
            return 'invalid_signature';
        }

        $orderRef = $this->gateway->orderRefFrom($payload);
        if ($orderRef === null) {
            return 'missing_order_ref';
        }

        $order = $this->orders->findByRef($orderRef);
        if (!$order) {
            Logger::warning('Webhook for unknown order', ['order_ref' => $orderRef]);
            return 'unknown_order';
        }

        $result = Database::transaction(fn (PDO $pdo): array => $this->apply($pdo, $payload, $order, $orderRef));

        if ($result['status'] === 'not_paid') {
            $this->orders->markFailed($orderRef, $this->gateway->failureReasonFrom($payload));
        }
        if ($result['status'] === 'amount_mismatch') {
            $this->orders->markFailed($orderRef, 'Paid amount did not match the order amount');
        }

        // Outside the transaction: the licence exists either way, and a mail
        // failure must never roll back a completed payment. Never 500 here, or
        // the gateway retries and we re-process a completed payment.
        if ($result['status'] === 'paid' && $result['license_key'] !== null
            && !$this->licenses->sendKeyEmail((string) $order['customer_email'], $result['license_key'])) {
            Logger::error('Licence email failed to send', ['order_ref' => $orderRef]);
        }

        Logger::info('Webhook processed', ['order_ref' => $orderRef, 'status' => $result['status']]);
        return $result['status'];
    }

    /** @return array{status:string, license_key:?string} */
    private function apply(PDO $pdo, array $payload, array $order, string $orderRef): array
    {
        $transactionId = $this->gateway->transactionIdFrom($payload);
        $recorded = (new WebhookEvents($pdo))->record(
            $this->gateway->name(),
            $transactionId ?? $orderRef,
            $orderRef,
            $payload
        );
        if (!$recorded) {
            return ['status' => 'duplicate', 'license_key' => null];
        }

        if (!$this->gateway->isPaid($payload)) {
            return ['status' => 'not_paid', 'license_key' => null];
        }

        $paid = $this->gateway->amountPaisaFrom($payload);
        if ($paid !== null && $paid !== (int) $order['amount_paisa']) {
            Logger::error('Webhook amount does not match order', [
                'order_ref' => $orderRef,
                'expected'  => (int) $order['amount_paisa'],
                'paid'      => $paid,
            ]);
            return ['status' => 'amount_mismatch', 'license_key' => null];
        }

        if (!$this->orders->markPaid($pdo, $orderRef, $transactionId)) {
            return ['status' => 'already_paid', 'license_key' => null];
        }

        $license = $this->licenses->issueForOrder($pdo, (int) $order['id'], (string) $order['customer_email']);
        return ['status' => 'paid', 'license_key' => $license['license_key']];
    }
}
