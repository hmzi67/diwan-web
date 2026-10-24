<?php
/**
 * GET  /api/admin-releases.php                             builds and their download counts
 * POST /api/admin-releases.php  { action: "update", id, is_active?, release_notes? }
 * POST /api/admin-releases.php  { action: "announce", version }   notify licensed customers
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Admin\AdminGuard;
use Diwan\Admin\AdminQueries;
use Diwan\Admin\ReleaseAdmin;
use Diwan\Support\Http;

$admin = AdminGuard::require(['GET', 'POST']);
$releases = new ReleaseAdmin();
$notified = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = Http::input();
    switch ($input['action'] ?? '') {
        case 'update':
            $releases->update(AdminGuard::id($input['id'] ?? null), $input, $admin);
            break;
        case 'announce':
            $version = trim((string) ($input['version'] ?? ''));
            if ($version === '') {
                Http::fail('Choose a version.', 422);
            }
            $notified = $releases->announce($version, $admin);
            break;
        default:
            Http::fail('Unknown action.', 422);
    }
}
Http::json(['releases' => (new AdminQueries())->releases(), 'notified' => $notified]);
