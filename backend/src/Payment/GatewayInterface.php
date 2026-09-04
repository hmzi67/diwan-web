<?php
declare(strict_types=1);

namespace Diwan\Payment;

/**
 * Every gateway does the same two jobs, so the rest of the app never needs to
 * know which one is configured: build a redirect, then verify a callback.
 */
interface GatewayInterface
{
    /**
     * Fields the browser must POST to the gateway to start a payment.
     *
     * @return array{redirect_url:string, fields:array<string,string>}
     */
    public function buildCheckout(string $orderRef, int $amountPaisa, array $customer): array;

    /**
     * Verify an inbound webhook/callback. MUST return false unless the payload
     * is cryptographically proven to come from the gateway.
     */
    public function verifyCallback(array $payload, array $headers, string $rawBody): bool;

    /** Extract our own order reference from a verified payload. */
    public function orderRefFrom(array $payload): ?string;

    /** Whether a verified payload represents a completed, paid transaction. */
    public function isPaid(array $payload): bool;

    /** Gateway's own transaction id, stored for reconciliation. */
    public function transactionIdFrom(array $payload): ?string;

    public function name(): string;
}
