<?php
/**
 * POST /api/issue-download.php  { license_key, platform }
 *
 * Exchanges a licence key for a single-use, 15-minute download URL.
 * Rate limited, because this endpoint is the obvious brute-force target.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Config\Env;
use Diwan\Download\DownloadService;
use Diwan\License\LicenseService;
use Diwan\Support\Http;
use Diwan\Support\Logger;

Http::requireMethod('POST');

$input    = Http::input();
$key      = trim((string) ($input['license_key'] ?? ''));
$platform = strtolower(trim((string) ($input['platform'] ?? 'windows')));
$ip       = Http::clientIp();

if (!in_array($platform, DownloadService::PLATFORMS, true)) {
    Http::fail('Unsupported platform.', 422);
}
// Optional build choice for platforms that ship several (macOS: apple-silicon /
// intel; Windows: x64 / x86; Linux: appimage / deb / rpm).
$variant = strtolower(trim((string) ($input['variant'] ?? '')));
if (!preg_match('/^[a-z0-9-]{0,32}$/', $variant)) {
    Http::fail('Unsupported build.', 422);
}
if ($key === '') {
    Http::fail('Please enter your licence key.', 422);
}

// --- Rate limit: every attempt counts, valid or not ------------------------
$downloads = new DownloadService();
if (!$downloads->allowAttempt($ip)) {
    Http::fail('Too many attempts. Please try again in an hour.', 429);
}

// --- Verify the licence ----------------------------------------------------
$licenses = new LicenseService();
$license  = $licenses->findActive($key);

if ($license === null) {
    Logger::warning('Invalid licence presented', ['ip' => $ip]);
    // Same message for "wrong key" and "expired key": do not help an attacker
    // work out which keys exist.
    Http::fail('That licence key is not valid or has expired.', 403);
}
if (!$licenses->hasDownloadsLeft($license)) {
    Http::fail('Download limit reached for this licence. Contact support.', 403);
}

try {
    $issued = $downloads->issueToken((int) $license['id'], $platform, $ip, $variant);
} catch (RuntimeException $e) {
    Logger::warning('No active release', ['platform' => $platform, 'variant' => $variant, 'reason' => $e->getMessage()]);
    Http::fail('That installer is not available yet.', 404);
}

Http::json([
    'download_url' => Env::appUrl('/api/download.php?token=' . $issued['token']),
    'filename'     => $issued['filename'],
    'expires_at'   => $issued['expires_at'],
    'downloads_remaining' => (int) $license['max_downloads'] - (int) $license['downloads_used'] - 1,
]);
