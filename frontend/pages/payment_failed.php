<?php
require_once __DIR__ . '/../includes/config.php';
require_login();

$order_id = $_GET['order_id'] ?? null;
$order = null;

if ($order_id) {
    // Mark the order's payment as failed via the app's real update path.
    api_update_order_status($order_id, [
        'payment_status' => 'failed',
    ], true);

    $order = api_get_order_detail($order_id);
}

$page_title = 'Payment Failed';
include __DIR__ . '/../includes/header.php';
?>

<div class="container-sm">
  <div class="page-header">
    <h1>Payment Failed</h1>
  </div>

  <div class="card-panel" style="text-align:center;">
    <div style="font-size:48px; color:#dc3545; margin-bottom:12px;">✕</div>
    <p style="font-weight:600; color:#dc3545; margin-bottom:16px;">
      <?= $order_id ? "Payment for Order #" . h($order['order_number'] ?? $order_id) . " didn't go through." : "Payment was not completed." ?>
    </p>

    <div class="confirm-section" style="text-align:left;">
      <h3 class="confirm-heading">Common reasons</h3>
      <ul style="padding-left:20px; color:#666; font-size:14px; line-height:1.6;">
        <li>Payment was cancelled before completing</li>
        <li>Test card/account declined intentionally</li>
        <li>Session or link expired</li>
      </ul>
    </div>

    <div class="d-flex gap-12 mt-20">
      <a href="<?= BASE_URL ?>/pages/orders.php" class="btn-primary flex-1">View Orders &amp; Retry Payment</a>
      <a href="<?= BASE_URL ?>/index.php" class="btn-back">Back to Products</a>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>