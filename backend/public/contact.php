<?php
/**
 * POST /api/contact.php  { name, email, topic, order_ref?, message, website }
 *
 * Contact form. `website` is a honeypot: hidden from people, filled in by
 * naive bots. A filled honeypot gets the normal success response so the bot
 * learns nothing, but nothing is stored or sent.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Contact\ContactService;
use Diwan\Support\Http;

Http::requireMethod('POST');

$input = Http::input();
$thanks = "Thanks — your message is on its way. We'll reply by email within one business day.";

if (trim((string) ($input['website'] ?? '')) !== '') {
    Http::json(['message' => $thanks]);
}

try {
    (new ContactService())->submit($input, Http::clientIp());
} catch (InvalidArgumentException $e) {
    Http::fail($e->getMessage(), 422);
}

Http::json(['message' => $thanks]);
