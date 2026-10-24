<?php
declare(strict_types=1);

namespace Diwan\Download;

use Diwan\Database\Database;

/**
 * The download analytics ledger (download_events). Rows are written only by
 * the real download path — issuing a link, redeeming it, finishing the
 * stream — never by a page view, so a refresh cannot count as a download.
 *
 *   requested → served → completed     (local file, client stayed connected)
 *   requested → served                 (GitHub: handed a signed URL; GitHub
 *                                        does the transfer, so completion
 *                                        cannot be measured and is not claimed)
 *   requested → failed                 (refused at redemption, or interrupted)
 *
 * Holds no IP addresses, licence keys or tokens: only references.
 */
final class DownloadEvents
{
    public const CHANNELS = ['dashboard', 'licence_key', 'other'];

    /** A refusal before any link existed (e.g. the plan excludes the platform). */
    public static function refused(array $license, string $platform, string $variant, string $channel, string $reason): void
    {
        self::insert(null, $license, null, $platform, $variant, $channel, 'failed', $reason);
    }

    /** A link was issued; returns nothing — the token id ties later updates back. */
    public static function requested(int $tokenId, array $license, array $release, string $channel): void
    {
        self::insert($tokenId, $license, $release, (string) $release['platform'], (string) $release['variant'], $channel, 'requested', null);
    }

    /** Moves the attempt made with this token to $status. */
    public static function mark(int $tokenId, string $status, ?string $reason = null): void
    {
        Database::pdo()->prepare(
            'UPDATE download_events SET status = :status, failure_reason = :reason, updated_at = NOW() WHERE token_id = :token'
        )->execute(['status' => $status, 'reason' => $reason, 'token' => $tokenId]);
    }

    private static function insert(?int $tokenId, array $license, ?array $release, string $platform, string $variant,
                                   string $channel, string $status, ?string $reason): void
    {
        Database::pdo()->prepare(
            'INSERT INTO download_events (event_uid, token_id, customer_id, license_id, plan_id, release_id, version,
                                          platform, variant, channel, status, failure_reason, license_status, created_at)
             VALUES (:uid, :token, :customer, :license, :plan, :release, :version,
                     :platform, :variant, :channel, :status, :reason, :license_status, NOW())'
        )->execute([
            'uid'            => bin2hex(random_bytes(16)),
            'token'          => $tokenId,
            'customer'       => $license['customer_id'] ?? null,
            'license'        => $license['id'] ?? null,
            'plan'           => $license['plan_id'] ?? null,
            'release'        => $release['id'] ?? null,
            'version'        => $release['version'] ?? null,
            'platform'       => $platform,
            'variant'        => $variant === '' ? null : $variant,
            'channel'        => in_array($channel, self::CHANNELS, true) ? $channel : 'other',
            'status'         => $status,
            'reason'         => $reason,
            'license_status' => $license['status'] ?? null,
        ]);
    }
}
