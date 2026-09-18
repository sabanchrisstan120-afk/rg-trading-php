<?php
require_once __DIR__ . '/../includes/config.php';
require_login();

/* =========================
   SUBMIT REVIEW
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {

    // Only customers can submit reviews — admins are not allowed to review products.
    if (is_admin()) {
        set_flash('error', 'Admins are not allowed to submit reviews.');
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

   $payload = [
    'product_id' => trim($_POST['product_id']),
    'rating'     => intval($_POST['rating']),
    'comment'    => trim($_POST['comment'])
];



   
    $review_result = api_request(
        'POST',
        '/reviews',
        $payload,
        true
    );

    if (($review_result['status'] ?? 500) === 201) {

        set_flash('success', 'Review submitted successfully.');

    } else {

        set_flash(
            'error',
            $review_result['body']['message'] ?? 'Failed to submit review.'
        );
    }

    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

/* =========================
   HANDLE CANCEL ORDER
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_order_id'])) {
    $order_id = trim($_POST['cancel_order_id']);

    $cancel_result = api_request(
        'POST',
        '/orders/' . $order_id . '/cancel',
        [],
        true
    );

    if (($cancel_result['status'] ?? 500) === 200) {
        set_flash('success', 'Order cancelled successfully.');
    } else {
        set_flash('error', $cancel_result['body']['message'] ?? 'Could not cancel order.');
    }

    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

/* =========================
   FETCH ORDERS
========================= */
$status  = $_GET['status'] ?? '';
$page    = max(1, intval($_GET['page'] ?? 1));
$limit   = 10;
$user    = current_user();
$user_id = $user['id'] ?? null;

$endpoint = is_admin() ? '/orders/admin' : '/orders/my-orders';

$query_params = ['page' => $page, 'limit' => $limit];
if ($status !== '') {
    $query_params['status'] = $status;
}
if (!is_admin() && $user_id) {
    $query_params['user_id'] = $user_id;
}

$params = http_build_query($query_params);
$result = api_request('GET', $endpoint . '?' . $params, [], true);

if ((!is_admin()) && (($result['status'] ?? 0) >= 400)) {
    $fallback_result = api_request('GET', '/orders?' . $params, [], true);
    if (($fallback_result['status'] ?? 0) >= 200 && ($fallback_result['status'] ?? 0) < 400) {
        $result = $fallback_result;
    }
}

$orders     = apply_order_state_overrides($result['body']['data']['orders'] ?? $result['body']['orders'] ?? []);
$pagination = $result['body']['data']['pagination'] ?? $result['body']['pagination'] ?? [];
$total_pages = $pagination['total_pages'] ?? ceil(($pagination['total'] ?? 0) / $limit);

/* =========================
   PAGE
========================= */
$page_title = 'My Orders — ' . APP_NAME;
include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <h1>My Orders</h1>
  <p>Track and manage your aircon orders</p>
</div>

<!-- Status Filter -->
<div class="d-flex gap-8 flex-wrap mb-20">
  <?php
  $statuses = [
    '' => 'All',
    'pending' => 'Pending',
    'confirmed' => 'Confirmed',
    'processing' => 'Processing',
    'shipped' => 'Shipped',
    'delivered' => 'Delivered',
    'cancelled' => 'Cancelled',
    'refunded' => 'Refunded',
  ];
  foreach ($statuses as $val => $label):
  ?>
    <a href="?status=<?= urlencode($val) ?>"
       class="btn-pill <?= $status === $val ? 'btn-pill-active' : '' ?>">
      <?= h($label) ?>
    </a>
  <?php endforeach; ?>
</div>

<?php if (empty($orders)): ?>
  <div class="empty-state">
    <div class="icon">📦</div>
    <p>No orders found. <a href="<?= BASE_URL ?>/index.php" class="link-accent">Browse products</a></p>
  </div>
