<?php
/**
 * POST /api/delete-account.php  { app_token? }
 *
 * Deletes the signed-in customer's account — website session cookie, or the
 * iOS app's token right after a fresh Sign in with Apple. App Review
 * requires in-app account deletion for any app that can create an account.
 *
 * What survives is the purchase record, scrubbed of personal data: a paid
 * licence keeps working on the device it's activated on (see
 * Diwan\Apple\AppleAccountService::delete).
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Apple\AppleAccountService;
use Diwan\Auth\Session;
use Diwan\Support\Http;

Http::requireMethod('POST');

$input      = Http::input();
$customerId = Session::customerIdFromCookieOrAppToken(isset($input['app_token']) ? (string) $input['app_token'] : null);
if ($customerId === null) {
    Http::fail('Please sign in again to delete your account.', 401);
}

(new AppleAccountService())->delete($customerId);
Session::destroy();

Http::json(['status' => 'deleted']);
