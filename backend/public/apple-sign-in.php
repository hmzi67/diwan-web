<?php
/**
 * POST /api/apple-sign-in.php
 *   { id_token, nonce, code?, name?, client: "web" | "app" }
 *
 * Signs in — or signs up — with an Apple identity token. The same account
 * rules as the password flow: a new account gets its trial in the same
 * transaction (register.php), an existing one is matched by Apple ID, then
 * by Apple-verified email (Diwan\Apple\AppleAccountService).
 *
 *   client=web → sets the usual session cookie; the page then goes to the
 *                dashboard exactly like after sign-in.php.
 *   client=app → returns a short-lived app token (the app has no cookie
 *                jar) and the account's StoreKit appAccountToken.
 *
 * `code` is Apple's one-time authorization code. When the server has the
 * Sign in with Apple key configured it is exchanged for a refresh token,
 * kept only so account deletion can revoke it.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Apple\AppleAccountService;
use Diwan\Apple\AppleAuthClient;
use Diwan\Apple\AppleConfig;
use Diwan\Apple\IdentityToken;
use Diwan\Apple\SignInNonce;
use Diwan\Auth\Session;
use Diwan\Support\Http;
use Diwan\Support\Logger;
use Diwan\Support\UserFacingException;

Http::requireMethod('POST');

$input   = Http::input();
$idToken = trim((string) ($input['id_token'] ?? ''));
$nonce   = trim((string) ($input['nonce'] ?? ''));
$code    = trim((string) ($input['code'] ?? ''));
$client  = (string) ($input['client'] ?? 'web');
$name    = trim(preg_replace('/\s+/', ' ', (string) ($input['name'] ?? '')));

if ($idToken === '' || $nonce === '' || !in_array($client, ['web', 'app'], true)) {
    Http::fail('Incomplete Apple sign-in request.', 422);
}
if (!SignInNonce::isValid($nonce)) {
    Http::fail('This sign-in attempt has expired. Please try again.', 400);
}

try {
    $claims = IdentityToken::verify($idToken, $nonce);
} catch (RuntimeException $e) {
    Logger::warning('Apple identity token rejected', ['reason' => $e->getMessage(), 'ip' => Http::clientIp()]);
    Http::fail('Apple sign-in could not be verified. Please try again.', 401);
}

// A token minted for the app must not open a web session, and vice versa.
$expectedAudience = $client === 'app' ? AppleConfig::bundleId() : AppleConfig::servicesId();
if ($claims['aud'] !== $expectedAudience) {
    Http::fail('Apple sign-in could not be verified. Please try again.', 401);
}

$refreshToken = $code !== ''
    ? AppleAuthClient::exchangeCode($code, $claims['aud'], $client === 'web' ? AppleConfig::webRedirectUri() : null)
    : null;

try {
    $account = (new AppleAccountService())->signIn(
        $claims,
        $name !== '' ? mb_substr($name, 0, 160) : null,
        $refreshToken,
        $claims['aud']
    );
} catch (UserFacingException $e) {
    Http::fail($e->getMessage(), 409);
}

Logger::info('Customer signed in with Apple', ['customer_id' => $account['customer_id'], 'client' => $client]);

if ($client === 'web') {
    Session::start($account['customer_id'], $account['session_epoch']);
    Http::json(['status' => 'ok', 'created' => $account['created']]);
}

Http::json([
    'status'            => 'ok',
    'created'           => $account['created'],
    'email'             => $claims['email'],
    'app_token'         => Session::issueAppToken($account['customer_id'], $account['session_epoch']),
    'app_account_token' => (new AppleAccountService())->appAccountToken($account['customer_id']),
]);
