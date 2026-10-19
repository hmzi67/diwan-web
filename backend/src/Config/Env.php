<?php
declare(strict_types=1);

namespace Diwan\Config;

use RuntimeException;

/**
 * Minimal .env reader.
 *
 * Values are kept in $GLOBALS rather than putenv()/$_ENV so they cannot leak
 * into phpinfo() output or be inherited by shelled-out processes.
 */
final class Env
{
    /** @var array<string,string> */
    private static array $values = [];
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }
        if (!is_readable($path)) {
            throw new RuntimeException(
                'Environment file not found or unreadable. Expected it at: ' . $path
            );
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $value = trim($value);
            // Quoted value: everything up to the matching closing quote is
            // the value; a trailing comment after the quote is ignored.
            // Unquoted: strip an inline comment (whitespace + #).
            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                $quote = $value[0];
                $end = strpos($value, $quote, 1);
                $value = $end === false ? substr($value, 1) : substr($value, 1, $end - 1);
            } else {
                $value = trim(preg_replace('/\s+#.*$/', '', $value));
            }
            self::$values[trim($key)] = $value;
        }
        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = self::$values[$key] ?? null;
        return ($value === null || $value === '') ? $default : $value;
    }

    /** Fails loudly at boot instead of silently misbehaving at 2am. */
    public static function require(string $key): string
    {
        $value = self::get($key);
        if ($value === null) {
            throw new RuntimeException("Missing required environment value: {$key}");
        }
        return $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);
        return $value === null ? $default : (int) $value;
    }

    /** Absolute URL on this site, e.g. appUrl('/api/download.php'). */
    public static function appUrl(string $path = ''): string
    {
        return rtrim(self::require('APP_URL'), '/') . $path;
    }

    public static function isProduction(): bool
    {
        return self::get('APP_ENV', 'local') === 'production';
    }
}
