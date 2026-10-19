<?php
declare(strict_types=1);

namespace Diwan\Auth;

use Diwan\Database\Database;
use PDO;

/** Reads and writes of the customers table that entrypoints need. */
final class Customers
{
    /** Emails are stored lower-cased and trimmed; look them up the same way. */
    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /** Everything sign-in and the email flows need, in one read. */
    public static function findByEmail(string $email): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, email, password_hash, session_epoch, email_verified_at, apple_sub
               FROM customers WHERE email = :email LIMIT 1'
        );
        $stmt->execute(['email' => self::normalizeEmail($email)]);
        return $stmt->fetch() ?: null;
    }

    public static function findById(int $customerId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT id, email, name FROM customers WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $customerId]);
        return $stmt->fetch() ?: null;
    }

    public static function create(PDO $pdo, string $email, string $passwordHash): int
    {
        $pdo->prepare(
            'INSERT INTO customers (email, password_hash, password_set_at, created_at)
             VALUES (:email, :hash, NOW(), NOW())'
        )->execute(['email' => self::normalizeEmail($email), 'hash' => $passwordHash]);
        return (int) $pdo->lastInsertId();
    }

    public static function updatePasswordHash(int $customerId, string $passwordHash): void
    {
        Database::pdo()->prepare('UPDATE customers SET password_hash = :hash WHERE id = :id')
            ->execute(['hash' => $passwordHash, 'id' => $customerId]);
    }
}
