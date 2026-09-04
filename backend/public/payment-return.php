<?php
/**
 * GET/POST /api/payment-return.php — where the customer's BROWSER lands after
 * paying. This is presentation only: it never grants anything, because a
 * browser redirect is trivially forged. The webhook is the source of truth.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Diwan\Order\OrderService;

$orderRef = (string) ($_REQUEST['pp_TxnRefNo'] ?? $_REQUEST['orderRefNumber'] ?? $_REQUEST['order_id'] ?? '');
$order    = $orderRef !== '' ? (new OrderService())->findByRef($orderRef) : null;

$paid = $order !== null && $order['status'] === 'paid';

header('Content-Type: text/html; charset=utf-8');
$safeRef = htmlspecialchars($orderRef, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $paid ? 'Payment received' : 'Payment pending' ?> — Diwan</title>
<link rel="stylesheet" href="/css/styles.css">
<main style="padding:4rem 1.5rem;max-width:640px;margin:0 auto">
<?php if ($paid): ?>
  <h1>Payment received</h1>
  <p>Your licence key is on its way to <strong><?= htmlspecialchars((string) $order['customer_email'], ENT_QUOTES, 'UTF-8') ?></strong>.</p>
  <p>Order reference: <code><?= $safeRef ?></code></p>
  <p><a class="btn" href="/#download">Go to downloads</a></p>
<?php else: ?>
  <h1>Payment is still processing</h1>
  <p>We have not had confirmation from the payment gateway yet. This usually
     takes under a minute. Your licence email will arrive as soon as it clears.</p>
  <?php if ($safeRef !== ''): ?><p>Order reference: <code><?= $safeRef ?></code></p><?php endif; ?>
  <p>If nothing arrives within 30 minutes, contact support with the reference above.</p>
<?php endif; ?>
</main>
