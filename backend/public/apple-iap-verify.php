<?php
/**
 * POST /api/apple-iap-verify.php
 *   { signed_transaction, machine_fingerprint, machine_hint?, app_token?, link_only? }
 *
 * Called by the iOS app (from Rust — never the webview) after a StoreKit
 * purchase, on "Restore purchases", and for any transaction StoreKit still
 * holds unfinished at launch. Returns the licence key for that purchase;
 * the app then activates it through activate-license.php like any other key
 * and only then tells StoreKit the transaction is finished.
 *
 * Nothing in the request is trusted except what Apple signed: the product,
 * price, environment and purchase identity all come out of the verified JWS
 * (Diwan\Apple\AppStoreJws). Idempotent — the same purchase always yields
 * the same licence (Diwan\Apple\AppStoreService::recordPurchase).
 *
 * `app_token` is optional: purchasing never requires an account (App Review
 * rejects apps that force registration before a non-account purchase). When
 * present, an ownerless purchase is linked to that account.
 *
 * `link_only` is set when the app is only linking purchases to a freshly
 * signed-in account: the device binding is left alone, so linking from a
 * second iPad can't move the licence away from the till that uses it.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Apple\AppleConfig;
use Diwan\Apple\AppStoreService;
use Diwan\Auth\Session;
use Diwan\Support\DeviceInput;
use Diwan\Support\Http;
use Diwan\Support\Logger;
use Diwan\Support\UserFacingException;

Http::requireMethod('POST');

$input       = Http::input();
$jws         = trim((string) ($input['signed_transaction'] ?? ''));

if ($jws === '') {
    Http::fail('Missing App Store transaction.', 422);
}
$fingerprint = DeviceInput::fingerprint($input);
$hint        = DeviceInput::hint($input);

$store = new AppStoreService();

// Only UserFacingException messages are written for the customer; anything
// else (JWS or certificate-chain internals) gets generic text.
try {
    $tx = $store->verifiedTransaction($jws);
} catch (RuntimeException $e) {
    Logger::warning('App Store transaction rejected', ['reason' => $e->getMessage(), 'ip' => Http::clientIp()]);
    Http::fail(
        $e instanceof UserFacingException ? $e->getMessage() : 'This App Store purchase could not be verified.',
        422
    );
}

$customerId = Session::customerIdFromAppToken(isset($input['app_token']) ? (string) $input['app_token'] : null);

try {
    $purchase = $store->recordPurchase($tx, $customerId);
} catch (RuntimeException $e) {
    Logger::warning('App Store purchase refused', ['reason' => $e->getMessage()]);
    Http::fail(
        $e instanceof UserFacingException ? $e->getMessage() : 'This purchase could not be recorded. Please contact support.',
        409
    );
}

$move = !empty($input['link_only'])
    ? 'unused'
    : $store->moveToDevice($purchase['license_id'], DeviceInput::fingerprintHash($fingerprint), $hint);
if ($move === 'throttled') {
    Http::fail(
        'This licence was moved to another device in the last '
        . AppleConfig::restoreMoveCooldownDays() . ' days. Contact support to move it again.',
        409
    );
}

Http::json([
    'status'      => 'ok',
    'license_key' => $purchase['license_key'],
    'created'     => $purchase['created'],
    'moved'       => $move === 'moved',
    'product_id'  => $tx['product_id'],
]);
