<?php
/**
 * GET /api/products.php
 *
 * The editions on sale, straight from the products table, so the checkout
 * page shows the price checkout.php will actually charge instead of a copy
 * kept in JavaScript. Public: it is the same information as the pricing page.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Order\OrderService;
use Diwan\Support\Http;

Http::requireMethod('GET');

Http::json(['products' => (new OrderService())->activeProducts()]);
