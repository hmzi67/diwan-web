<?php
/**
 * POST /api/pos-terminal-register.php
 *   { license_key, machine_fingerprint, install_id, terminal_name?, platform?, app_version? }
 *
 * Connects this device — which must already hold an active slot of the
 * licence (activate-license.php) — to its licence's business workspace as a
 * counter, and returns the counter's token (shown once; only its hash is
 * kept). Re-registering the same device rotates the token. See
 * docs/MULTI-COUNTER.md.
 *
 * Shares activate-license.php's rate limit (ActivationThrottle): the licence
 * key is a credential here too.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Database\Database;
use Diwan\License\ActivationThrottle;
use Diwan\Support\DeviceInput;
use Diwan\Support\Http;
use Diwan\Workspace\Api;
use Diwan\Workspace\TerminalRegistry;

Http::requireMethod('POST');

$input = Http::input();
$key = trim((string) ($input['license_key'] ?? ''));
if ($key === '') {
    Http::fail('Please provide a licence key.', 422);
}
$fingerprint = DeviceInput::fingerprint($input);
(new ActivationThrottle(Database::pdo(), Http::clientIp(), ActivationThrottle::prefixOf($key)))->enforce();

Api::run(static fn (): array => (new TerminalRegistry())->register(
    $key,
    DeviceInput::fingerprintHash($fingerprint),
    (string) ($input['install_id'] ?? ''),
    isset($input['terminal_name']) && is_string($input['terminal_name']) && trim($input['terminal_name']) !== '' ? $input['terminal_name'] : null,
    isset($input['platform']) && is_string($input['platform']) ? $input['platform'] : null,
    isset($input['app_version']) && is_string($input['app_version']) ? $input['app_version'] : null,
));
