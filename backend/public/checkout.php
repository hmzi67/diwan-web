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

use Diwan\Auth\Session;
use Diwan\Database\Database;
use Diwan\Order\OrderService;
use Diwan\Payment\GatewayFactory;
use Diwan\Support\Http;
use Diwan\Support\Logger;

Http::requireMethod('POST');

$customerId = Session::customerId();
if ($customerId === null) {
    Http::fail('Please log in first.', 401);
}

$customer = Database::pdo()->prepare('SELECT email FROM customers WHERE id = :id LIMIT 1');
$customer->execute(['id' => $customerId]);
$customer = $customer->fetch();

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
