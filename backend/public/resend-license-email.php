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
use Diwan\License\LicenseService;
use Diwan\Support\Http;
use Diwan\Support\Logger;

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

$licenses = new LicenseService();

// Ownership check: the license must belong to THIS customer, via its order.
$license = $licenses->findOwned($licenseId, $customerId);
if ($license === null) {
    Http::fail('License not found.', 404);
}

$key = $licenses->decrypt($license['license_key_encrypted']);

if ($key === null) {
    Http::fail(
        'This license was issued before we could offer instant resend. '
        . 'Please contact support and we\'ll help you recover it.',
        409
    );
}

if (!$licenses->sendKeyEmail((string) $license['customer_email'], $key, true)) {
    Logger::error('License resend email failed to send', ['license_id' => $licenseId]);
    Http::fail('Could not send the email right now. Please try again shortly.', 502);
}

Logger::info('License key resent', ['license_id' => $licenseId, 'customer_id' => $customerId]);
Http::json(['message' => 'Sent! Check your email for your licence key.']);
