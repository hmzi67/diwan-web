<?php
declare(strict_types=1);

namespace Diwan\Database;

use Diwan\Config\Env;
use PDO;
use RuntimeException;
use Throwable;

/** Lazily-constructed shared PDO connection. */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            Env::require('DB_HOST'),
            Env::int('DB_PORT', 3306),
            Env::require('DB_NAME')
        );

        self::$pdo = new PDO($dsn, Env::require('DB_USER'), Env::require('DB_PASS'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Real prepared statements, so placeholders are never string-spliced.
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        return self::$pdo;
    }

    /** Connects and runs a trivial query; throws if the database is unusable. */
    public static function ping(): void
    {
        self::pdo()->query('SELECT 1');
    }

    /**
     * Maps a connection failure to a coarse category. Deliberately a fixed
     * vocabulary: enough to tell you which thing to go fix, not enough to help
     * anyone attack the database. Used by health.php.
     */
    public static function classifyFailure(Throwable $e): string
    {
        if ($e instanceof RuntimeException && str_contains($e->getMessage(), 'Missing required environment value')) {
            return 'not_configured';   // a DB_* value is absent from .env
        }
        $message = $e->getMessage();
        return match (true) {
            str_contains($message, '1045'), str_contains($message, 'Access denied')   => 'auth_failed',
            str_contains($message, '1049'), str_contains($message, 'Unknown database') => 'unknown_database',
            str_contains($message, '2002'), str_contains($message, '2005')             => 'unreachable',
            default                                                                    => 'unknown',
        };
    }

    public static function transaction(callable $work): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $work($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
