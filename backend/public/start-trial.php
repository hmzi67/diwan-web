<?php
/** POST /api/start-trial.php — explicit, idempotent trial start after authentication. */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Auth\Session;
use Diwan\Database\Database;
use Diwan\Support\Http;
use Diwan\Trial\TrialService;

Http::requireMethod('POST');
$customerId = Session::customerId();
if ($customerId === null) {
    Http::fail('Please sign in to start your trial.', 401);
}

$result = Database::transaction(static fn (PDO $pdo): array =>
    (new TrialService())->ensureForCustomer($pdo, $customerId)
);
Http::json($result);
