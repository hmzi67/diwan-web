<?php
/**
 * POST /api/activate-license.php  { license_key, machine_fingerprint, machine_hint? }
 *
 * Called by the desktop app on first run (and, harmlessly, on any later run
 * from the same machine — that path is a no-op success). NOT browser-facing.
 *
 * One-time activation: a licence key binds to exactly one machine fingerprint.
 * A second machine presenting the same key is rejected. See
 * Diwan\License\LicenseService::activate() for the locking that makes this
 * race-safe under concurrent attempts.
 *
 * Rate limited on two axes via ActivationThrottle (shared with
 * license-status.php): by IP (blunt brute force) and by the key's first
 * group (one stolen key hammered from rotating IPs).
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Database\Database;
use Diwan\License\ActivationThrottle;
use Diwan\License\LicenseService;
use Diwan\Support\Http;
use Diwan\Support\Logger;

Http::requireMethod('POST');

$input       = Http::input();
$key         = trim((string) ($input['license_key'] ?? ''));
$fingerprint = strtolower(trim((string) ($input['machine_fingerprint'] ?? '')));
$hint        = trim((string) ($input['machine_hint'] ?? ''));
$ip          = Http::clientIp();

if ($key === '') {
    Http::fail('Please provide a licence key.', 422);
}
if (!preg_match('/^[a-f0-9]{64}$/', $fingerprint)) {
    // The app must send sha256(machine id) hex-encoded — never the raw id.
    Http::fail('Invalid device fingerprint.', 422);
}
$hint = $hint !== '' ? substr(preg_replace('/[^\x20-\x7E]/', '', $hint), 0, 64) : null;

$prefix   = ActivationThrottle::prefixOf($key);
$throttle = new ActivationThrottle(Database::pdo(), $ip, $prefix);
$throttle->enforce();

$licenses = new LicenseService();
$outcome  = $licenses->activate($key, hash('sha256', $fingerprint), $hint);

switch ($outcome['result']) {
    case 'invalid_key':
        $throttle->record('invalid_key');
        Logger::warning('Licence activation: invalid or ineligible key', ['ip' => $ip, 'prefix' => $prefix]);
        // Generic on purpose: "doesn't exist" and "revoked/expired" must look
        // identical, or the endpoint becomes a key-enumeration oracle.
        Http::fail('That licence key is not valid.', 403);
        // no break — Http::fail() does not return

    case 'device_mismatch':
        $throttle->record('already_bound');
        Logger::warning('Licence activation refused: bound to another device', ['ip' => $ip, 'prefix' => $prefix]);
        Http::fail(
            'This licence key is already activated on another device. '
            . 'Sign in to your account to move it to a new device, or contact support.',
            409
        );
        // no break

    case 'activated':
        $throttle->record('activated');
        Logger::info('Licence activated', ['prefix' => $prefix]);
        Http::json(['status' => 'activated']);
        // no break

    case 'already_active_same_device':
        $throttle->record('reactivated');
        Http::json(['status' => 'activated']);
        // no break
}
