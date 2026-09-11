#!/usr/bin/env php
<?php
/**
 * Generates the Ed25519 keypair that signs licence-status payloads for the
 * desktop app. Run ONCE per key id, on a machine you trust, never on the
 * shared host (FTP-only, no shell there anyway):
 *
 *   php scripts/generate-license-signing-key.php [key-id]
 *
 * It prints three things and writes nothing:
 *
 *   1. The PRIVATE key line for backend/config/.env locally, and for the
 *      LICENSE_SIGNING_PRIVATE_KEY GitHub Secret (deploy.yml writes the
 *      server's .env from secrets). This value must never be committed,
 *      logged, or pasted anywhere else.
 *   2. The PUBLIC key as a Rust `[u8; 32]` literal, to paste into the
 *      desktop app's src-tauri/src/license/verify.rs ACCEPTED_PUBLIC_KEYS.
 *      This half is safe to expose — it can verify signatures, not make them.
 *   3. The key id, which goes in .env as LICENSE_SIGNING_KEY_ID and is the
 *      label the Rust side matches on.
 *
 * Rotation: generate a new pair with a new key id, ship an app build whose
 * ACCEPTED_PUBLIC_KEYS lists BOTH ids, then switch the server's .env to the
 * new pair. Installs that have not updated keep verifying old payloads;
 * new payloads carry the new kid. Drop the old id from the app a release
 * or two later.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/backend/src/bootstrap-autoload.php';

use Diwan\License\LicenseSigner;

$keyId = $argv[1] ?? date('Y-m');
if (!preg_match('/^[A-Za-z0-9._-]{1,32}$/', $keyId)) {
    fwrite(STDERR, "Key id must be 1-32 chars of [A-Za-z0-9._-].\n");
    exit(1);
}

$pair   = LicenseSigner::generateKeyPair();
$public = base64_decode($pair['public'], true);
$rust   = implode(', ', array_map(static fn (int $b): string => sprintf('0x%02x', $b), array_values(unpack('C*', $public))));

echo <<<TXT

== 1. PRIVATE — backend/config/.env locally + GitHub Secret LICENSE_SIGNING_PRIVATE_KEY ==
    (never commit, never log; this is the only time it is shown)

LICENSE_SIGNING_KEY_ID={$keyId}
LICENSE_SIGNING_PRIVATE_KEY={$pair['private']}

== 2. PUBLIC — desktop app, src-tauri/src/license/verify.rs ACCEPTED_PUBLIC_KEYS ==

    ("{$keyId}", [{$rust}]),

    (same key, base64, for reference: {$pair['public']})

== 3. GitHub repository VARIABLE (not secret) ==

LICENSE_SIGNING_KEY_ID={$keyId}


TXT;
