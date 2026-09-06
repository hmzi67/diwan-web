<?php
/**
 * POST /api/send-login-link.php  { email, plan? }
 *
 * Starting point of passwordless login AND signup — an unknown email gets a
 * customers row created on the spot, see LoginService::requestLink(). Always
 * responds with the same generic message either way, so this can't be used
 * to enumerate which emails already have an account.
 *
 * `plan` (optional, a product SKU) is only ever a UI convenience: when the
 * customer clicked "Get Started" on a specific pricing card before being
 * sent here, it rides along in the emailed link so verify-login.php can
 * send them back to that plan's checkout afterward.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Auth\LoginService;
use Diwan\Support\Http;

Http::requireMethod('POST');

$input = Http::input();
$email = trim((string) ($input['email'] ?? ''));
$plan  = trim((string) ($input['plan'] ?? '')) ?: null;

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    Http::fail('Please enter a valid email address.', 422);
}

(new LoginService())->requestLink($email, Http::clientIp(), $plan);

Http::json([
    'message' => "If that email has a Diwan account, we've sent a login link. Check your inbox.",
]);
