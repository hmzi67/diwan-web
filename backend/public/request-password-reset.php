<?php
/**
 * POST /api/request-password-reset.php  { email }
 *
 * Emails a single-use link to set a new password. Also doubles as the "set
 * your first password" path for accounts that predate password auth —
 * PasswordResetService picks the wording; the mechanism is the same.
 *
 * Always responds identically, whether or not the email has an account, for
 * the same reason resend-verification.php does: this must not become an
 * account-enumeration oracle.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Auth\PasswordResetService;
use Diwan\Support\Http;

Http::requireMethod('POST');

$input = Http::input();
$email = trim((string) ($input['email'] ?? ''));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    Http::fail('Please enter a valid email address.', 422);
}

$platform = (string) ($input['platform'] ?? '');
(new PasswordResetService())->request($email, Http::clientIp(), $platform);

Http::json([
    'message' => "If that email has a Diwan account, we've sent a link to set a new password.",
]);
