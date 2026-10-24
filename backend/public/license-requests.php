<?php
/**
 * GET  /api/license-requests.php                         the customer's own requests
 * POST /api/license-requests.php  { plan, business_name?, contact_phone?, message? }
 * POST /api/license-requests.php  { action: "cancel", id }
 *
 * Asking for a plan never takes a payment and never issues a licence: the
 * request waits as `pending` until an administrator approves or rejects it
 * (admin-license-requests.php). Signed-in customers only, and only ever
 * their own requests — the customer id comes from the session, never the body.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Auth\Session;
use Diwan\License\LicenseRequestService;
use Diwan\Support\Http;

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    Http::fail('Method not allowed', 405);
}

$customerId = Session::customerId();
if ($customerId === null) {
    Http::fail('Please sign in to request a plan.', 401);
}

$requests = new LicenseRequestService();
if ($method === 'GET') {
    Http::json(['requests' => $requests->forCustomer($customerId)]);
}

$input = Http::input();
if (($input['action'] ?? '') === 'cancel') {
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        Http::fail('Invalid request id.', 422);
    }
    Http::json(['request' => $requests->cancel($customerId, $id)]);
}

$plan = strtolower(trim((string) ($input['plan'] ?? '')));
if ($plan === '') {
    Http::fail('Please choose a plan.', 422);
}
$request = $requests->submit($customerId, $plan, $input);
Http::json(['request' => [
    'id'        => (int) $request['id'],
    'status'    => $request['status'],
    'plan_code' => $request['plan_code'],
    'plan_name' => $request['plan_name'],
]], 201);
