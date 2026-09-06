<?php
declare(strict_types=1);

namespace Diwan\Auth;

/**
 * Password hashing and verification.
 *
 * The algorithm is chosen at RUNTIME rather than hardcoded. Argon2id is
 * preferred, but shared hosting frequently ships PHP without libargon2, and
 * naming PASSWORD_ARGON2ID directly on such a host is a fatal error on every
 * sign-up — i.e. the store breaks on deploy, not in testing. Production
 * (PHP 8.3 on Hostinger) is reported by health.php's `password_algos` field so
 * this can be confirmed against the live host rather than assumed.
 *
 * bcrypt is a perfectly acceptable fallback; the point is that the choice is
 * made by what the host actually supports.
 *
 * Existing hashes keep working across an algorithm or parameter change:
 * password_verify() reads the algorithm out of the stored hash, and
 * needsRehash() lets sign-in transparently upgrade one on the next successful
 * login — so raising the cost later needs no migration.
 */
final class PasswordService
{
    /** Minimum length. Deliberately the only composition rule — see validate(). */
    public const MIN_LENGTH = 8;

    /**
     * 64 MB / 4 passes. Comfortably above OWASP's floor while staying under the
     * memory_limit a shared host is likely to grant a single request.
     */
    private const ARGON2_OPTIONS = [
        'memory_cost' => 65536,
        'time_cost'   => 4,
        'threads'     => 1,
    ];

    private const BCRYPT_OPTIONS = ['cost' => 12];

    public static function hash(string $password): string
    {
        [$algo, $options] = self::algorithm();
        $hash = password_hash($password, $algo, $options);

        // password_hash() returns false only on genuine failure (e.g. the host
        // cannot allocate the memory cost). Failing loudly here is right: the
        // alternative is storing something unusable as a password.
        if (!is_string($hash) || $hash === '') {
            throw new \RuntimeException('Password hashing failed on this host.');
        }
        return $hash;
    }

    public static function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Burns roughly the same time as a real verify() would, for accounts that
     * do not exist or have no password set. Without it, "unknown email" answers
     * noticeably faster than "wrong password" and the sign-in endpoint becomes
     * an account-enumeration oracle despite the identical error message.
     */
    public static function burnTime(): void
    {
        static $dummy = null;
        $dummy ??= self::hash('diwan-timing-equaliser');
        password_verify('diwan-timing-equaliser-x', $dummy);
    }

    public static function needsRehash(string $hash): bool
    {
        [$algo, $options] = self::algorithm();
        return password_needs_rehash($hash, $algo, $options);
    }

    /**
     * Length only. Composition rules (a digit, a symbol, mixed case) push
     * people towards predictable substitutions written on a note by the
     * till — worse for this audience than a longer passphrase.
     */
    public static function validate(string $password): ?string
    {
        if (strlen($password) < self::MIN_LENGTH) {
            return 'Password must be at least ' . self::MIN_LENGTH . ' characters.';
        }
        if (strlen($password) > 4096) {
            // Guards against a multi-megabyte body burning CPU in the KDF.
            return 'That password is too long.';
        }
        return null;
    }

    /** @return array{0: string, 1: array<string,int>} */
    private static function algorithm(): array
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return [PASSWORD_ARGON2ID, self::ARGON2_OPTIONS];
        }
        if (defined('PASSWORD_ARGON2I')) {
            return [PASSWORD_ARGON2I, self::ARGON2_OPTIONS];
        }
        return [PASSWORD_BCRYPT, self::BCRYPT_OPTIONS];
    }

    /** Reported by health.php so the live host's choice is verifiable. */
    public static function algorithmName(): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return 'argon2id';
        }
        if (defined('PASSWORD_ARGON2I')) {
            return 'argon2i';
        }
        return 'bcrypt';
    }
}
