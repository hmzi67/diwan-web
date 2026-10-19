<?php
declare(strict_types=1);

use Diwan\Config\Env;
use Diwan\Order\OrderService;
use Diwan\Payment\EasyPaisaGateway;
use Diwan\Payment\JazzCashGateway;
use Diwan\Payment\SafepayGateway;
use Diwan\Payment\WebhookProcessor;

/**
 * The JazzCash integrity hash, written out independently of the gateway class
 * from the documented rule: HMAC-SHA256 (keyed by the salt) over the salt and
 * every non-empty pp* value, sorted by key, joined with '&', upper-cased.
 */
function jazzSign(array $fields): array
{
    unset($fields['pp_SecureHash']);
    ksort($fields, SORT_STRING);
    $salt  = Env::require('JAZZCASH_INTEGRITY_SALT');
    $parts = [$salt];
    foreach ($fields as $key => $value) {
        if (str_starts_with((string) $key, 'pp') && (string) $value !== '') {
            $parts[] = (string) $value;
        }
    }
    $fields['pp_SecureHash'] = strtoupper(hash_hmac('sha256', implode('&', $parts), $salt));
    return $fields;
}

/** A signed JazzCash callback for $order: paid unless $code says otherwise. */
function jazzCallback(array $order, string $code = '000', ?int $amount = null, ?string $rrn = null): array
{
    return jazzSign([
        'pp_TxnRefNo'             => $order['order_ref'],
        'pp_Amount'               => (string) ($amount ?? (int) $order['amount_paisa']),
        'pp_ResponseCode'         => $code,
        'pp_ResponseMessage'      => $code === '000' ? 'Thank you for using JazzCash' : 'Transaction declined',
        'pp_RetreivalReferenceNo' => $rrn ?? bin2hex(random_bytes(6)),
    ]);
}

function licenceCount(array $order): int
{
    return (int) TestKit::scalar('SELECT COUNT(*) FROM licenses WHERE order_id = :id', ['id' => $order['id']]);
}

function orderStatus(array $order): string
{
    return (string) TestKit::scalar('SELECT status FROM orders WHERE id = :id', ['id' => $order['id']]);
}

$webhook = static fn (): WebhookProcessor => new WebhookProcessor(new JazzCashGateway());

