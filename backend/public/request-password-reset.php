<?php
/**
 * POST /api/request-password-reset.php  { email }
 *
 * Emails a single-use link to set a new password. Doubles as the "set your
 * first password" path for accounts created during the magic-link era —
 * PasswordResetService picks the wording; the mechanism is the same.
 *
 * Always responds identically, whether or not the email has an account, for
 * the same reason send-login-link.php does.
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

(new PasswordResetService())->request($email, Http::clientIp());

Http::json([
    'message' => "If that email has a Diwan account, we've sent a link to set a new password.",
]);
