<?php
/**
 * POST /api/checkout.php  { phone, product_sku }
 *
 * Creates a pending order and returns the fields the browser must POST to the
 * payment gateway. The price is read from the database, never from the client.
 *
 * Requires a logged-in session — see Diwan\Auth\Session. The order's email is
 * always the session's own customer record, never a value typed on this
 * request, so an order can never end up attached to someone else's email by
 * mistake or by a customer typo.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Auth\Customers;
use Diwan\Auth\Session;
use Diwan\Order\OrderService;
use Diwan\Payment\GatewayFactory;
use Diwan\Support\Http;
use Diwan\Support\Logger;

Http::requireMethod('POST');

// Online payment is switched off: plans are requested and approved by an
// administrator instead (license-requests.php). Gateway webhooks stay live
// so an order paid before the switch is still fulfilled.
if (!Diwan\Config\Env::bool('PAYMENTS_ENABLED', false)) {
    Http::fail('Online checkout is not available. Choose a plan on the pricing page to request access.', 410);
}

$customerId = Session::customerId();
if ($customerId === null) {
    Http::fail('Please log in first.', 401);
}

$customer = Customers::findById($customerId);

if (!$customer) {
    // Session cookie outlived the customer row (e.g. deleted account).
    Session::destroy();
    Http::fail('Session is no longer valid. Please log in again.', 401);
}

$email = (string) $customer['email'];

$input = Http::input();
$phone = preg_replace('/\D+/', '', (string) ($input['phone'] ?? ''));
$sku   = trim((string) ($input['product_sku'] ?? ''));

if (strlen((string) $phone) < 10 || strlen((string) $phone) > 15) {
    Http::fail('Please enter a valid phone number.', 422);
}
if ($sku === '') {
    Http::fail('No product selected.', 422);
}

$gateway = GatewayFactory::make();
$orders  = new OrderService();

try {
    $order = $orders->createPending($sku, $customerId, $email, (string) $phone, $gateway->name());
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