return [
    'a valid paid callback marks the order paid and issues one licence' => static function () use ($webhook): void {
        $order = TestKit::pendingOrder(TestKit::customer());
        TestKit::same('paid', $webhook()->process(jazzCallback($order), [], '', TestKit::ip()), 'status');
        TestKit::same('paid', orderStatus($order), 'order status');
        TestKit::same(1, licenceCount($order), 'licences issued');
    },

    'a forged hash is rejected and changes nothing' => static function () use ($webhook): void {
        $order = TestKit::pendingOrder(TestKit::customer());
        $forged = jazzCallback($order);
        $forged['pp_SecureHash'] = strtoupper(hash('sha256', 'forged'));
        TestKit::same('invalid_signature', $webhook()->process($forged, [], '', TestKit::ip()), 'status');
        TestKit::same('pending', orderStatus($order), 'order status');
        TestKit::same(0, licenceCount($order), 'licences issued');
    },

    'a missing hash is rejected' => static function () use ($webhook): void {
        $order = TestKit::pendingOrder(TestKit::customer());
        $payload = jazzCallback($order);
        unset($payload['pp_SecureHash']);
        TestKit::same('invalid_signature', $webhook()->process($payload, [], '', TestKit::ip()), 'status');
    },

    'a field tampered after signing is rejected' => static function () use ($webhook): void {
        $order = TestKit::pendingOrder(TestKit::customer());
        $payload = jazzCallback($order, '157');           // signed as declined…
        $payload['pp_ResponseCode'] = '000';              // …then flipped to paid
        TestKit::same('invalid_signature', $webhook()->process($payload, [], '', TestKit::ip()), 'status');
        TestKit::same(0, licenceCount($order), 'licences issued');
    },

    'a replayed callback is a duplicate and mints no second licence' => static function () use ($webhook): void {
        $order = TestKit::pendingOrder(TestKit::customer());
        $payload = jazzCallback($order);
        TestKit::same('paid', $webhook()->process($payload, [], '', TestKit::ip()), 'first delivery');
        TestKit::same('duplicate', $webhook()->process($payload, [], '', TestKit::ip()), 'replay');
        TestKit::same(1, licenceCount($order), 'licences issued');
    },

    'a second paid event for a paid order is already_paid' => static function () use ($webhook): void {
        $order = TestKit::pendingOrder(TestKit::customer());
        $webhook()->process(jazzCallback($order), [], '', TestKit::ip());
        TestKit::same('already_paid', $webhook()->process(jazzCallback($order), [], '', TestKit::ip()), 'status');
        TestKit::same(1, licenceCount($order), 'licences issued');
    },

    'a declined callback marks the order failed with the gateway reason' => static function () use ($webhook): void {
        $order = TestKit::pendingOrder(TestKit::customer());
        TestKit::same('not_paid', $webhook()->process(jazzCallback($order, '157'), [], '', TestKit::ip()), 'status');
        TestKit::same('failed', orderStatus($order), 'order status');
        TestKit::same('Transaction declined', TestKit::scalar(
            'SELECT failure_reason FROM orders WHERE id = :id', ['id' => $order['id']]
        ), 'failure reason');
        TestKit::same(0, licenceCount($order), 'licences issued');
    },

    'a validly signed callback for the wrong amount issues nothing' => static function () use ($webhook): void {
        $order = TestKit::pendingOrder(TestKit::customer());
        $underpaid = jazzCallback($order, '000', (int) $order['amount_paisa'] - 100);
        TestKit::same('amount_mismatch', $webhook()->process($underpaid, [], '', TestKit::ip()), 'status');
        TestKit::same('failed', orderStatus($order), 'order status');
        TestKit::same(0, licenceCount($order), 'licences issued');
    },

    'an unknown order reference is ignored' => static function () use ($webhook): void {
        $payload = jazzSign([
            'pp_TxnRefNo' => 'DW00000000000000NOPE', 'pp_Amount' => '100', 'pp_ResponseCode' => '000',
        ]);
        TestKit::same('unknown_order', $webhook()->process($payload, [], '', TestKit::ip()), 'status');
    },

    'stored webhook payloads have the signature redacted' => static function () use ($webhook): void {
        $order = TestKit::pendingOrder(TestKit::customer());
        $webhook()->process(jazzCallback($order), [], '', TestKit::ip());
        $stored = (string) TestKit::scalar('SELECT payload FROM webhook_events WHERE order_ref = :ref', ['ref' => $order['order_ref']]);
        TestKit::check(str_contains($stored, '"pp_SecureHash":"[redacted]"'), 'pp_SecureHash is redacted');
    },

    'Safepay accepts its signature header in any letter case' => static function (): void {
        $order = TestKit::pendingOrder(TestKit::customer(), 'diwan-pos-starter', 'safepay');
        $body = json_encode(['data' => ['order_id' => $order['order_ref'], 'state' => 'PAID', 'tracker' => 'trk_' . bin2hex(random_bytes(4))]]);
        $sig  = hash_hmac('sha256', $body, Env::require('SAFEPAY_WEBHOOK_SECRET'));
        $result = (new WebhookProcessor(new SafepayGateway()))
            ->process(json_decode($body, true), ['X-Sfpy-Signature' => $sig], $body, TestKit::ip());
        TestKit::same('paid', $result, 'mixed-case header');
        TestKit::same(1, licenceCount($order), 'licences issued');
    },

    'Safepay rejects a body signed with the wrong secret' => static function (): void {
        $body = json_encode(['data' => ['order_id' => 'DW1', 'state' => 'PAID']]);
        TestKit::check(!(new SafepayGateway())->verifyCallback(
            json_decode($body, true),
            ['x-sfpy-signature' => hash_hmac('sha256', $body, 'wrong-secret')],
            $body
        ), 'wrong-secret signature is refused');
    },

    'JazzCash checkout fields carry a hash the gateway accepts back' => static function (): void {
        $gateway = new JazzCashGateway();
        $fields  = $gateway->buildCheckout('DW20260101000000ABCDEF', 800000, ['email' => 'a@tests.invalid', 'phone' => '03000000000']);
        TestKit::same('800000', $fields['fields']['pp_Amount'], 'amount is sent in paisa');
        TestKit::check($gateway->verifyCallback($fields['fields'], [], ''), 'own hash verifies');
        $fields['fields']['pp_Amount'] = '1';
        TestKit::check(!$gateway->verifyCallback($fields['fields'], [], ''), 'changed amount no longer verifies');
    },

    'EasyPaisa checkout fields carry a hash the gateway accepts back' => static function (): void {
        $gateway = new EasyPaisaGateway();
        $fields  = $gateway->buildCheckout('DW20260101000000ABCDEF', 1200000, ['email' => 'a@tests.invalid']);
        TestKit::same('12000.0', $fields['fields']['amount'], 'amount is sent in rupees');
        TestKit::same(1200000, $gateway->amountPaisaFrom($fields['fields']), 'amount reads back in paisa');
        TestKit::check($gateway->verifyCallback($fields['fields'], [], ''), 'own hash verifies');
        $fields['fields']['amount'] = '1.0';
        TestKit::check(!$gateway->verifyCallback($fields['fields'], [], ''), 'changed amount no longer verifies');
    },

    'checkout prices come from the products table, not the caller' => static function (): void {
        $orders = new OrderService();
        $order  = $orders->createPending('diwan-pos-standard', TestKit::customer(), TestKit::email(), '03000000000', 'jazzcash');
        $price  = (int) TestKit::scalar('SELECT price_paisa FROM products WHERE sku = "diwan-pos-standard"');
        TestKit::same($price, $order['amount_paisa'], 'order amount');
        TestKit::throws(RuntimeException::class, static fn () => $orders->product('diwan-pos-trial'),
            'inactive trial SKU cannot be bought', 'inactive product');
        TestKit::throws(RuntimeException::class, static fn () => $orders->product('no-such-sku'),
            'unknown SKU cannot be bought', 'Unknown');
    },
];
