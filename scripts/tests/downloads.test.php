<?php
declare(strict_types=1);

use Diwan\Download\DownloadDenied;
use Diwan\Download\DownloadService;

/** A local installer file in private storage plus its active release row. */
function fixtureRelease(string $platform = 'windows', string $variant = ''): int
{
    $version = TestKit::RELEASE_PREFIX . bin2hex(random_bytes(4));
    $file    = "{$version}.exe";
    file_put_contents(DIWAN_PRIVATE_STORAGE . "/releases/{$file}", 'installer bytes');
    TestKit::exec(
        'INSERT INTO releases (platform, variant, version, filename, storage_path, source, is_active, released_at)
         VALUES (:platform, :variant, :version, :filename, :path, "local", 1, DATE_ADD(NOW(), INTERVAL 1 DAY))',
        ['platform' => $platform, 'variant' => $variant, 'version' => $version, 'filename' => "Diwan-{$file}", 'path' => $file]
    );
    return (int) TestKit::scalar('SELECT id FROM releases WHERE version = :v', ['v' => $version]);
}

/** A licence id with downloads available, plus a token for it. */
function issuedDownload(): array
{
    fixtureRelease();
    [$order] = TestKit::paidLicense(TestKit::customer());
    $licenseId = (int) TestKit::scalar('SELECT id FROM licenses WHERE order_id = :id', ['id' => $order['id']]);
    $issued = (new DownloadService())->issueToken($licenseId, 'windows', TestKit::ip());
    return [$licenseId, $issued['token']];
}

function deniedWith(int $status, callable $redeem, string $message): void
{
    try {
        $redeem();
    } catch (DownloadDenied $e) {
        TestKit::same($status, $e->status, $message);
        return;
    }
    throw new TestFailure("{$message} — the download was allowed");
}

return [
    'a token redeems to the installer once, and counts the download' => static function (): void {
        [$licenseId, $token] = issuedDownload();
        $target = (new DownloadService())->redeem($token, TestKit::ip());
        TestKit::check(is_file((string) $target['path']), 'resolves to the file in private storage');
        TestKit::same(1, (int) TestKit::scalar('SELECT downloads_used FROM licenses WHERE id = :id', ['id' => $licenseId]), 'downloads counted');
        deniedWith(410, static fn () => (new DownloadService())->redeem($token, TestKit::ip()), 'second use');
    },

    'an unknown token is refused' => static function (): void {
        deniedWith(404, static fn () => (new DownloadService())->redeem(str_repeat('0', 64), TestKit::ip()), 'unknown token');
    },

    'an expired token is refused and not burned' => static function (): void {
        [$licenseId, $token] = issuedDownload();
        TestKit::exec('UPDATE download_tokens SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE license_id = :id', ['id' => $licenseId]);
        deniedWith(410, static fn () => (new DownloadService())->redeem($token, TestKit::ip()), 'expired token');
        TestKit::same(0, (int) TestKit::scalar('SELECT downloads_used FROM licenses WHERE id = :id', ['id' => $licenseId]), 'nothing counted');
    },

    'a revoked or expired licence cannot redeem an issued token' => static function (): void {
        [$licenseId, $token] = issuedDownload();
        TestKit::exec('UPDATE licenses SET status = "revoked" WHERE id = :id', ['id' => $licenseId]);
        deniedWith(403, static fn () => (new DownloadService())->redeem($token, TestKit::ip()), 'revoked licence');

        [$licenseId, $token] = issuedDownload();
        TestKit::exec('UPDATE licenses SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = :id', ['id' => $licenseId]);
        deniedWith(403, static fn () => (new DownloadService())->redeem($token, TestKit::ip()), 'expired licence');
    },

    'the download quota is enforced at redemption' => static function (): void {
        [$licenseId, $token] = issuedDownload();
        TestKit::exec('UPDATE licenses SET downloads_used = max_downloads WHERE id = :id', ['id' => $licenseId]);
        deniedWith(403, static fn () => (new DownloadService())->redeem($token, TestKit::ip()), 'quota used up');
    },

    'a missing installer file costs the customer nothing' => static function (): void {
        [$licenseId, $token] = issuedDownload();
        TestKit::exec(
            'UPDATE releases r JOIN download_tokens t ON t.release_id = r.id SET r.storage_path = "missing.exe" WHERE t.license_id = :id',
            ['id' => $licenseId]
        );
        deniedWith(503, static fn () => (new DownloadService())->redeem($token, TestKit::ip()), 'missing file');
        TestKit::same(null, TestKit::scalar('SELECT used_at FROM download_tokens WHERE license_id = :id', ['id' => $licenseId]), 'token not burned');
    },

    'storage paths cannot escape private storage' => static function (): void {
        [$licenseId, $token] = issuedDownload();
        TestKit::exec(
            'UPDATE releases r JOIN download_tokens t ON t.release_id = r.id SET r.storage_path = "../test.env" WHERE t.license_id = :id',
            ['id' => $licenseId]
        );
        deniedWith(503, static fn () => (new DownloadService())->redeem($token, TestKit::ip()), 'path traversal');
    },

    'link requests are limited to 10 per IP per hour' => static function (): void {
        $service = new DownloadService();
        $ip = TestKit::ip();
        for ($i = 0; $i < 10; $i++) {
            TestKit::check($service->allowAttempt($ip), "attempt " . ($i + 1) . ' allowed');
        }
        TestKit::check(!$service->allowAttempt($ip), 'the 11th is refused');
        TestKit::check($service->allowAttempt(TestKit::ip()), 'another IP is unaffected');
    },

    'a requested build wins, with the platform-wide build as fallback' => static function (): void {
        $intel = fixtureRelease('macos', 'intel');
        [$order] = TestKit::paidLicense(TestKit::customer());
        $licenseId = (int) TestKit::scalar('SELECT id FROM licenses WHERE order_id = :id', ['id' => $order['id']]);
        $service = new DownloadService();
        $service->issueToken($licenseId, 'macos', TestKit::ip(), 'intel');
        TestKit::same($intel, (int) TestKit::scalar(
            'SELECT release_id FROM download_tokens WHERE license_id = :id ORDER BY id DESC LIMIT 1', ['id' => $licenseId]
        ), 'intel build chosen');
        TestKit::throws(RuntimeException::class, static fn () => $service->issueToken($licenseId, 'android', TestKit::ip()),
            'a platform with no release has nothing to issue', 'No active release');
    },
];
