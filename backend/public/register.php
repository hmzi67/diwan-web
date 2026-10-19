<?php
/**
 * POST /api/register.php  { email, password, plan? }
 *
 * Creates a customer account with a password and emails a verification link.
 * No session is issued here — sign-in stays closed until verify-email.php
 * stamps the account, because the licence key is delivered by email and a
 * purchase against an unreadable address is a support ticket.
 *
 * Enumeration: the response is byte-identical whether or not the address
 * already has an account. A new account gets the verification email; an
 * existing one gets an "you already have an account" notice instead (with a
 * fresh verification link attached when that account is still unverified —
 * the person holding the mailbox is the one asking either way). Both cases
 * are rate-limited per IP so this cannot be used to enumerate at scale or to
 * flood the customers table.
 *
 * TEMPORARY no-payment onboarding: the account and its free trial are
 * committed together, so the licence is waiting in the dashboard as soon as
 * the address is verified. Registering again never changes the password or
 * grants another trial.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Auth\LoginAttempts;
use Diwan\Auth\PasswordService;
use Diwan\Auth\RegistrationService;
use Diwan\Support\Http;
use Diwan\Support\Logger;

Http::requireMethod('POST');

$input    = Http::input();
$email    = strtolower(trim((string) ($input['email'] ?? '')));
$password = (string) ($input['password'] ?? '');
$plan     = trim((string) ($input['plan'] ?? '')) ?: null;
$ip       = Http::clientIp();

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    Http::fail('Please enter a valid email address.', 422);
}

$problem = PasswordService::validate($password);
if ($problem !== null) {
    Http::fail($problem, 422);
}

if (LoginAttempts::isRegistrationRateLimited($ip)) {
    Logger::warning('Registration rate limited by IP', ['ip' => $ip]);
    Http::fail('Too many attempts. Please wait an hour and try again.', 429);
}

(new RegistrationService())->register($email, PasswordService::hash($password), $ip, $plan);

LoginAttempts::record($ip, $email, 'register');
Http::json([
    'status'  => 'sent',
    'message' => 'Check your email for a verification link to finish creating your account.',
]);
