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
 *   { v, kid, license_key, status, issued_at, expires_at, device_fingerprint, max_offline_days }
 *
 *   status             active | revoked | expired
 *   issued_at          server unix time — the app's monotonic floor for its
 *                      own clock, and how it orders competing payloads
 *   expires_at         unix time, or null for a perpetual licence
 *   device_fingerprint the hex fingerprint the app sent, echoed back so a
 *                      payload copied from another machine's database fails
 *                      the app's own comparison
 *   max_offline_days   how long the app may go without a successful refresh
 *                      before it insists on one — server-controlled so the
 *                      policy can change without an app release
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
use Diwan\Support\Http;
use Diwan\Support\Logger;

Http::requireMethod('POST');

$input       = Http::input();
$key         = trim((string) ($input['license_key'] ?? ''));
$fingerprint = strtolower(trim((string) ($input['machine_fingerprint'] ?? '')));

if ($key === '') {
    Http::fail('Please provide a licence key.', 422);
}
if (!preg_match('/^[a-f0-9]{64}$/', $fingerprint)) {
    // The app must send sha256(machine id) hex-encoded — never the raw id.
    Http::fail('Invalid device fingerprint.', 422);
}

$prefix   = ActivationThrottle::prefixOf($key);
$throttle = new ActivationThrottle(Database::pdo(), Http::clientIp(), $prefix);
$throttle->enforce();

$licenses = new LicenseService();
$outcome  = $licenses->statusForDevice($key, hash('sha256', $fingerprint));

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

$signed = (new LicenseSigner())->sign([
    'license_key'        => $licenses->normaliseKey($key),
    'status'             => $outcome['status'],
    'issued_at'          => time(),
    'expires_at'         => $outcome['expires_at'],
    'device_fingerprint' => $fingerprint,
    'max_offline_days'   => Env::int('LICENSE_MAX_OFFLINE_DAYS', 30),
]);

$throttle->record('status_' . $outcome['status']);
Logger::info('Licence status issued', ['prefix' => $prefix, 'status' => $outcome['status']]);
Http::json($signed);
