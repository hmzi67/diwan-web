<?php
/** POST /api/logout.php — clears the session cookie. Always succeeds. */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Auth\Session;
use Diwan\Support\Http;

Http::requireMethod('POST');

Session::destroy();

Http::json(['message' => 'Logged out.']);
