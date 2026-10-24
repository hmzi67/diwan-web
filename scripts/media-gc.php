<?php
/**
 * Deletes product photos and logos that no record of their business uses any
 * more (a photo was replaced, a product deleted), once they are older than
 * the grace period. Keeps the hosting's file (inode) count down. Daily cron:
 *
 *   php scripts/media-gc.php [--days=7] [--env=/path/to/.env]
 *
 * See Diwan\Workspace\MediaStore and docs/MULTI-COUNTER.md.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/backend/src/bootstrap-autoload.php';

use Diwan\Config\Env;
use Diwan\Workspace\MediaStore;

$envPath = dirname(__DIR__) . '/backend/config/.env';
$days = MediaStore::GRACE_DAYS;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--env=')) {
        $envPath = substr($arg, 6);
    } elseif (str_starts_with($arg, '--days=')) {
        $days = max(1, (int) substr($arg, 7));
    }
}
Env::load($envPath);
define('DIWAN_PRIVATE_STORAGE', rtrim(Env::get('PRIVATE_STORAGE_PATH', dirname(__DIR__) . '/backend/private-storage'), '/'));

$result = (new MediaStore())->collectGarbage($days);
printf("Unused images deleted: %d (%.1f MB)\n", $result['deleted'], $result['bytes'] / 1048576);
