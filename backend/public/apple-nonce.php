<?php
/**
 * GET /api/apple-nonce.php
 *
 * Step one of Sign in with Apple, for the website and the app alike: a
 * fresh signed nonce (see Diwan\Apple\SignInNonce) plus what the website's
 * button needs to initialise Apple's JS — so the static site carries no
 * Apple config of its own and the button hides itself when web sign-in is
 * not configured on this server.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Apple\AppleConfig;
use Diwan\Apple\IdentityToken;
use Diwan\Apple\SignInNonce;
use Diwan\Support\Http;

Http::requireMethod('GET');

Http::json([
    'nonce' => SignInNonce::issue(),
    'web'   => IdentityToken::webEnabled()
        ? ['enabled' => true, 'client_id' => AppleConfig::servicesId(), 'redirect_uri' => AppleConfig::webRedirectUri()]
        : ['enabled' => false],
]);
