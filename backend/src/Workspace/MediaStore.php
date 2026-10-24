<?php
declare(strict_types=1);

namespace Diwan\Workspace;

use Diwan\Database\Database;
use PDO;

/**
 * Product photos and the logo of a business, shared by its counters.
 *
 * - **Files, not rows.** Each file is stored once per business under
 *   private-storage/media/<business id>/<name>, outside the web root, and is
 *   only ever served to a counter of that business (pos-media.php).
 * - **Named by the counter.** The name is what the counter stores in
 *   `items.image_path` / `app_config.logo_path` (new counters name a photo by
 *   its content hash). The first upload of a name wins; a re-upload is a
 *   no-op, so a retried upload is harmless.
 * - **Checked.** A raster image must decode as the type its name says, within
 *   4096 px, and is re-encoded with GD when it is available (which drops
 *   anything hidden after the image data). An SVG logo is refused if it
 *   carries script, entities or external references. Files are sent back as
 *   `application/octet-stream` with `nosniff`, never rendered by a browser.
 * - **Bounded.** 3 MB a file and 5,000 files a business (the hosting's inode
 *   limit is shared by every customer). `collectGarbage` deletes files no
 *   record uses any more, after a grace period.
 */
final class MediaStore
{
    public const MAX_BYTES = 3 * 1024 * 1024;
    public const MAX_SVG_BYTES = 512 * 1024;
    public const MAX_FILES = 5000;
    public const MAX_CHECK = 1000;
    public const GRACE_DAYS = 7;
    private const MAX_PIXELS = 4096;
    private const NAME = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,90}\.(jpg|jpeg|png|webp|gif|svg)$/';

    private string $root;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim($root ?? (DIWAN_PRIVATE_STORAGE . '/media'), '/');
    }

    public static function validName(mixed $name): bool
    {
        return is_string($name) && preg_match(self::NAME, $name) === 1;
    }

    public function path(int $businessId, string $name): string
    {
        return $this->root . '/' . $businessId . '/' . $name;
    }

    /** Which of `names` the business does not have yet. */
    public function check(array $ctx, mixed $names): array
    {
        if (!is_array($names) || !array_is_list($names) || count($names) > self::MAX_CHECK) {
            throw new WorkspaceException('bad_request', 'Send a list of up to ' . self::MAX_CHECK . ' file names.', 422);
        }
        $missing = [];
        foreach (array_unique($names) as $name) {
            if (self::validName($name) && !is_file($this->path($ctx['business_id'], $name))) {
                $missing[] = $name;
            }
        }
        return ['missing' => $missing];
    }

    /** Stores one file. `stored` is false when the business already had it. */
    public function put(array $ctx, mixed $name, string $bytes): array
    {
        if (!self::validName($name)) {
            throw new WorkspaceException('bad_request', 'Invalid file name.', 422);
        }
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new WorkspaceException('too_large', 'An image must be at most 3 MB.', 413);
        }
        $businessId = $ctx['business_id'];
        $path = $this->path($businessId, $name);
        if (is_file($path)) {
            return ['name' => $name, 'stored' => false];
        }

        $count = (int) self::scalar('SELECT COUNT(*) FROM sync_media WHERE business_id = :b', ['b' => $businessId]);
        if ($count >= self::MAX_FILES) {
            throw new WorkspaceException('media_quota', 'This business has reached its limit of ' . self::MAX_FILES . ' images.', 409);
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $clean = $extension === 'svg' ? self::cleanSvg($bytes) : self::cleanRaster($bytes, $extension);

        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create the media directory.');
        }
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $clean) !== strlen($clean) || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Could not store the image.');
        }
        Database::pdo()->prepare(
            'INSERT INTO sync_media (business_id, name, bytes, terminal_id) VALUES (:b, :n, :bytes, :t)
             ON DUPLICATE KEY UPDATE bytes = VALUES(bytes)'
        )->execute(['b' => $businessId, 'n' => $name, 'bytes' => strlen($clean), 't' => $ctx['terminal_id']]);
        return ['name' => $name, 'stored' => true];
    }

    /** The file to send back, or a 404 refusal. */
    public function fetch(array $ctx, mixed $name): string
    {
        if (!self::validName($name)) {
            throw new WorkspaceException('bad_request', 'Invalid file name.', 422);
        }
        $path = $this->path($ctx['business_id'], $name);
        if (!is_file($path)) {
            throw new WorkspaceException('not_found', 'The business has no such image yet.', 404);
        }
        return $path;
    }

    /**
     * Deletes files that no record of their business uses and that are older
     * than the grace period (a counter may upload a photo a little before the
     * record that uses it). Run daily from cron: scripts/media-gc.php.
     *
     * @return array{deleted: int, bytes: int}
     */
    public function collectGarbage(int $graceDays = self::GRACE_DAYS, ?int $businessId = null): array
    {
        $pdo = Database::pdo();
        $sql = 'SELECT DISTINCT business_id FROM sync_media' . ($businessId === null ? '' : ' WHERE business_id = :b');
        $stmt = $pdo->prepare($sql);
        $stmt->execute($businessId === null ? [] : ['b' => $businessId]);
        $deleted = 0;
        $bytes = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $business) {
            $business = (int) $business;
            $used = array_flip($this->referenced($pdo, $business));
            $old = $pdo->prepare(
                'SELECT name, bytes FROM sync_media WHERE business_id = :b AND created_at < NOW() - INTERVAL :days DAY'
            );
            $old->bindValue('b', $business, PDO::PARAM_INT);
            $old->bindValue('days', $graceDays, PDO::PARAM_INT);
            $old->execute();
            $remove = $pdo->prepare('DELETE FROM sync_media WHERE business_id = :b AND name = :n');
            foreach ($old->fetchAll() as $file) {
                if (isset($used[$file['name']])) {
                    continue;
                }
                @unlink($this->path($business, $file['name']));
                $remove->execute(['b' => $business, 'n' => $file['name']]);
                $deleted++;
                $bytes += (int) $file['bytes'];
            }
        }
        return ['deleted' => $deleted, 'bytes' => $bytes];
    }

    /** @return array{files: int, bytes: int} */
    public static function usage(int $businessId): array
    {
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) AS files, COALESCE(SUM(bytes), 0) AS bytes FROM sync_media WHERE business_id = :b');
        $stmt->execute(['b' => $businessId]);
        $row = $stmt->fetch();
        return ['files' => (int) $row['files'], 'bytes' => (int) $row['bytes']];
    }

    /** Every file name the business's live records point at. */
    private function referenced(PDO $pdo, int $businessId): array
    {
        $stmt = $pdo->prepare(
            "SELECT JSON_UNQUOTE(JSON_EXTRACT(data, '$.image_path')) FROM sync_rows
              WHERE business_id = :b AND entity = 'items' AND deleted = 0
             UNION
             SELECT JSON_UNQUOTE(JSON_EXTRACT(data, '$.logo_path')) FROM sync_rows
              WHERE business_id = :b2 AND entity = 'app_config' AND deleted = 0"
        );
        $stmt->execute(['b' => $businessId, 'b2' => $businessId]);
        return array_values(array_filter($stmt->fetchAll(PDO::FETCH_COLUMN), static fn ($n) => is_string($n) && $n !== 'null'));
    }

    private static function cleanRaster(string $bytes, string $extension): string
    {
        $expected = match ($extension) {
            'jpg', 'jpeg' => IMAGETYPE_JPEG,
            'png'         => IMAGETYPE_PNG,
            'webp'        => IMAGETYPE_WEBP,
            'gif'         => IMAGETYPE_GIF,
        };
        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info[2] !== $expected) {
            throw new WorkspaceException('bad_image', 'The file is not a valid ' . strtoupper($extension) . ' image.', 422);
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] > self::MAX_PIXELS || $info[1] > self::MAX_PIXELS) {
            throw new WorkspaceException('bad_image', 'An image must be at most ' . self::MAX_PIXELS . ' pixels wide and high.', 422);
        }
        if (!function_exists('imagecreatefromstring')) {
            return $bytes; // no GD on this host: the header check above is all we can do
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new WorkspaceException('bad_image', 'The image could not be read.', 422);
        }
        ob_start();
        $ok = match ($expected) {
            IMAGETYPE_JPEG => imagejpeg($image, null, 85),
            IMAGETYPE_PNG  => (imagealphablending($image, false) && imagesavealpha($image, true)) ? imagepng($image, null, 6) : false,
            IMAGETYPE_WEBP => function_exists('imagewebp') && imagewebp($image, null, 85),
            IMAGETYPE_GIF  => imagegif($image),
        };
        $clean = (string) ob_get_clean();
        imagedestroy($image);
        // A host whose GD lacks WebP support keeps the checked original.
        return $ok && $clean !== '' ? $clean : $bytes;
    }

    private static function cleanSvg(string $bytes): string
    {
        if (strlen($bytes) > self::MAX_SVG_BYTES) {
            throw new WorkspaceException('too_large', 'An SVG logo must be at most 512 KB.', 413);
        }
        $lower = strtolower($bytes);
        $dangerous = preg_match('/<script|<!entity|<!doctype|<foreignobject|javascript:|\son[a-z]+\s*=|\s(?:xlink:)?href\s*=\s*["\']\s*(?!#)|<iframe|<embed|<object/', $lower) === 1;
        if ($dangerous || !str_contains($lower, '<svg')) {
            throw new WorkspaceException('bad_image', 'This SVG cannot be used as a logo. Use a PNG or JPG instead.', 422);
        }
        return $bytes;
    }

    private static function scalar(string $sql, array $params): mixed
    {
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}
