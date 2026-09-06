<?php
/**
 * GET /api/verify-login.php?token=...
 *
 * The link the customer clicks in their email. Not JSON — this is a browser
 * navigation, so it redeems the token, starts the session cookie, and
 * redirects into the static site (dashboard.html or back to login with an
 * error the login page can show in plain language).
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Auth\LoginService;
use Diwan\Auth\Session;
use Diwan\Support\Http;

$token = (string) ($_GET['token'] ?? '');

// 64 hex chars — same shape as every other bin2hex(random_bytes(32)) token
// in this codebase (download_tokens). Reject junk before it touches the DB.
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    header('Location: /login.html?error=invalid_link');
    exit;
}

$customer = (new LoginService())->verify($token, Http::clientIp());

if ($customer === null) {
    header('Location: /login.html?error=expired_link');
    exit;
}

Session::start((int) $customer['id']);

// If this link was requested from a pricing card (see LoginService::
// requestLink()'s $plan param), send the customer straight back to that
// plan's checkout instead of the dashboard. Same shape check as the token
// above: reject anything that isn't a bare SKU before it touches a header.
$plan = (string) ($_GET['plan'] ?? '');
if (preg_match('/^[a-z0-9-]{1,64}$/', $plan) === 1) {
    header('Location: /checkout.html?plan=' . rawurlencode($plan));
    exit;
}

header('Location: /dashboard.html');
exit;
