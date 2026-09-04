<?php
declare(strict_types=1);

namespace Diwan\Support;

/** Tiny request/response helpers shared by every entrypoint. */
final class Http
{
    public static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function fail(string $message, int $status = 400): never
    {
        self::json(['error' => $message], $status);
    }

    public static function requireMethod(string $method): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
            header('Allow: ' . $method);
            self::fail('Method not allowed', 405);
        }
    }

    /** Reads a JSON body, falling back to form-encoded input. */
    public static function input(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw !== '' && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'json')) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return $_POST ?: [];
    }

    public static function rawBody(): string
    {
        return file_get_contents('php://input') ?: '';
    }

    public static function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}
