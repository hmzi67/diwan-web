<?php
/**
 * The owner's back office: every record of the business's workspace, read and
 * edited from the customer dashboard. See Diwan\Workspace\OwnerWorkspace and
 * docs/MULTI-COUNTER.md §13.
 *
 * GET  ?view=context                      the owner's businesses, settings, counters, version
 * GET  ?view=head                         current version, to notice new data
 * GET  ?view=dashboard&today=             cards, alerts and sales per day
 * GET  ?view=records&entities=a,b         records of those types
 * GET  ?view=sales&from=&to=              sales in a range; ?view=sale&gid= one sale's lines
 * GET  ?view=orders                       orders still in progress
 * GET  ?view=report&name=&from=&to=       terminals | profit_loss | inventory | suppliers
 * POST { action, request_id, ... }        save | delete | adjust_stock | create_po |
 *                                         set_po_status | receive_goods | pay_supplier
 *
 * Every call may name `business`; it is honoured only when the signed-in
 * customer owns that business.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Auth\Session;
use Diwan\Support\Http;
use Diwan\Support\Logger;
use Diwan\Support\UserFacingException;
use Diwan\Workspace\OwnerReports;
use Diwan\Workspace\OwnerWorkspace;

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method !== 'GET' && $method !== 'POST') {
    Http::fail('Method not allowed', 405);
}
$customerId = Session::customerId();
if ($customerId === null) {
    Http::fail('Not logged in.', 401);
}

try {
    $input = $method === 'POST' ? Http::input() : $_GET;
    $requested = isset($input['business']) ? (int) $input['business'] : null;
    if (($input['view'] ?? '') === 'context') {
        Http::json(OwnerWorkspace::context($customerId, $requested));
    }
    $workspace = OwnerWorkspace::forCustomer($customerId, $requested);
    if ($method === 'POST') {
        Http::json($workspace->apply((string) ($input['action'] ?? ''), $input));
    }
    $reports = new OwnerReports($workspace);
    Http::json(match ((string) ($_GET['view'] ?? '')) {
        'head'      => ['version' => $workspace->version()],
        'dashboard' => $reports->dashboard((string) ($_GET['today'] ?? '')),
        'records'   => $workspace->records(explode(',', (string) ($_GET['entities'] ?? ''))),
        'sales'     => $reports->sales((string) ($_GET['from'] ?? ''), (string) ($_GET['to'] ?? '')),
        'sale'      => $reports->sale((string) ($_GET['gid'] ?? '')),
        'orders'    => $reports->ordersInProgress(),
        'report'    => $reports->report((string) ($_GET['name'] ?? ''), (string) ($_GET['from'] ?? ''), (string) ($_GET['to'] ?? '')),
        default     => throw new UserFacingException('Unknown view.'),
    });
} catch (UserFacingException $e) {
    Http::fail($e->getMessage(), 422);
} catch (Throwable $e) {
    Logger::error('business.php: ' . $e->getMessage());
    Http::fail('Something went wrong. Please try again.', 500);
}
