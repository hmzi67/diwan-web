<?php
/**
 * GET  /api/pos-media.php?name=<file>     one product photo or logo of the counter's business
 * POST /api/pos-media.php { names: [...] } which of these files the business does not have yet
 *
 * Authenticated by the counter token (X-Terminal-Token). Uploads go to
 * pos-media-upload.php. See Diwan\Workspace\MediaStore.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Support\Http;
use Diwan\Workspace\Api;
use Diwan\Workspace\MediaStore;
use Diwan\Workspace\WorkspaceException;

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method === 'POST') {
    Api::run(static fn (): array => (new MediaStore())->check(Api::terminal(), Http::input()['names'] ?? null));
}
if ($method !== 'GET') {
    Http::fail('Method not allowed', 405);
}

try {
    $path = (new MediaStore())->fetch(Api::terminal(), $_GET['name'] ?? null);
} catch (WorkspaceException $e) {
    Http::json(['error' => $e->getMessage(), 'code' => $e->reason], $e->getCode());
}
http_response_code(200);
header('Content-Type: application/octet-stream');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment');
// A file's content never changes under its name.
header('Cache-Control: private, max-age=31536000, immutable');
readfile($path);
exit;