<?php else: ?>
  <div class="orders-table">
    <table>
      <thead>
        <tr>
          <th>Order #</th>
          <th>Date</th>
          <th>Total</th>
          <th>Status</th>
          <th>Payment</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($orders as $order): ?>
          <tr>
            <td><strong><?= h($order['order_number'] ?? '-') ?></strong></td>

            <td>
              <?= !empty($order['ordered_at'])
                ? date('M d, Y', strtotime($order['ordered_at']))
                : '-' ?>
            </td>

            <td>
              <strong><?= format_price($order['total_amount'] ?? 0) ?></strong>
            </td>

            <td>
              <span class="badge badge-<?= h(order_status_badge_class($order['status'] ?? 'pending')) ?>">
                <?= h(order_status_label($order['status'] ?? 'pending')) ?>
              </span>
            </td>

            <td>
              <span class="badge badge-<?= h($order['payment_status'] ?? 'pending') ?>">
                <?= h(ucfirst(str_replace('_', ' ', $order['payment_status'] ?? 'Pending'))) ?>
              </span>
            </td>

            <td class="d-flex items-center gap-6">
  <a href="<?= BASE_URL ?>/pages/order-detail.php?id=<?= h($order['id']) ?>">
    <button class="btn-sm btn-sm-blue">View</button>
  </a>

  <!-- CANCEL (ONLY PENDING) -->
  <?php if (in_array(strtolower($order['status'] ?? ''), ['pending'], true)): ?>
    <form method="POST" class="inline-form" onsubmit="return confirm('Cancel this order?')">
      <input type="hidden" name="cancel_order_id" value="<?= h($order['id']) ?>">
      <button type="submit" class="btn-sm btn-sm-red">Cancel</button>
    </form>
  <?php endif; ?>

  <?php $is_reviewable = in_array(strtolower($order['status'] ?? ''), ['delivered'], true) || !empty($order['delivered_at']); ?>
  <?php if (!is_admin() && $is_reviewable): ?>
    <button type="button"
            class="btn-sm btn-sm-green"
            onclick="openReviewModal('<?= h(trim($order['items'][0]['product_id'] ?? '')) ?>')">
      ⭐ Review
    </button>
  <?php endif; ?>

</td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- PAGINATION -->
  <?php if ($total_pages > 1): ?>
    <div class="pagination mt-20">
      <?php for ($i = 1; $i <= $total_pages; $i++): ?>
        <?php if ($i === $page): ?>
          <span class="active"><?= $i ?></span>
        <?php else: ?>
          <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>">
            <?= $i ?>
          </a>
        <?php endif; ?>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>
<?php if (!is_admin()): ?>
<div id="reviewModal" class="modal-fixed hidden">

  <div class="card-panel max-w-350 p-20">

    <h3>Write Review</h3>

    <form method="POST">

      <input type="hidden" name="submit_review" value="1">

      <!-- IMPORTANT: product_id will be filled by JS -->
      <input type="hidden" name="product_id" id="review_product_id">

      <label>Rating</label>
      <select name="rating" required class="form-select w-full">
        <option value="5">⭐⭐⭐⭐⭐</option>
        <option value="4">⭐⭐⭐⭐</option>
        <option value="3">⭐⭐⭐</option>
        <option value="2">⭐⭐</option>
        <option value="1">⭐</option>
      </select>

      <br><br>

      <label>Comment</label>
      <textarea name="comment" required class="form-textarea w-full"></textarea>

      <br><br>

      <button type="submit" class="btn-primary">Submit Review</button>
      <button type="button" class="btn-secondary" onclick="closeReviewModal()">Close</button>

    </form>

  </div>

</div>
<script>
function openReviewModal(productId) {
    document.getElementById('review_product_id').value = productId;
    document.getElementById('reviewModal').classList.remove('hidden');
    document.getElementById('reviewModal').classList.add('open');
}

function closeReviewModal() {
    document.getElementById('reviewModal').classList.remove('open');
    document.getElementById('reviewModal').classList.add('hidden');
}
</script>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>