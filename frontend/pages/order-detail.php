<?php
require_once __DIR__ . '/../includes/config.php';
require_login();

$id     = $_GET['id'] ?? '';
$result = api_request('GET', '/orders/' . urlencode($id), [], true);
$order  = $result['body']['data']['order'] ?? null;
if ($order && isset($order['id'])) {
    $order = array_merge($order, get_order_state_store()[(string) $order['id']] ?? []);
}

if (!$order) {
    set_flash('error', 'Order not found.');
    header('Location: ' . BASE_URL . '/pages/orders.php'); exit;
}

// Order status steps
$current_status = $order['status'];
$is_cancelled   = $current_status === 'cancelled';

function step_state(string $step, string $current, bool $cancelled): string {
    if ($cancelled) return $step === $current ? 'cancelled' : 'done';
    $steps = ['pending','confirmed','processing','shipped','delivered'];
    $cur_i = array_search($current, $steps);
    $step_i = array_search($step, $steps);
    if ($cur_i === false || $step_i === false) {
        return '';
    }
    if ($step_i < $cur_i) return 'done';
    if ($step_i === $cur_i) return 'active';
    return '';
}

$page_title = 'Order ' . $order['order_number'] . ' — ' . APP_NAME;
include __DIR__ . '/../includes/header.php';
?>

<div class="main-content max-w-800">
  <div class="page-header mb-24">
    <a href="<?= BASE_URL ?>/pages/orders.php" class="btn-back">← Back to Orders</a>
    <h1>Order #<?= h($order['order_number']) ?></h1>
    <p class="text-muted">View and manage your order details below</p>
  </div>

  <!-- Status Timeline -->
  <?php if (!$is_cancelled): ?>
  <div class="admin-card mb-24">
    <div class="admin-card-body p-22">
      <div class="status-timeline">
        <?php
        $steps = ['pending','confirmed','processing','shipped','delivered'];
        $icons = ['pending'=>'📝','confirmed'=>'✅','processing'=>'⚙️','shipped'=>'🚚','delivered'=>'🏠'];
        foreach ($steps as $s):
          $state = step_state($s, $current_status, $is_cancelled);
        ?>
          <div class="timeline-step <?= $state ?>">
            <div class="timeline-dot"><?= $icons[$s] ?></div>
            <div class="timeline-label"><?= ucfirst($s) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php else: ?>
  <div class="alert-cancelled">
    <span class="text-large">✕</span> This order was cancelled.
  </div>
  <?php endif; ?>

  <!-- Summary Row -->
  <div class="grid-auto-200 mb-26">
    <?php $info = [
      'Order Status'   => order_status_label($order['status'] ?? 'pending'),
      'Payment Status' => payment_status_label($order['payment_status'] ?? 'pending'),
      'Payment Method' => ucwords(str_replace('_', ' ', $order['payment_method'] ?? 'N/A')),
      'Payment Reference' => !empty($order['payment_reference']) ? h($order['payment_reference']) : '—',
      'Date Ordered'   => date('M d, Y', strtotime($order['ordered_at'])),
      'Expected Delivery' => !empty($order['expected_delivery_date']) 
        ? date('M d, Y', strtotime($order['expected_delivery_date'])) 
        : '—',
    ]; foreach ($info as $label => $val): ?>
      <div class="info-card">
        <div class="info-card-label"><?= h($label) ?></div>
        <div class="info-card-value"><?= h($val) ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php
    $payment_status = strtolower((string)($order['payment_status'] ?? 'pending'));
    $payment_message = 'Your payment is currently pending verification.';
    if ($payment_status === 'paid') {
        $payment_message = 'Payment has been verified successfully.';
    } elseif ($payment_status === 'failed') {
        $payment_message = 'Payment verification was rejected. Please contact support or submit a valid reference.';
    }
  ?>
  <div class="admin-card mb-24">
    <div class="admin-card-header"><h3>Payment Verification</h3></div>
    <div class="admin-card-body">
      <div class="confirm-row"><span class="confirm-label">Payment Status:</span><span class="confirm-value"><?= h(payment_status_label($order['payment_status'] ?? 'pending')) ?></span></div>
      <div class="confirm-row"><span class="confirm-label">Payment Reference:</span><span class="confirm-value"><?= !empty($order['payment_reference']) ? h($order['payment_reference']) : '—' ?></span></div>
      <p class="text-muted mt-12"><?= h($payment_message) ?></p>
    </div>
  </div>

  <!-- Delivery Details -->
  <div class="admin-card mb-24">
    <div class="admin-card-header"><h3>Delivery Information</h3></div>
    <div class="admin-card-body grid-gap-14">
      <div>
        <div class="info-card-label">Delivery Status</div>
        <div class="info-card-value">
          <?= h(ucfirst(str_replace('_', ' ', $order['delivery_status'] ?? 'Not available'))) ?>
        </div>
      </div>
      <?php if (!empty($order['delivery_issue_type']) || !empty($order['delivery_note'])): ?>
        <div>
          <div class="info-card-label">Issue / Note</div>
          <div class="info-card-value text-95">
            <?= h($order['delivery_issue_type'] ?? '') ?>
            <?= !empty($order['delivery_issue_type']) && !empty($order['delivery_note']) ? ' - ' : '' ?>
            <?= h($order['delivery_note'] ?? '') ?>
          </div>
        </div>
      <?php endif; ?>
      <?php if (!empty($order['delivery_proof_url'])): ?>
        <div>
          <div class="info-card-label">Proof</div>
          <div><a href="<?= BASE_URL ?>/pages/proof.php?path=<?= urlencode($order['delivery_proof_url']) ?>" target="_blank" class="btn-back">🔍 View proof image</a></div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Items -->
  <div class="admin-card mb-24">
    <div class="admin-card-header"><h3>Items Ordered</h3></div>
    <table class="data-table table-no-margin">
      <thead>
        <tr>
          <th>Product</th>
          <th class="text-center">Qty</th>
          <th class="text-right">Unit Price</th>
          <th class="text-right">Total</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($order['items'] ?? [] as $item): ?>
          <tr>
            <td>
              <div class="font-strong text-strong"><?= h($item['product_name']) ?></div>
              <div class="text-muted text-small">Model: <?= h($item['model_number']) ?></div>
            </td>
            <td class="text-center"><?= $item['quantity'] ?></td>
            <td class="text-right"><?= format_price($item['unit_price']) ?></td>
            <td class="text-right font-strong"><?= format_price($item['total_price']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div class="order-summary-panel">
      <div class="order-summary-row"><span>Subtotal</span><span><?= format_price($order['subtotal']) ?></span></div>
      <div class="order-summary-row"><span>Shipping</span><span><?= $order['shipping_fee'] > 0 ? format_price($order['shipping_fee']) : 'FREE' ?></span></div>
      <div class="order-summary-row total"><span>Total Amount</span><span><?= format_price($order['total_amount']) ?></span></div>
    </div>
  </div>

  <!-- Cancel button (ONLY PENDING) -->
  <?php if (in_array($order['status'] ?? '', ['pending'], true)): ?>
    <form method="POST" action="<?= BASE_URL ?>/pages/orders.php"
          onsubmit="return confirm('Cancel this order?');">
      <input type="hidden" name="cancel_order_id" value="<?= h($order['id']) ?>">
      <button type="submit" class="btn-danger-outline">
        ✕ Cancel Order
      </button>
    </form>
  <?php endif; ?>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>