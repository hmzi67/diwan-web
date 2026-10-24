<?php
/**
 * POST /api/pos-media-upload.php?name=<file>   body: the image bytes
 *
 * Stores one product photo or logo for the counter's business. Re-sending a
 * file the business already has is a no-op. Authenticated by the counter
 * token (X-Terminal-Token). See Diwan\Workspace\MediaStore.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Support\Http;
use Diwan\Workspace\Api;
use Diwan\Workspace\MediaStore;

Http::requireMethod('POST');

Api::run(static fn (): array => (new MediaStore())->put(Api::terminal(), $_GET['name'] ?? null, Http::rawBody()));
