<?php
/**
 * POST /api/register.php  { email, password }
 *
 * TEMPORARY no-payment onboarding: account and trial are committed together,
 * then the customer is signed in. Existing accounts must sign in; registering
 * again never changes their password or grants another trial.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Auth\PasswordService;
use Diwan\Auth\Session;
use Diwan\Database\Database;
use Diwan\Support\Http;
use Diwan\Support\Logger;
use Diwan\Trial\TrialService;

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
    $customerId = Database::transaction(static function (PDO $pdo) use ($email, $hash): int {
        $pdo->prepare(
            'INSERT INTO customers (email, password_hash, password_set_at, created_at)
             VALUES (:email, :hash, NOW(), NOW())'
        )->execute(['email' => $email, 'hash' => $hash]);
        $id = (int) $pdo->lastInsertId();
        (new TrialService())->ensureForCustomer($pdo, $id);
        return $id;
    });
} catch (PDOException $e) {
    // Unique key on customers.email: a concurrent request won the race.
    if (($e->errorInfo[1] ?? null) !== 1062) {
        throw $e;
    }
    $check = $pdo->prepare('SELECT id FROM customers WHERE email = :email');
    $check->execute(['email' => $email]);
    if ($check->fetchColumn() === false) {
        throw $e; // An unrelated uniqueness failure must not pretend signup succeeded.
    }
    Http::json([
        'status'  => 'exists',
        'message' => 'That email already has a Diwan account. Please sign in instead.',
    ]);
}

Session::start($customerId, 0);   // fresh row: session_epoch defaults to 0
Logger::info('Customer registered', ['customer_id' => $customerId]);

Http::json(['status' => 'ok']);
