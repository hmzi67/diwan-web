<?php
declare(strict_types=1);

namespace Diwan\Admin;

use Diwan\Database\Database;
use Diwan\Support\UserFacingException;
use PDO;

/**
 * Platform settings an administrator may change at run time. Each key has a
 * fixed type and range here; anything else is refused, so the table can never
 * hold a value the code does not know how to read.
 */
final class AdminSettings
{
    /** key => [type, default, min, max, label] */
    public const DEFINITIONS = [
        'license_period_days'           => ['int', 30, 1, 3660, 'Default licence period on approval (days)'],
        'expiry_reminder_days'          => ['int', 7, 1, 60, 'Remind customers this many days before expiry'],
        'allow_request_after_rejection' => ['bool', true, null, null, 'Let a rejected customer submit a new request'],
        'admin_notification_email'      => ['email', '', null, null, 'Email new licence requests to (blank = in-app only)'],
    ];

    public static function int(string $key): int
    {
        return (int) self::get($key);
    }

    public static function bool(string $key): bool
    {
        return (bool) self::get($key);
    }

    public static function string(string $key): string
    {
        return (string) self::get($key);
    }

    /** @return array<string, int|bool|string> */
    public static function all(): array
    {
        $stored = Database::pdo()->query('SELECT setting_key, setting_value FROM admin_settings')
            ->fetchAll(PDO::FETCH_KEY_PAIR);
        $values = [];
        foreach (self::DEFINITIONS as $key => $definition) {
            $values[$key] = array_key_exists($key, $stored)
                ? self::cast($definition[0], (string) $stored[$key])
                : $definition[1];
        }
        return $values;
    }

    /**
     * Validates and stores one setting; returns the stored value.
     * @throws UserFacingException for an unknown key or out-of-range value
     */
    public static function set(string $key, mixed $value, int $adminId, PDO $pdo): int|bool|string
    {
        $definition = self::DEFINITIONS[$key] ?? null;
        if ($definition === null) {
            throw new UserFacingException('Unknown setting.');
        }
        [$type, , $min, $max, $label] = $definition;
        $clean = match ($type) {
            'int' => filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]),
            'bool' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
            'email' => trim((string) $value) === '' ? '' : filter_var(trim((string) $value), FILTER_VALIDATE_EMAIL),
        };
        // false is a real answer for a bool; for the others it means invalid.
        if ($clean === null || ($type !== 'bool' && $clean === false)) {
            throw new UserFacingException("{$label}: invalid value.");
        }
        $pdo->prepare(
            'INSERT INTO admin_settings (setting_key, setting_value, updated_by, updated_at)
             VALUES (:key, :value, :admin, NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                     updated_by = VALUES(updated_by), updated_at = NOW()'
        )->execute(['key' => $key, 'value' => is_bool($clean) ? ($clean ? '1' : '0') : (string) $clean, 'admin' => $adminId]);
        return $clean;
    }

    private static function get(string $key): int|bool|string
    {
        return self::all()[$key] ?? throw new UserFacingException('Unknown setting.');
    }

    private static function cast(string $type, string $raw): int|bool|string
    {
        return match ($type) {
            'int' => (int) $raw,
            'bool' => $raw === '1',
            default => $raw,
        };
    }
}
