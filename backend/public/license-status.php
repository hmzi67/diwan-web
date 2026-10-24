<?php
/**
 * POST /api/license-status.php  { license_key, machine_fingerprint }
 *
 * Called by the desktop app on every launch it has internet for (and every
 * few hours while running) to refresh its locally cached, signed licence
 * status. NOT browser-facing.
 *
 * Returns an Ed25519-signed payload the app stores and re-verifies offline
 * on every subsequent open — see Diwan\License\LicenseSigner for why the
 * signature is asymmetric and what exactly is signed:
 *
 *   { "payload": base64(json), "signature": base64, "kid": "..." }
 *
 * where the JSON is
 *
 *   { v, kid, license_key, status, issued_at, expires_at, device_fingerprint, max_offline_days,
 *     plan, entitlements, upgrade }
 *
 *   status             active | suspended | revoked | expired
 *   issued_at          server unix time — the app's monotonic floor for its
 *                      own clock, and how it orders competing payloads
 *   expires_at         unix time, or null for a perpetual licence
 *   device_fingerprint the hex fingerprint the app sent, echoed back so a
 *                      payload copied from another machine's database fails
 *                      the app's own comparison
 *   max_offline_days   how long the app may go without a successful refresh
 *                      before it insists on one — server-controlled so the
 *                      policy can change without an app release
 *   plan               {code, name} of the licence's plan, or null for a
 *                      licence issued before plans existed
 *   entitlements       sorted POS module/capability keys the plan unlocks
 *                      (Diwan\Plan\PlanCatalog), or null = unrestricted.
 *                      The app enforces this list in Rust on every command;
 *                      builds older than plans ignore the field.
 *   upgrade            {key: plan name} — the cheapest plan that unlocks each
 *                      key this licence lacks, for the app's upgrade message
 *
 * Who gets an answer: only the device the key is bound to. A wrong key, a
 * key bound elsewhere, and a revoked key from a stranger all produce the
 * same generic 403 as activate-license.php — this endpoint must not become
 * an enumeration oracle. The bound device IS told the truth, including
 * "revoked": it is the licensee, and locking promptly is the whole point.
 *
 * Shares activate-license.php's rate limit window and audit table via
 * ActivationThrottle, so the two endpoints cannot be played against each
 * other for extra attempts.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Config\Env;
use Diwan\Database\Database;
use Diwan\License\ActivationThrottle;
use Diwan\License\LicenseService;
use Diwan\License\LicenseSigner;
use Diwan\Plan\PlanCatalog;
use Diwan\Support\DeviceInput;
use Diwan\Support\Http;
use Diwan\Support\Logger;

Http::requireMethod('POST');

$input       = Http::input();
$key         = trim((string) ($input['license_key'] ?? ''));

if ($key === '') {
    Http::fail('Please provide a licence key.', 422);
}
$fingerprint = DeviceInput::fingerprint($input);

$prefix   = ActivationThrottle::prefixOf($key);
$throttle = new ActivationThrottle(Database::pdo(), Http::clientIp(), $prefix);
$throttle->enforce();

$licenses = new LicenseService();
$outcome  = $licenses->statusForDevice($key, DeviceInput::fingerprintHash($fingerprint));

switch ($outcome['result']) {
    case 'unknown':
        $throttle->record('status_refused');
        Logger::warning('Licence status refused', ['prefix' => $prefix]);
        Http::fail('That licence key is not valid.', 403);
        // no break — Http::fail() does not return

    case 'not_activated':
        // Distinguishable on purpose: the app's own first-run flow calls
        // activate-license.php first, so a real client never lands here.
        // It exists so a mis-ordered call fails with an actionable message
        // rather than looking like a bad key.
        $throttle->record('status_not_activated');
        Http::fail('This licence has not been activated on a device yet.', 409);
        // no break
}

$plan = null;
$entitlements = null;
$upgrade = new stdClass();
if ($outcome['plan_id'] !== null) {
    $catalog = new PlanCatalog();
    $planRow = $catalog->findById($outcome['plan_id']);
    $plan = ['code' => $planRow['code'], 'name' => $planRow['name']];
    $entitlements = $catalog->entitlementsForPlan($outcome['plan_id']);
    $upgrade = (object) $catalog->upgradeHints($entitlements);
}

$signed = (new LicenseSigner())->sign([
    'license_key'        => $licenses->normalizeKey($key),
    'status'             => $outcome['status'],
    'issued_at'          => time(),
    'expires_at'         => $outcome['expires_at'],
    'device_fingerprint' => $fingerprint,
    'max_offline_days'   => Env::int('LICENSE_MAX_OFFLINE_DAYS', 30),
    'plan'               => $plan,
    'entitlements'       => $entitlements,
    'upgrade'            => $upgrade,
]);

$throttle->record('status_' . $outcome['status']);
Logger::info('Licence status issued', ['prefix' => $prefix, 'status' => $outcome['status']]);
Http::json($signed);
