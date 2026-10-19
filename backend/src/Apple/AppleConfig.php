<?php
declare(strict_types=1);

namespace Diwan\Apple;

use Diwan\Config\Env;

/**
 * Every Apple-related setting, read from .env in one place. See the "Apple"
 * block in .env.example and docs/APPLE.md for where each value comes from.
 */
final class AppleConfig
{
    /**
     * App Store product id → our products.sku. The prices customers pay on
     * iOS are set in App Store Connect, not here; the SKU only decides which
     * licence tier the purchase is recorded against.
     */
    private const DEFAULT_PRODUCTS = [
        'com.diwan.pos.license.starter'    => 'diwan-pos-starter',
        'com.diwan.pos.license.standard'   => 'diwan-pos-standard',
        'com.diwan.pos.license.enterprise' => 'diwan-pos-enterprise',
    ];

    /** The iOS app's bundle id — must equal tauri.conf.json's `identifier`. */
    public static function bundleId(): string
    {
        return Env::get('APPLE_BUNDLE_ID', 'com.diwan.pos');
    }

    /**
     * The app's numeric Apple ID (App Store Connect → App Information).
     * Production transactions and notifications carry it; null until the app
     * exists in App Store Connect, in which case only the bundle id is checked.
     */
    public static function appAppleId(): ?int
    {
        $id = Env::int('APPLE_APP_ID', 0);
        return $id > 0 ? $id : null;
    }

    /**
     * Sandbox covers Xcode/TestFlight builds AND App Review, which always
     * purchases in the sandbox even for a production build — so this stays
     * true in production unless you have a reason to lock review out.
     */
    public static function allowSandbox(): bool
    {
        return Env::bool('APPLE_IAP_ALLOW_SANDBOX', true);
    }

    /** @return array<string,string> product id → sku */
    public static function products(): array
    {
        $raw = Env::get('APPLE_IAP_PRODUCTS');
        if ($raw === null) {
            return self::DEFAULT_PRODUCTS;
        }
        $map = [];
        foreach (explode(',', $raw) as $pair) {
            [$productId, $sku] = array_map('trim', explode('=', $pair, 2) + [1 => '']);
            if ($productId !== '' && $sku !== '') {
                $map[$productId] = $sku;
            }
        }
        return $map;
    }

    public static function skuFor(string $productId): ?string
    {
        return self::products()[$productId] ?? null;
    }

    /** Services ID used by the website's Sign in with Apple button; null = web sign-in off. */
    public static function servicesId(): ?string
    {
        return Env::get('APPLE_SIGN_IN_SERVICES_ID');
    }

    public static function webRedirectUri(): string
    {
        return Env::get(
            'APPLE_SIGN_IN_REDIRECT_URI',
            Env::appUrl('/auth/login.html')
        );
    }

    /**
     * Identity-token audiences we accept: the native app signs in as its
     * bundle id, the website as its Services ID.
     *
     * @return list<string>
     */
    public static function signInAudiences(): array
    {
        return array_values(array_filter([self::bundleId(), self::servicesId()]));
    }

    /**
     * Team id + key id + .p8 for Sign in with Apple's REST API (code
     * exchange and token revocation on account deletion). Optional: without
     * them sign-in still works, but deleting an account can't revoke Apple's
     * token, which App Review requires for the iOS app.
     *
     * @return array{team_id:string, key_id:string, private_key:string}|null
     */
    public static function signInKey(): ?array
    {
        $teamId = Env::get('APPLE_TEAM_ID');
        $keyId  = Env::get('APPLE_SIGN_IN_KEY_ID');
        $key    = Env::get('APPLE_SIGN_IN_PRIVATE_KEY');
        if ($teamId === null || $keyId === null || $key === null) {
            return null;
        }
        // .env values are single-line, so the .p8 is stored base64-encoded
        // whole; a raw PEM pasted with literal "\n"s is accepted too.
        $pem = str_contains($key, 'BEGIN') ? str_replace('\n', "\n", $key) : base64_decode($key, true);
        if (!is_string($pem) || !str_contains($pem, 'PRIVATE KEY')) {
            return null;
        }
        return ['team_id' => $teamId, 'key_id' => $keyId, 'private_key' => $pem];
    }

    /** Days between self-service moves of an App Store licence to a new device. */
    public static function restoreMoveCooldownDays(): int
    {
        return max(0, Env::int('APPLE_RESTORE_MOVE_COOLDOWN_DAYS', 30));
    }
}
