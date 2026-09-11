<?php
declare(strict_types=1);

namespace Diwan\License;

use Diwan\Support\Http;
use Diwan\Support\Logger;
use PDO;

/**
 * Rate limiting + audit trail shared by every endpoint the desktop app
 * calls with a licence key (activate-license.php, license-status.php).
 *
 * Two axes, both against `license_activation_attempts`: by IP (blunt brute
 * force) and by the key's first group (one stolen key hammered from
 * rotating IPs). Extracted from activate-license.php so both endpoints
 * share one window and one budget — a key that is being guessed against
 * one endpoint must not get a fresh 10 attempts on the other. One change
 * from the original: only FAILED outcomes count (see COUNTED_RESULTS).
 */
final class ActivationThrottle
{
    private const WINDOW_SQL = 'DATE_SUB(NOW(), INTERVAL 1 HOUR)';
    private const MAX_PER_WINDOW = 10;

    /**
     * Only these outcomes consume budget. Successful activations and status
     * refreshes are still recorded (audit trail) but never counted: the app
     * refreshes on every launch and every few hours, and several tills in
     * one shop share one public IP — a limit that counted successes would
     * lock an honest shop out of its own licence checks after a handful of
     * restarts. What the limit exists for is guessing, and guessing fails.
     */
    private const COUNTED_RESULTS = "'invalid_key','already_bound','rate_limited','status_refused','status_not_activated'";

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $ip,
        private readonly string $prefix,
    ) {
    }

    /**
     * `license_key_prefix` is stored as "DIWAN-XXXX" (first group) — the
     * same slice LicenseService uses at issuance, so a malformed/foreign key
     * still gets a deterministic prefix to rate-limit on.
     */
    public static function prefixOf(string $rawKey): string
    {
        return strtoupper(substr(trim($rawKey), 0, 11));
    }

    /** Responds 429 and exits if either axis is over budget. */
    public function enforce(): void
    {
        $this->pdo->prepare(
            'DELETE FROM license_activation_attempts WHERE attempted_at < ' . self::WINDOW_SQL
        )->execute();

        $ipCount = $this->pdo->prepare(
            'SELECT COUNT(*) FROM license_activation_attempts
              WHERE ip = :ip AND result IN (' . self::COUNTED_RESULTS . ')
                AND attempted_at > ' . self::WINDOW_SQL
        );
        $ipCount->execute(['ip' => $this->ip]);

        $prefixCount = $this->pdo->prepare(
            'SELECT COUNT(*) FROM license_activation_attempts
              WHERE license_key_prefix = :prefix AND result IN (' . self::COUNTED_RESULTS . ')
                AND attempted_at > ' . self::WINDOW_SQL
        );
        $prefixCount->execute(['prefix' => $this->prefix]);

        if ((int) $ipCount->fetchColumn() >= self::MAX_PER_WINDOW
            || (int) $prefixCount->fetchColumn() >= self::MAX_PER_WINDOW) {
            Logger::warning('Licence endpoint rate limited', ['ip' => $this->ip, 'prefix' => $this->prefix]);
            $this->record('rate_limited');
            Http::fail('Too many attempts. Please try again in an hour.', 429);
        }
    }

    /** Fire-and-forget audit row; mirrors the download_attempts insert pattern. */
    public function record(string $result): void
    {
        $this->pdo->prepare(
            'INSERT INTO license_activation_attempts (ip, license_key_prefix, result, attempted_at)
             VALUES (:ip, :prefix, :result, NOW())'
        )->execute(['ip' => $this->ip, 'prefix' => $this->prefix, 'result' => $result]);
    }
}
