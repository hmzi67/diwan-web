<?php
/**
 * POST /api/payment-webhook.php  — server-to-server callback from the gateway.
 *
 * All of the work — signature, idempotency, amount check, licence issuance
 * and delivery — is in Diwan\Payment\WebhookProcessor. This file only maps
 * its outcome to an HTTP answer.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Payment\GatewayFactory;
use Diwan\Payment\WebhookProcessor;
use Diwan\Support\Http;

Http::requireMethod('POST');

$status = (new WebhookProcessor(GatewayFactory::make()))->process(
    Http::input(),
    function_exists('getallheaders') ? (getallheaders() ?: []) : [],
    Http::rawBody(),
    Http::clientIp()
);

match ($status) {
    // 400, not 401: give an attacker nothing to distinguish.
    'invalid_signature' => Http::fail('Invalid request', 400),
    'missing_order_ref' => Http::fail('Missing order reference', 400),
    // 200 so the gateway stops retrying something we can never resolve.
    'unknown_order'     => Http::json(['status' => 'ignored']),
    default             => Http::json(['status' => $status]),
};
