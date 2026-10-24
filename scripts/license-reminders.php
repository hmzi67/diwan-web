<?php
/**
 * Sends "expiring soon" and "expired" notices for plan licences. Idempotent:
 * each notice goes out once per licence and expiry date, so run it as often
 * as you like — e.g. a daily cron job:
 *
 *   php scripts/license-reminders.php [--env=/path/to/.env]
 *
 * Without cron, the admin dashboard runs the same check at most hourly.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/backend/src/bootstrap-autoload.php';

use Diwan\Config\Env;
use Diwan\License\ExpiryReminders;

$envPath = dirname(__DIR__) . '/backend/config/.env';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--env=')) {
        $envPath = substr($arg, 6);
    }
}
Env::load($envPath);

$sent = (new ExpiryReminders())->run();
echo "Expiring-soon notices sent: {$sent['expiring']}\nExpired notices sent: {$sent['expired']}\n";
