<?php
/**
 * POST /api/resend-verification.php  { email }
 *
 * Re-sends the signup verification link. Reached from the login page's
 * "your email isn't verified yet" state, so a lost or expired link never
 * dead-ends the signup.
 *
 * Always responds identically: unknown address, already-verified address and
 * rate-limited request all return the same message, for the same reason
 * request-password-reset does — this must not become an account-enumeration
 * oracle. Rate limits and the no-op cases live in EmailVerificationService.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Auth\EmailVerificationService;
use Diwan\Support\Http;

Http::requireMethod('POST');

$input = Http::input();
$email = trim((string) ($input['email'] ?? ''));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    Http::fail('Please enter a valid email address.', 422);
}

(new EmailVerificationService())->send($email, Http::clientIp());

Http::json([
    'message' => "If that email has a Diwan account that isn't verified yet, we've sent a new link. Check your inbox.",
]);
