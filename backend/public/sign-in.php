<?php
/**
 * POST /api/sign-in.php  { email, password }
 *
 * Verifies a password and starts the same session cookie every other
 * authenticated endpoint already reads (Diwan\Auth\Session) — checkout.php,
 * dashboard-data.php, logout.php and resend-license-email.php need no changes.
 *
 * Every failure returns the SAME message and status. "No such account" and
 * "wrong password" being distinguishable — by wording, by status code, or by
 * response time — is what turns a sign-in form into a list of your customers.
 * PasswordService::burnTime() covers the timing side.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Auth\LoginAttempts;
use Diwan\Auth\PasswordService;
use Diwan\Auth\Session;
use Diwan\Database\Database;
use Diwan\Support\Http;
use Diwan\Support\Logger;

Http::requireMethod('POST');

$input    = Http::input();
$email    = strtolower(trim((string) ($input['email'] ?? '')));
$password = (string) ($input['password'] ?? '');
$ip       = Http::clientIp();

if ($email === '' || $password === '') {
    Http::fail('Please enter your email and password.', 422);
}

if (LoginAttempts::isRateLimited($ip, $email)) {
    LoginAttempts::record($ip, $email, 'rate_limited');
    Logger::warning('Sign-in rate limited', ['ip' => $ip]);
    Http::fail(
        'Too many sign-in attempts. Please wait '
        . LoginAttempts::windowMinutes() . ' minutes and try again.',
        429
    );
}

$customer = Database::pdo()->prepare(
    'SELECT id, password_hash, session_epoch FROM customers WHERE email = :email LIMIT 1'
);
$customer->execute(['email' => $email]);
$customer = $customer->fetch();

$generic = 'Email or password is incorrect.';

if (!$customer) {
    PasswordService::burnTime();
    LoginAttempts::record($ip, $email, 'unknown_email');
    Http::fail($generic, 401);
}

// Account predates password auth (migration 004 leaves password_hash NULL).
// This is the one case that gets its own answer, because the alternative is
// telling a real customer their correct details are wrong and leaving them
// stuck forever. It reveals only what the person already told us — that this
// address has an account — to someone who cannot sign in with it anyway.
if ($customer['password_hash'] === null) {
    PasswordService::burnTime();
    LoginAttempts::record($ip, $email, 'no_password_set');
    Http::json([
        'status'  => 'password_not_set',
        'message' => 'Your account was created before we offered passwords. '
                   . "Choose \"Forgot password?\" and we'll email you a link to set one.",
    ], 409);
}

if (!PasswordService::verify($password, (string) $customer['password_hash'])) {
    LoginAttempts::record($ip, $email, 'bad_password');
    Http::fail($generic, 401);
}

$customerId = (int) $customer['id'];

// Transparently upgrade an old hash now that we have the plaintext in hand —
// this is how the cost factor gets raised later without a migration.
if (PasswordService::needsRehash((string) $customer['password_hash'])) {
    Database::pdo()->prepare('UPDATE customers SET password_hash = :hash WHERE id = :id')
        ->execute(['hash' => PasswordService::hash($password), 'id' => $customerId]);
    Logger::info('Password hash upgraded', ['customer_id' => $customerId]);
}

LoginAttempts::record($ip, $email, 'ok');
LoginAttempts::clearFor($email);

// Epoch comes from the row already read above, so starting the session costs
// no extra query.
Session::start($customerId, (int) $customer['session_epoch']);

Logger::info('Customer signed in', ['customer_id' => $customerId]);

Http::json(['status' => 'ok']);
