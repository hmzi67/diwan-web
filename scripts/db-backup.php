<?php
/**
 * Writes a gzipped SQL dump of the whole database to
 * private-storage/backups/diwan-<date>.sql.gz and keeps the newest 14.
 * Hostinger's own backups are weekly, too far apart for sales data. Nightly
 * cron:
 *
 *   php scripts/db-backup.php [--keep=14] [--env=/path/to/.env]
 *
 * Plain PHP (PDO), so it needs no mysqldump on the host. Restore with
 *   gunzip -c diwan-<date>.sql.gz | mysql -u <user> -p <database>
 * Download the files off the server now and then: a backup on the same host
 * does not survive the loss of the account.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/backend/src/bootstrap-autoload.php';

use Diwan\Config\Env;
use Diwan\Database\Database;

$envPath = dirname(__DIR__) . '/backend/config/.env';
$keep = 14;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--env=')) {
        $envPath = substr($arg, 6);
    } elseif (str_starts_with($arg, '--keep=')) {
        $keep = max(1, (int) substr($arg, 7));
    }
}
Env::load($envPath);
$dir = rtrim(Env::get('PRIVATE_STORAGE_PATH', dirname(__DIR__) . '/backend/private-storage'), '/') . '/backups';
if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
    fwrite(STDERR, "Cannot create {$dir}\n");
    exit(1);
}

$pdo = Database::pdo();
$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
$final = $dir . '/diwan-' . date('Ymd-His') . '.sql.gz';
$partial = $final . '.partial';
$gz = gzopen($partial, 'wb6');
if ($gz === false) {
    fwrite(STDERR, "Cannot write {$partial}\n");
    exit(1);
}

$value = static function (mixed $v) use ($pdo): string {
    if ($v === null) {
        return 'NULL';
    }
    if (is_int($v) || is_float($v)) {
        return (string) $v;
    }
    $v = (string) $v;
    // Binary columns (hashes, tokens) go as hex so no charset can mangle them.
    return mb_check_encoding($v, 'UTF-8') ? $pdo->quote($v) : '0x' . bin2hex($v);
};

gzwrite($gz, "-- Diwan database backup, " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
// Every table is read in one consistent snapshot.
$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
$tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
$rows = 0;
foreach ($tables as $table) {
    $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM)[1];
    gzwrite($gz, "DROP TABLE IF EXISTS `{$table}`;\n{$create};\n");
    $stmt = $pdo->query("SELECT * FROM `{$table}`");
    $batch = [];
    while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
        $batch[] = '(' . implode(',', array_map($value, $row)) . ')';
        if (count($batch) === 200) {
            gzwrite($gz, "INSERT INTO `{$table}` VALUES\n" . implode(",\n", $batch) . ";\n");
            $rows += 200;
            $batch = [];
        }
    }
    $stmt->closeCursor();
    if ($batch !== []) {
        gzwrite($gz, "INSERT INTO `{$table}` VALUES\n" . implode(",\n", $batch) . ";\n");
        $rows += count($batch);
    }
    gzwrite($gz, "\n");
}
$pdo->exec('COMMIT');
gzwrite($gz, "SET FOREIGN_KEY_CHECKS = 1;\n");
gzclose($gz);
rename($partial, $final);
chmod($final, 0640);

$old = glob($dir . '/diwan-*.sql.gz') ?: [];
rsort($old);
foreach (array_slice($old, $keep) as $file) {
    unlink($file);
}
printf("Backed up %d tables, %d rows to %s (%.1f MB). Keeping the newest %d.\n",
    count($tables), $rows, basename($final), filesize($final) / 1048576, $keep);
