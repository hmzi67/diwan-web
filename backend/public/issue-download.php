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
use Diwan\Database\Database;
use Diwan\Download\DownloadService;
use Diwan\License\LicenseService;
use Diwan\Support\Http;
use Diwan\Support\Logger;

Http::requireMethod('POST');

$input    = Http::input();
$key      = trim((string) ($input['license_key'] ?? ''));
$platform = strtolower(trim((string) ($input['platform'] ?? 'windows')));
$ip       = Http::clientIp();

if (!in_array($platform, ['windows', 'macos', 'android'], true)) {
    Http::fail('Unsupported platform.', 422);
}
if ($key === '') {
    Http::fail('Please enter your licence key.', 422);
}

// --- Rate limit: 10 attempts per IP per hour -------------------------------
$pdo = Database::pdo();
$pdo->prepare('DELETE FROM download_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)')->execute();

$count = $pdo->prepare(
    'SELECT COUNT(*) FROM download_attempts
      WHERE ip = :ip AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
);
$count->execute(['ip' => $ip]);

if ((int) $count->fetchColumn() >= 10) {
    Logger::warning('Download issue rate limited', ['ip' => $ip]);
    Http::fail('Too many attempts. Please try again in an hour.', 429);
}

$pdo->prepare('INSERT INTO download_attempts (ip, attempted_at) VALUES (:ip, NOW())')
    ->execute(['ip' => $ip]);

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

$downloads = new DownloadService();

try {
    $issued = $downloads->issueToken((int) $license['id'], $platform, $ip);
} catch (RuntimeException $e) {
    Logger::error('No active release', ['platform' => $platform, 'reason' => $e->getMessage()]);
    Http::fail('That installer is not available yet.', 404);
}

Http::json([
    'download_url' => rtrim((string) Env::require('APP_URL'), '/') . '/api/download.php?token=' . $issued['token'],
    'filename'     => $issued['filename'],
    'expires_at'   => $issued['expires_at'],
    'downloads_remaining' => (int) $license['max_downloads'] - (int) $license['downloads_used'] - 1,
]);
