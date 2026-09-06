<?php
/**
 * POST /api/resend-license-email.php  { license_id }
 *
 * Re-sends the original purchase email for one of the logged-in customer's
 * own licenses. Only works for licenses issued after migration 002
 * (license_key_encrypted IS NOT NULL) — older licenses truly have no
 * plaintext anywhere, by original design, and fall back to support.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Auth\Session;
use Diwan\Config\Env;
use Diwan\Database\Database;
use Diwan\License\LicenseService;
use Diwan\Support\Http;
use Diwan\Support\Logger;
use Diwan\Support\Mailer;

Http::requireMethod('POST');

$customerId = Session::customerId();
if ($customerId === null) {
    Http::fail('Not logged in.', 401);
}

$input = Http::input();
$licenseId = (int) ($input['license_id'] ?? 0);
if ($licenseId <= 0) {
    Http::fail('Missing license.', 422);
}

$pdo = Database::pdo();

// Ownership check: the license must belong to THIS customer, via its order.
$stmt = $pdo->prepare(
    'SELECT l.id, l.license_key_encrypted, l.customer_email
       FROM licenses l
       JOIN orders o ON o.id = l.order_id
      WHERE l.id = :license AND o.customer_id = :customer
      LIMIT 1'
);
$stmt->execute(['license' => $licenseId, 'customer' => $customerId]);
$license = $stmt->fetch();

if (!$license) {
    Http::fail('License not found.', 404);
}

$key = (new LicenseService())->decrypt($license['license_key_encrypted']);

if ($key === null) {
    Http::fail(
        'This license was issued before we could offer instant resend. '
        . 'Please contact support and we\'ll help you recover it.',
        409
    );
}

$sent = Mailer::send(
    $license['customer_email'],
    'Your Diwan POS licence key',
    "Here is your licence key again, as requested:\n\n"
    . "Licence key: {$key}\n\n"
    . "Download your installer: " . rtrim((string) Env::require('APP_URL'), '/') . "/#download\n\n"
    . "Keep this key safe — it is your proof of purchase.\n",
    'From: ' . Env::get('MAIL_FROM', 'no-reply@localhost')
);

if (!$sent) {
    Logger::error('License resend email failed to send', ['license_id' => $licenseId]);
    Http::fail('Could not send the email right now. Please try again shortly.', 502);
}

Logger::info('License key resent', ['license_id' => $licenseId, 'customer_id' => $customerId]);
Http::json(['message' => 'Sent! Check your email for your licence key.']);
