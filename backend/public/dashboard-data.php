<?php
/**
 * GET /api/dashboard-data.php
 *
 * Everything the customer dashboard renders, in one call. Requires a valid
 * session cookie (Diwan\Auth\Session) — no cookie, no session, straight 401.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Auth\Session;
use Diwan\Database\Database;
use Diwan\License\LicenseService;
use Diwan\Support\Http;

Http::requireMethod('GET');

$customerId = Session::customerId();
if ($customerId === null) {
    Http::fail('Not logged in.', 401);
}

$pdo = Database::pdo();

$customer = $pdo->prepare('SELECT id, email, name FROM customers WHERE id = :id LIMIT 1');
$customer->execute(['id' => $customerId]);
$customer = $customer->fetch();

if (!$customer) {
    // Session cookie outlived the customer row (e.g. deleted account).
    Session::destroy();
    Http::fail('Session is no longer valid. Please log in again.', 401);
}

$orders = $pdo->prepare(
    'SELECT o.id AS order_id, o.order_ref, o.amount_paisa, o.currency, o.status AS order_status,
            o.created_at, o.paid_at, o.gateway,
            p.name AS product_name,
            l.id AS license_id, l.license_key_prefix, l.license_key_encrypted,
            CASE WHEN l.status = "active" AND l.expires_at <= NOW() THEN "expired"
                 ELSE l.status END AS license_status,
            UNIX_TIMESTAMP(l.expires_at) AS expires_at,
            l.activation_status, l.activated_machine_hint, l.activated_at,
            l.max_downloads, l.downloads_used,
            (l.license_key_encrypted IS NOT NULL) AS can_resend
       FROM orders o
       JOIN products p ON p.id = o.product_id
  LEFT JOIN licenses l ON l.order_id = o.id
      WHERE o.customer_id = :id
      ORDER BY o.created_at DESC'
);
$orders->execute(['id' => $customerId]);

$rows = $orders->fetchAll();
$licenses = new LicenseService();
foreach ($rows as &$row) {
    // The query above scopes keys to the authenticated owner. Http::json sets
    // Cache-Control: no-store; never put keys in URLs, logs or browser storage.
    $row['license_key'] = $licenses->decrypt($row['license_key_encrypted']);
    unset($row['license_key_encrypted']);
    $row['expires_at'] = $row['expires_at'] === null ? null : (int) $row['expires_at'];
}
unset($row);

Http::json([
    'email'  => $customer['email'],
    'name'   => $customer['name'],
    'orders' => $rows,
]);
