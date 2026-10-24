<?php
declare(strict_types=1);

namespace Diwan\Workspace;

use RuntimeException;

/**
 * A refusal from the counter-sync API. `reason` is a stable machine-readable
 * code the POS branches on (e.g. `not_joined`, `seed_taken`); the message is
 * written for the person at the counter and is safe to show as-is.
 */
final class WorkspaceException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, int $status = 409)
    {
        parent::__construct($message, $status);
    }
}
