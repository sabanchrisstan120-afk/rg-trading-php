<?php
require_once __DIR__ . '/../includes/config.php';
require_login();

$order_id = $_GET['order_id'] ?? null;
$error_message = '';
$order = null;

if ($order_id) {
    // Mark the order paid. Uses the app's real update path — writes
    // directly to MySQL orders.payment_status / orders.status — not a
    // Node API route (there isn't one for this).
    $update = api_update_order_status($order_id, [
        'payment_status' => 'paid',
        'status'         => 'processing',
    ], true);

    if (!($update['body']['success'] ?? false)) {
        $error_message = $update['body']['message'] ?? 'Could not update the order after payment.';
    }

    $order = api_get_order_detail($order_id);
    if (empty($order)) {
        $error_message = $error_message ?: "We couldn't find order #{$order_id}, but your payment was recorded.";
    }
} else {
    $error_message = "No order reference was provided.";
}

$page_title = 'Payment Successful';
include __DIR__ . '/../includes/header.php';
?>

<div class="container-sm">
  <div class="page-header">
    <h1>Payment Successful</h1>
  </div>

  <div class="card-panel" style="text-align:center;">
    <div style="font-size:48px; color:#28a745; margin-bottom:12px;">✓</div>
    <p style="font-weight:600; color:#28a745; margin-bottom:16px;">
      Your test payment went through.
    </p>

    <?php if ($error_message): ?>
      <div class="flash flash-error flash-inline"><?= h($error_message) ?></div>
    <?php elseif ($order): ?>
      <div class="confirm-section" style="text-align:left;">
        <div class="confirm-row">
          <span class="confirm-label">Order Number:</span>
          <span class="confirm-value">#<?= h($order['order_number'] ?? $order_id) ?></span>
        </div>
        <div class="confirm-row">
          <span class="confirm-label">Status:</span>
          <span class="confirm-value">Processing</span>
        </div>
        <div class="confirm-row">
          <span class="confirm-label">Payment:</span>
          <span class="confirm-value">Paid (test mode)</span>
        </div>
      </div>
    <?php endif; ?>

    <div class="d-flex gap-12 mt-20">
      <a href="<?= BASE_URL ?>/pages/orders.php" class="btn-primary flex-1">View Orders</a>
      <a href="<?= BASE_URL ?>/index.php" class="btn-secondary">Back to Products</a>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>