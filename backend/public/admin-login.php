<?php
/**
 * POST /api/admin-login.php  { email, password }  → { admin, csrf_token }
 *
 * Platform administrators only (admin_users — never a customer account).
 * Rate-limited per IP and per address; every success and failure for a real
 * admin account is written to the audit log.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Admin\AdminAuth;
use Diwan\Admin\AdminSession;
use Diwan\Support\Http;

Http::requireMethod('POST');
header('X-Robots-Tag: noindex');

$input = Http::input();
$email = trim((string) ($input['email'] ?? ''));
$password = (string) ($input['password'] ?? '');
if ($email === '' || $password === '') {
    Http::fail('Please enter your email and password.', 422);
}

$admin = AdminAuth::signIn($email, $password, Http::clientIp());
$csrf = AdminSession::start($admin);
Http::json([
    'admin'      => ['id' => (int) $admin['id'], 'email' => $admin['email'], 'name' => $admin['name']],
    'csrf_token' => $csrf,
]);
