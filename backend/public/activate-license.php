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
 * Rate limited on two axes, mirroring issue-download.php's pattern against
 * license_activation_attempts: by IP (blunt brute force) and by the key's
 * first group (one stolen key hammered from rotating IPs).
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Database\Database;
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

// license_key_prefix is stored as "DIWAN-XXXX" (first group) — same slice
// LicenseService uses at issuance, so a malformed/foreign key still gets a
// deterministic prefix to rate-limit on.
$prefix = strtoupper(substr(trim($key), 0, 11));

$pdo = Database::pdo();
$pdo->prepare('DELETE FROM license_activation_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)')->execute();

$ipCount = $pdo->prepare(
    'SELECT COUNT(*) FROM license_activation_attempts
      WHERE ip = :ip AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
);
$ipCount->execute(['ip' => $ip]);

$prefixCount = $pdo->prepare(
    'SELECT COUNT(*) FROM license_activation_attempts
      WHERE license_key_prefix = :prefix AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
);
$prefixCount->execute(['prefix' => $prefix]);

if ((int) $ipCount->fetchColumn() >= 10 || (int) $prefixCount->fetchColumn() >= 10) {
    Logger::warning('Licence activation rate limited', ['ip' => $ip, 'prefix' => $prefix]);
    logAttempt($pdo, $ip, $prefix, 'rate_limited');
    Http::fail('Too many attempts. Please try again in an hour.', 429);
}

$licenses = new LicenseService();
$outcome  = $licenses->activate($key, hash('sha256', $fingerprint), $hint);

switch ($outcome['result']) {
    case 'invalid_key':
        logAttempt($pdo, $ip, $prefix, 'invalid_key');
        Logger::warning('Licence activation: invalid or ineligible key', ['ip' => $ip, 'prefix' => $prefix]);
        // Generic on purpose: "doesn't exist" and "revoked/expired" must look
        // identical, or the endpoint becomes a key-enumeration oracle.
        Http::fail('That licence key is not valid.', 403);
        // no break — Http::fail() does not return

    case 'device_mismatch':
        logAttempt($pdo, $ip, $prefix, 'already_bound');
        Logger::warning('Licence activation refused: bound to another device', ['ip' => $ip, 'prefix' => $prefix]);
        Http::fail(
            'This licence key is already activated on another device. '
            . 'Sign in to your account to move it to a new device, or contact support.',
            409
        );
        // no break

    case 'activated':
        logAttempt($pdo, $ip, $prefix, 'activated');
        Logger::info('Licence activated', ['prefix' => $prefix]);
        Http::json(['status' => 'activated']);
        // no break

    case 'already_active_same_device':
        logAttempt($pdo, $ip, $prefix, 'reactivated');
        Http::json(['status' => 'activated']);
        // no break
}

/** Fire-and-forget audit row; mirrors the download_attempts insert pattern. */
function logAttempt(PDO $pdo, string $ip, string $prefix, string $result): void
{
    $pdo->prepare(
        'INSERT INTO license_activation_attempts (ip, license_key_prefix, result, attempted_at)
         VALUES (:ip, :prefix, :result, NOW())'
    )->execute(['ip' => $ip, 'prefix' => $prefix, 'result' => $result]);
}
