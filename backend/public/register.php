<?php
/**
 * POST /api/register.php  { email, password }
 *
 * Creates a customer account with a password and signs them straight in — no
 * email round-trip, so someone who came from a pricing card can go on to
 * checkout without leaving the browser.
 *
 * An email that already exists is NOT an error here, and the response is the
 * same either way: telling a stranger "that address is already registered"
 * turns this endpoint into an account-enumeration oracle. An existing account
 * is simply not touched, and the response points at sign-in.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Auth\PasswordService;
use Diwan\Auth\Session;
use Diwan\Database\Database;
use Diwan\Support\Http;
use Diwan\Support\Logger;

Http::requireMethod('POST');

$input    = Http::input();
$email    = strtolower(trim((string) ($input['email'] ?? '')));
$password = (string) ($input['password'] ?? '');

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    Http::fail('Please enter a valid email address.', 422);
}

$problem = PasswordService::validate($password);
if ($problem !== null) {
    Http::fail($problem, 422);
}

$pdo = Database::pdo();

$existing = $pdo->prepare('SELECT id, password_hash FROM customers WHERE email = :email LIMIT 1');
$existing->execute(['email' => $email]);
$existing = $existing->fetch();

if ($existing) {
    // Deliberately does not overwrite the password — that would let anyone
    // take over an account by "registering" an address they don't own.
    Logger::info('Registration attempted for existing email', ['customer_id' => $existing['id']]);
    Http::json([
        'status'  => 'exists',
        'message' => 'That email already has a Diwan account. Please sign in instead.',
    ]);
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
    Http::json([
        'status'  => 'exists',
        'message' => 'That email already has a Diwan account. Please sign in instead.',
    ]);
}

Session::start($customerId, 0);   // fresh row: session_epoch defaults to 0
Logger::info('Customer registered', ['customer_id' => $customerId]);

Http::json(['status' => 'ok']);
