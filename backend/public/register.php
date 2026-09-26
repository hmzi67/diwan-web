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
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Auth\EmailVerificationService;
use Diwan\Auth\LoginAttempts;
use Diwan\Auth\PasswordService;
use Diwan\Config\Env;
use Diwan\Database\Database;
use Diwan\Support\Http;
use Diwan\Support\Logger;
use Diwan\Support\Mailer;

Http::requireMethod('POST');

// Mirrors the other auth endpoints' IP window: one host churning through
// signups is both an enumeration sweep and a row-flooding attack.
$maxPerIpPerHour = 10;

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

$recent = Database::pdo()->prepare(
    'SELECT COUNT(*) FROM login_attempts
      WHERE ip = :ip AND result = "register"
        AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
);
$recent->execute(['ip' => $ip]);
if ((int) $recent->fetchColumn() >= $maxPerIpPerHour) {
    Logger::warning('Registration rate limited by IP', ['ip' => $ip]);
    Http::fail('Too many attempts. Please wait an hour and try again.', 429);
}

$pdo = Database::pdo();

$existing = $pdo->prepare('SELECT id, email_verified_at FROM customers WHERE email = :email LIMIT 1');
$existing->execute(['email' => $email]);
$existing = $existing->fetch();

// One response object for both paths, built once below.
$respond = function () use ($email, $ip): never {
    LoginAttempts::record($ip, $email, 'register');
    Http::json([
        'status'  => 'sent',
        'message' => 'Check your email for a verification link to finish creating your account.',
    ]);
};

if ($existing) {
    // Deliberately does not touch the password — "registering" an address you
    // don't own must never take over the account that does.
    Logger::info('Registration attempted for existing email', ['customer_id' => $existing['id']]);

    if ($existing['email_verified_at'] === null) {
        // Same mailbox proof either way: hand the real owner a working link
        // so the notice cannot be used to stall someone else's signup.
        (new EmailVerificationService())->send($email, $ip, $plan);
    } else {
        $site = rtrim((string) Env::get('APP_URL', ''), '/');
        Mailer::send(
            $email,
            'You already have a Diwan account',
            "Someone (hopefully you) tried to sign up for Diwan with this email address,\n"
            . "but an account already exists — so nothing was changed.\n\n"
            . "To sign in: {$site}/auth/login.html\n"
            . "If you've forgotten your password: {$site}/auth/forgot-password.html\n",
            'From: ' . Env::get('MAIL_FROM', 'no-reply@localhost')
        );
    }

    $respond();
}

$hash = PasswordService::hash($password);

try {
    $pdo->prepare(
        'INSERT INTO customers (email, password_hash, password_set_at, created_at)
         VALUES (:email, :hash, NOW(), NOW())'
    )->execute(['email' => $email, 'hash' => $hash]);
    $customerId = (int) $pdo->lastInsertId();
} catch (PDOException $e) {
    // Unique key on customers.email: a concurrent request won the race.
    if ($e->getCode() !== '23000') {
        throw $e;
    }
    Logger::info('Registration raced into existing email', ['ip' => $ip]);
    $respond();
}

Logger::info('Customer registered, pending verification', ['customer_id' => $customerId]);
(new EmailVerificationService())->send($email, $ip, $plan);

$respond();
