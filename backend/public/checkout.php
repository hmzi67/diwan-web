<?php
/**
 * POST /api/checkout.php
 * Creates a pending order and returns the fields the browser must POST to the
 * payment gateway. The price is read from the database, never from the client.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Order\OrderService;
use Diwan\Payment\GatewayFactory;
use Diwan\Support\Http;
use Diwan\Support\Logger;

Http::requireMethod('POST');

$input = Http::input();

$email = trim((string) ($input['email'] ?? ''));
$phone = preg_replace('/\D+/', '', (string) ($input['phone'] ?? ''));
$sku   = trim((string) ($input['product_sku'] ?? ''));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    Http::fail('Please enter a valid email address.', 422);
}
if (strlen((string) $phone) < 10 || strlen((string) $phone) > 15) {
    Http::fail('Please enter a valid phone number.', 422);
}
if ($sku === '') {
    Http::fail('No product selected.', 422);
}

$gateway = GatewayFactory::make();
$orders  = new OrderService();

try {
    $order = $orders->createPending($sku, $email, (string) $phone, $gateway->name());
} catch (RuntimeException $e) {
    Logger::warning('Checkout rejected', ['sku' => $sku, 'reason' => $e->getMessage()]);
    Http::fail('That product is not available.', 404);
}

$checkout = $gateway->buildCheckout($order['order_ref'], $order['amount_paisa'], [
    'email' => $email,
    'phone' => $phone,
]);

Http::json([
    'order_ref'    => $order['order_ref'],
    'redirect_url' => $checkout['redirect_url'],
    'fields'       => $checkout['fields'],
]);
