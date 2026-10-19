<?php
declare(strict_types=1);

namespace Diwan\Support;

use RuntimeException;

/**
 * A failure whose message is written for the customer and is safe to return
 * as-is. Every other exception's message is internal: endpoints log it and
 * answer with generic text, so a driver or library message never reaches a
 * client.
 */
final class UserFacingException extends RuntimeException
{
}
