<?php
/**
 * GET  /api/pos-workspace.php                       this counter and its business
 * POST /api/pos-workspace.php { action, name? }
 *   claim_seed     this counter's data becomes the business data (empty workspace only)
 *   complete_seed  the seeding counter finished uploading; others may now join
 *   joined         this counter downloaded and applied the business data
 *   rename         { name }
 *
 * Authenticated by the counter token (X-Terminal-Token).
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Support\Http;
use Diwan\Workspace\Api;
use Diwan\Workspace\TerminalRegistry;
use Diwan\Workspace\WorkspaceException;

Api::run(static function (): array {
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if ($method !== 'GET' && $method !== 'POST') {
        throw new WorkspaceException('bad_request', 'Method not allowed', 405);
    }
    $ctx = Api::terminal();
    $registry = new TerminalRegistry();
    if ($method === 'GET') {
        return $registry->status($ctx);
    }
    $input = Http::input();
    return match ((string) ($input['action'] ?? '')) {
        'claim_seed'    => $registry->claimSeed($ctx),
        'complete_seed' => $registry->completeSeed($ctx),
        'joined'        => $registry->markJoined($ctx),
        'rename'        => $registry->rename($ctx, (string) ($input['name'] ?? '')),
        default         => throw new WorkspaceException('bad_request', 'Unknown action.', 422),
    };
});
