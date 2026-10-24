<?php
/**
 * GET  /api/admin-settings.php                 settings + read-only deployment status
 * POST /api/admin-settings.php  { settings: { key: value, ... } }
 *
 * Only the keys AdminSettings defines can be written. The deployment status
 * (mail, payments, trials) comes from the server's .env and is shown, never
 * editable from the browser.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Admin\AdminGuard;
use Diwan\Admin\AdminSettings;
use Diwan\Config\Env;
use Diwan\Database\Database;
use Diwan\Support\AuditLog;
use Diwan\Support\Http;
use Diwan\Support\Mailer;

$admin = AdminGuard::require(['GET', 'POST']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settings = Http::input()['settings'] ?? null;
    if (!is_array($settings) || $settings === []) {
        Http::fail('Nothing to save.', 422);
    }
    Database::transaction(static function (PDO $pdo) use ($settings, $admin): void {
        $saved = [];
        foreach ($settings as $key => $value) {
            $saved[$key] = AdminSettings::set((string) $key, $value, (int) $admin['id'], $pdo);
        }
        AuditLog::record('admin', (int) $admin['id'], (string) $admin['email'], 'settings.update', null, null, $saved, $pdo);
    });
}

$definitions = [];
foreach (AdminSettings::DEFINITIONS as $key => [$type, , $min, $max, $label]) {
    $definitions[$key] = ['type' => $type, 'min' => $min, 'max' => $max, 'label' => $label];
}
Http::json([
    'settings'    => AdminSettings::all(),
    'definitions' => $definitions,
    'environment' => [
        'mail_configured'      => Mailer::isConfigured(),
        'mail_driver'          => Env::get('MAIL_DRIVER', 'sendmail'),
        'payments_enabled'     => Env::bool('PAYMENTS_ENABLED', false),
        'trial_signup_enabled' => Env::bool('TRIAL_SIGNUP_ENABLED', false),
        'max_offline_days'     => Env::int('LICENSE_MAX_OFFLINE_DAYS', 30),
        'app_env'              => Env::get('APP_ENV', 'local'),
    ],
]);
