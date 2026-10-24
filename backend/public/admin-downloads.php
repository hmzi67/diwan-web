<?php
/**
 * GET /api/admin-downloads.php?from=&to=&plan=&platform=&version=&status=&channel=&q=&page=
 * GET /api/admin-downloads.php?...&format=csv     the same filters as a CSV file
 *
 * "Downloads" in the summary are attempts that reached the installer
 * (served or completed); requested-but-unused links and refusals are counted
 * separately and never reported as downloads.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Admin\AdminGuard;
use Diwan\Admin\AdminQueries;
use Diwan\Support\AuditLog;
use Diwan\Support\Http;

$admin = AdminGuard::require(['GET']);
$queries = new AdminQueries();

if (($_GET['format'] ?? '') !== 'csv') {
    Http::json($queries->downloads($_GET));
}

$rows = $queries->downloadsForExport($_GET);
AuditLog::record('admin', (int) $admin['id'], (string) $admin['email'], 'downloads.export_csv', null, null,
    ['rows' => count($rows), 'filters' => array_intersect_key($_GET, array_flip(['from', 'to', 'plan', 'platform', 'version', 'status', 'channel']))]);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="diwan-downloads-' . date('Y-m-d') . '.csv"');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$out = fopen('php://output', 'w');
fputcsv($out, ['event_id', 'time', 'status', 'failure_reason', 'platform', 'build', 'version', 'channel', 'licence_status', 'customer_email', 'plan']);
foreach ($rows as $r) {
    // A leading =,+,- or @ would run as a formula in a spreadsheet.
    $safe = array_map(static fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) === 1 ? "'" . $v : $v, [
        $r['event_uid'], $r['created_at'], $r['status'], $r['failure_reason'], $r['platform'], $r['variant'],
        $r['version'], $r['channel'], $r['license_status'], $r['email'], $r['plan_name'],
    ]);
    fputcsv($out, $safe);
}
fclose($out);
exit;
