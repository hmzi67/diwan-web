<?php
declare(strict_types=1);

namespace Diwan\Support;

/**
 * Device fields the desktop and iOS apps send with a licence call
 * (activate-license.php, license-status.php, apple-iap-verify.php).
 */
final class DeviceInput
{
    /**
     * The app sends sha256(machine id), hex-encoded — never the raw id.
     * Responds 422 and exits for anything else.
     */
    public static function fingerprint(array $input): string
    {
        $fingerprint = strtolower(trim((string) ($input['machine_fingerprint'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1) {
            Http::fail('Invalid device fingerprint.', 422);
        }
        return $fingerprint;
    }

    /** What is stored: a hash of the fingerprint, so the DB never holds the app's value. */
    public static function fingerprintHash(string $fingerprint): string
    {
        return hash('sha256', $fingerprint);
    }

    /** Printable ASCII, at most 64 chars, or null when absent. */
    public static function hint(array $input): ?string
    {
        $hint = trim((string) ($input['machine_hint'] ?? ''));
        return $hint !== '' ? substr((string) preg_replace('/[^\x20-\x7E]/', '', $hint), 0, 64) : null;
    }
}
