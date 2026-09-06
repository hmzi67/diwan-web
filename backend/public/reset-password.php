<?php
/**
 * POST /api/reset-password.php  { token, password }
 *
 * Redeems a reset token and sets the new password. Called by the static page
 * /auth/reset-password.html, which carries the token in its query string.
 *
 * On success the customer is signed straight in — they have just proven
 * control of the mailbox and chosen a password; making them immediately type
 * it again adds nothing.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Auth\PasswordResetService;
use Diwan\Auth\PasswordService;
use Diwan\Auth\Session;
use Diwan\Support\Http;

Http::requireMethod('POST');

$input    = Http::input();
$token    = (string) ($input['token'] ?? '');
$password = (string) ($input['password'] ?? '');

// Same shape check as verify-login.php: reject junk before it reaches the DB.
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    Http::fail('That reset link is not valid. Please request a new one.', 400);
}

$problem = PasswordService::validate($password);
if ($problem !== null) {
    Http::fail($problem, 422);
}

$customerId = (new PasswordResetService())->reset($token, $password, Http::clientIp());

if ($customerId === null) {
    Http::fail('That reset link has expired or was already used. Please request a new one.', 410);
}

// PasswordResetService has just bumped this customer's session_epoch, which
// invalidated every cookie issued before now — including this browser's, and
// including an attacker's on another device. Issue a fresh one so the person
// who just proved control of the mailbox stays signed in.
Session::start($customerId);

Http::json(['status' => 'ok']);
