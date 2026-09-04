<?php
declare(strict_types=1);

namespace Diwan\Support;

use Diwan\Config\Env;

/** Append-only JSON lines log, written inside private-storage/logs. */
final class Logger
{
    private const REDACT = ['password', 'pass', 'secret', 'salt', 'api_key', 'token', 'pp_SecureHash'];

    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        $dir = rtrim(Env::get('PRIVATE_STORAGE_PATH', dirname(__DIR__, 2) . '/private-storage'), '/') . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $line = json_encode([
            'ts'      => gmdate('c'),
            'level'   => $level,
            'message' => $message,
            'context' => self::redact($context),
        ], JSON_UNESCAPED_SLASHES);

        @file_put_contents($dir . '/app-' . gmdate('Y-m-d') . '.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /** Keeps gateway credentials and licence keys out of the log file. */
    private static function redact(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $context[$key] = self::redact($value);
                continue;
            }
            foreach (self::REDACT as $needle) {
                if (stripos((string) $key, $needle) !== false) {
                    $context[$key] = '[redacted]';
                    break;
                }
            }
        }
        return $context;
    }
}
