<?php
declare(strict_types=1);

namespace Diwan\Support;

use Diwan\Config\Env;
use Throwable;

/**
 * Append-only JSON lines log, written inside private-storage/logs.
 *
 * Files older than LOG_RETENTION_DAYS (default 30) are pruned when a new day's
 * file is started: entries carry IP addresses, which are personal data and
 * should not be kept indefinitely.
 */
final class Logger
{
    /**
     * Context keys whose values never reach the log. Matched as substrings, so
     * keep them specific: "pass" alone also blanked keys like "passed".
     */
    private const REDACT = [
        'password', 'passwd', 'secret', 'salt', 'api_key', 'token', 'license_key', 'licence_key',
        'securehash', 'hashedreq', 'signature', 'authorization', 'cookie', 'fingerprint', 'email',
    ];

    /**
     * Masked anywhere in free text, including exception messages: a full
     * licence key (DIWAN-XXXX-XXXX-XXXX-XXXX) and email addresses (SMTP
     * replies echo the recipient back).
     */
    private const SCRUB = [
        '/\bDIWAN(-[0-9A-F]{4}){4}\b/i'          => 'DIWAN-[redacted]',
        '/[^\s<>"\'@:]+@[^\s<>"\'@]+\.[a-z]{2,}/i' => '[email]',
    ];

    public static function debug(string $message, array $context = []): void
    {
        if (Env::bool('LOG_DEBUG', false)) {
            self::write('debug', $message, $context);
        }
    }

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

    /** Class, message and a trimmed trace — enough to diagnose without a debugger. */
    public static function exception(string $message, Throwable $e, array $context = []): void
    {
        self::write('error', $message, $context + [
            'exception' => get_class($e),
            'reason'    => $e->getMessage(),
            'at'        => $e->getFile() . ':' . $e->getLine(),
            'trace'     => array_slice(explode("\n", $e->getTraceAsString()), 0, 12),
        ]);
    }

    /**
     * Context with every sensitive key blanked and licence keys masked in any
     * string value. Public so callers that persist payloads elsewhere (webhook
     * events) apply the same rules as the log file.
     */
    public static function redact(array $context): array
    {
        foreach ($context as $key => $value) {
            if (self::isSensitiveKey((string) $key)) {
                $context[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $context[$key] = self::redact($value);
            } elseif (is_string($value)) {
                $context[$key] = self::scrub($value);
            }
        }
        return $context;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);
        foreach (self::REDACT as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }
        return false;
    }

    private static function scrub(string $text): string
    {
        return (string) preg_replace(array_keys(self::SCRUB), array_values(self::SCRUB), $text);
    }

    private static function write(string $level, string $message, array $context): void
    {
        $dir = (defined('DIWAN_PRIVATE_STORAGE')
            ? DIWAN_PRIVATE_STORAGE
            : dirname(__DIR__, 2) . '/private-storage') . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $line = json_encode([
            'ts'      => gmdate('c'),
            'level'   => $level,
            'message' => self::scrub($message),
            'context' => self::redact($context),
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        $file = $dir . '/app-' . gmdate('Y-m-d') . '.log';
        $isNewFile = !is_file($file);

        // @: a log write must never take a request down with it. The fallback
        // keeps the entry visible in PHP's own error log instead of losing it.
        if (@file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            error_log('Diwan log write failed; entry: ' . $line);
            return;
        }
        if ($isNewFile) {
            self::prune($dir);
        }
    }

    private static function prune(string $dir): void
    {
        $days = Env::int('LOG_RETENTION_DAYS', 30);
        if ($days < 1) {
            return;
        }
        $cutoff = gmdate('Y-m-d', time() - $days * 86400);
        foreach (glob($dir . '/app-*.log') ?: [] as $old) {
            if (substr(basename($old, '.log'), 4) < $cutoff) {
                @unlink($old);
            }
        }
    }
}
