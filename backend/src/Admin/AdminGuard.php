<?php
declare(strict_types=1);

namespace Diwan\Admin;

use Diwan\Support\Http;
use Diwan\Support\Logger;

/**
 * The first line of every admin-*.php endpoint. Answers 401 without a valid
 * administrator session, 405 for a method the endpoint does not take, and
 * 403 for a POST without the session's CSRF token. A customer session is
 * never enough: customers and administrators are different tables.
 */
final class AdminGuard
{
    /** @param list<string> $methods */
    public static function require(array $methods = ['GET']): array
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        if (!in_array($method, $methods, true)) {
            header('Allow: ' . implode(', ', $methods));
            Http::fail('Method not allowed', 405);
        }
        header('X-Robots-Tag: noindex');
        $admin = AdminSession::admin();
        if ($admin === null) {
            Http::fail('Please sign in as an administrator.', 401);
        }
        if ($method !== 'GET' && !AdminSession::checkCsrf()) {
            Logger::warning('Admin request refused: missing or wrong CSRF token', ['admin_id' => $admin['id']]);
            Http::fail('Your session token is missing or out of date. Reload the page and try again.', 403);
        }
        return $admin;
    }

    /** A positive integer query/body parameter, or a 422. */
    public static function id(mixed $value, string $label = 'id'): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            Http::fail("Invalid {$label}.", 422);
        }
        return $id;
    }
}
