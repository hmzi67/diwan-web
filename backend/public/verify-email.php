<?php
/**
 * POST /api/verify-email.php  { token }
 *
 * Called by the static page /auth/verify-email.html, which carries the token
 * in its query string and POSTs it back. Redeems the token, stamps the
 * account verified, and starts the session in the same step.
 *
 * Signing the customer in here is deliberate: the token proves control of the
 * mailbox, and the account was created seconds earlier by whoever requested
 * it. Making them retype the password they just chose adds nothing — and the
 * token can only ever mint a session for its own customer, so there is no way
 * to point it at anyone else's account.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Auth\EmailVerificationService;
use Diwan\Auth\Session;
use Diwan\Support\Crypto;
use Diwan\Support\Http;

Http::requireMethod('POST');

$input = Http::input();
$token = (string) ($input['token'] ?? '');

// 64 hex chars — same shape as every other bin2hex(random_bytes(32)) token
// in this codebase. Reject junk before it touches the DB.
if (!Crypto::isToken($token)) {
    Http::fail('That verification link is not valid. Please request a new one.', 400);
}

$customerId = (new EmailVerificationService())->verify($token, Http::clientIp());

if ($customerId === null) {
    Http::fail('That verification link has expired or was already used. Please request a new one.', 410);
}

Session::start($customerId);

Http::json(['status' => 'ok']);
