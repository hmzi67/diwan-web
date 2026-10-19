<?php
declare(strict_types=1);

namespace Diwan\Download;

use RuntimeException;

/** A download refused for a reason the customer may see, with its HTTP status. */
final class DownloadDenied extends RuntimeException
{
    /** @param array<string,mixed> $context logged, never returned */
    public function __construct(string $message, public readonly int $status, public readonly array $context = [])
    {
        parent::__construct($message);
    }
}
