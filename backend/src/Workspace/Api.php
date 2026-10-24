<?php
declare(strict_types=1);

namespace Diwan\Workspace;

use Diwan\Database\Database;
use Diwan\Support\Http;

/**
 * Shared plumbing of the counter-sync endpoints (pos-*.php): the counter's
 * token, a size cap, and refusals as `{error, code}` so the POS can branch
 * on `code` and show `error`.
 */
final class Api
{
    /** Generous for a first upload of a large shop, far below anything abusive. */
    private const MAX_BODY_BYTES = 16 * 1024 * 1024;

    public static function run(callable $handler): never
    {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > self::MAX_BODY_BYTES) {
            Http::json(['error' => 'Request too large.', 'code' => 'bad_request'], 413);
        }
        try {
            Http::json($handler());
        } catch (WorkspaceException $e) {
            Http::json(['error' => $e->getMessage(), 'code' => $e->reason], $e->getCode());
        }
    }

    /**
     * The calling counter. Read from X-Terminal-Token (Apache on shared
     * hosting drops `Authorization` before PHP sees it); `Authorization:
     * Bearer` is accepted where it survives.
     */
    public static function terminal(): array
    {
        $token = $_SERVER[TerminalRegistry::TOKEN_HEADER] ?? null;
        if ($token === null && preg_match('/^Bearer\s+(\S+)$/', (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $m) === 1) {
            $token = $m[1];
        }
        $ctx = (new TerminalRegistry())->authenticate($token);
        self::countRequest();
        return $ctx;
    }

    /**
     * One more request this hour for the calling endpoint (admin panel →
     * Businesses & Counters → server load). Best effort: a failure here never
     * fails the request.
     */
    private static function countRequest(): void
    {
        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '.php');
        $endpoint = match (true) {
            $script === 'pos-sync-push'          => 'push',
            $script === 'pos-sync-pull'          => 'pull',
            $script === 'pos-stock-reserve'      => 'reserve',
            str_starts_with($script, 'pos-media') => 'media',
            default                              => 'other',
        };
        try {
            Database::pdo()->prepare(
                "INSERT INTO sync_load (hour_start, endpoint, requests) VALUES (DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00'), :e, 1)
                 ON DUPLICATE KEY UPDATE requests = requests + 1"
            )->execute(['e' => $endpoint]);
        } catch (\Throwable) {
        }
    }
}
