<?php
/**
 * GET /api/download.php?token=...
 *
 * The single gate in front of private-storage. It holds no logic of its own:
 * DownloadService validates the token, burns it, and streams the file. There is
 * no code path here that can emit a file without a valid, unused token.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Download\DownloadService;
use Diwan\Support\Http;

Http::requireMethod('GET');

$token = (string) ($_GET['token'] ?? '');

// 64 hex chars — reject anything malformed before it touches the database.
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    Http::fail('Invalid download link.', 400);
}

(new DownloadService())->stream($token, Http::clientIp());
