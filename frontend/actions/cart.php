<?php
require_once __DIR__ . '/../includes/config.php';
require_login();

$product_id = $_GET['product_id'] ?? $_POST['product_id'] ?? '';

// Validate product id
if (empty($product_id)) {
  set_flash('error', 'No product selected.');
  header('Location: ' . BASE_URL . '/index.php');
  exit;
}

$qty        = max(1, intval($_GET['qty'] ?? 1));
$error      = '';

// Fetch product details
$result  = api_request('GET', '/products/' . urlencode($product_id));

$product = $result['body']['data']['product'] ?? null;

if (!$product) {
  set_flash('error', 'Product not found.');
  header('Location: ' . BASE_URL . '/index.php');
  exit;
}

// Fetch saved address from the authenticated user profile
$profile = get_authenticated_profile();
$saved_address = $profile['address'] ?? null;
// Expected shape: ['street' => '', 'city' => '', 'province' => '', 'zip' => '']

// Handle order submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $qty = max(1, intval($_POST['quantity'] ?? 1));
    $payment_method = in_array($_POST['payment_method'] ?? 'gcash', ['gcash', 'maya'], true)
        ? $_POST['payment_method']
        : 'gcash';
    $payment_reference = trim((string)($_POST['payment_reference'] ?? ''));
    $notes = trim($_POST['notes'] ?? '');
    $phone = trim((string)($_POST['phone'] ?? ''));
    if ($phone === '' && !empty($profile['phone'])) {
        $phone = trim((string)$profile['phone']);
    }
    $use_saved = isset($_POST['use_saved_address']) && $saved_address;

    if ($use_saved) {
        $address = $saved_address;
    } else {
        $address = [
            'street' => trim($_POST['street'] ?? ''),
            'city' => trim($_POST['city'] ?? ''),
            'province' => trim($_POST['province'] ?? ''),
            'zip' => trim($_POST['zip'] ?? ''),
        ];
    }

    if (empty($address['street']) || empty($address['city']) || empty($address['province']) || empty($address['zip'])) {
        $error = 'Please complete all address fields.';
    } elseif ($phone === '') {
        $error = 'Please provide a phone number for delivery contact.';
    } elseif ($payment_reference === '') {
        $error = 'Please enter the payment reference number.';
    } else {
        $payload = [
            'items' => [['product_id' => $product_id, 'quantity' => $qty]],
            'payment_method' => $payment_method,
            'payment_reference' => $payment_reference,
            'contact_number' => $phone,
            'phone' => $phone,
            'customer_phone' => $phone,
            'address' => $address,
            'status' => 'pending',
            'payment_status' => 'pending',
        ];
        if (!empty($notes)) $payload['notes'] = $notes;

        $order_result = api_request('POST', '/orders', $payload, true);

        if (($order_result['status'] ?? 0) === 201) {
          $order = $order_result['body']['data']['order'] ?? [];
          $order_id = (string)($order['id'] ?? '');
          if ($order_id !== '') {
              remember_order_update($order_id, [
                  'payment_reference' => $payment_reference,
                  'payment_method' => $payment_method,
                  'payment_status' => 'pending',
                  'contact_number' => $phone,
                  'phone' => $phone,
                  'customer_phone' => $phone,
              ]);
          }
          $order_num = $order['order_number'] ?? 'N/A';
          set_flash('success', "Order #{$order_num} placed successfully. Payment is pending verification.");
          header('Location: ' . BASE_URL . '/pages/orders.php');
          exit;
        }

        $fallback_result = create_order_direct($payload, $product, $qty);
        if (($fallback_result['status'] ?? 0) === 201) {
          $order = $fallback_result['body']['data']['order'] ?? [];
          $order_id = (string)($order['id'] ?? '');
          if ($order_id !== '') {
              remember_order_update($order_id, [
                  'payment_reference' => $payment_reference,
                  'payment_method' => $payment_method,
                  'payment_status' => 'pending',
                  'contact_number' => $phone,
                  'phone' => $phone,
                  'customer_phone' => $phone,
              ]);
          }
          $order_num = $order['order_number'] ?? 'N/A';
          set_flash('success', "Order #{$order_num} placed successfully. Payment is pending verification.");
          header('Location: ' . BASE_URL . '/pages/orders.php');
          exit;
        }

        $error = $fallback_result['body']['message'] ?? ($order_result['body']['message'] ?? 'Failed to place order. Please try again.');
    }
}

// Determine what to pre-fill in the manual fields
$use_saved_checked = !isset($_POST['use_saved_address']) && $saved_address; // default: use saved if available
$post_street   = h($_POST['street']   ?? '');
$post_city     = h($_POST['city']     ?? '');
$post_province = h($_POST['province'] ?? '');
$post_zip      = h($_POST['zip']      ?? '');

$page_title = 'Order — ' . h($product['name']);
include __DIR__ . '/../includes/header.php';
?>

<div class="container-sm">
  <div class="page-header">
    <h1>Place Order</h1>
    <p><a href="<?= BASE_URL ?>/index.php" class="btn-link">← Back to Products</a></p>
  </div>

  <?php if ($error): ?>
    <div class="flash flash-error flash-inline"><?= h($error) ?></div>
  <?php endif; ?>

  <!-- Product Summary -->
  <div class="card">
    <div class="muted-meta"><?= h($product['brand']) ?></div>
    <div class="product-title"><?= h($product['name']) ?></div>
    <div class="model-text">Model: <?= h($product['model_number']) ?></div>
    <div class="row-between">
      <span class="price-large"><?= format_price($product['price']) ?></span>
      <span class="stock-small">In stock: <?= $product['stock_qty'] ?> units</span>
    </div>
  </div>

  <!-- Order Form -->
  <div class="card-lg">
    <form method="POST">
      <div class="form-group">
        <label>Quantity</label>
        <input type="number" name="quantity" value="<?= $qty ?>" min="1" max="<?= $product['stock_qty'] ?>" required>
      </div>

      <div class="form-group">
        <label>Payment Method</label>
        <select name="payment_method">
          <option value="gcash">GCash</option>
          <option value="maya">Maya</option>
        </select>
      </div>

      <div class="form-group">
        <label>Payment Reference Number <span class="text-danger">*</span></label>
        <input type="text" name="payment_reference" placeholder="Enter payment reference number" required>
      </div>

      <div class="form-group">
        <label>Phone Number <span class="text-danger">*</span></label>
        <input type="tel" name="phone" value="<?= h($profile['phone'] ?? '') ?>" placeholder="e.g. 09123456789" pattern="[0-9+\-\s()]*" required>
      </div>

      <!-- Delivery Address -->
      <div class="mb-18">
        <label class="label-strong">Delivery Address</label>

        <?php if ($saved_address): ?>
          <!-- Saved address option -->
          <div class="address-box">
            <label class="address-label">
              <input type="checkbox" name="use_saved_address" id="use_saved_address"
                     <?= $use_saved_checked ? 'checked' : '' ?>
                     class="mt-3"
                     onchange="toggleAddressFields(this)">
              <div>
                <div class="address-strong">Use saved address</div>
                <div class="small-muted">
                  <?= h($saved_address['street']) ?><br>
                  <?= h($saved_address['city']) ?>, <?= h($saved_address['province']) ?> <?= h($saved_address['zip']) ?>
                </div>
              </div>
            </label>
          </div>
        <?php endif; ?>

        <!-- Manual address fields -->
        <div id="address-fields" class="<?= ($use_saved_checked) ? 'hidden' : '' ?>">
          <div class="form-group">
            <label class="form-label">Street / House No.</label>
            <input type="text" name="street" value="<?= $post_street ?>" placeholder="e.g. 123 Rizal St., Brgy. San Jose">
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">City / Municipality</label>
              <input type="text" name="city" value="<?= $post_city ?>" placeholder="e.g. Iloilo City">
            </div>
            <div class="form-group">
              <label class="form-label">Province</label>
              <input type="text" name="province" value="<?= $post_province ?>" placeholder="e.g. Iloilo">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">ZIP Code</label>
            <input type="text" name="zip" value="<?= $post_zip ?>" placeholder="e.g. 5000" maxlength="10">
          </div>
        </div>
      </div>

      <div class="form-group">
        <label>Notes <span class="small-muted">(optional)</span></label>
        <textarea name="notes" rows="3" placeholder="Special instructions for delivery..." class="form-textarea"><?= h($_POST['notes'] ?? '') ?></textarea>
      </div>

      <!-- Order Summary -->
      <div class="summary-card">
        <?php $cart_subtotal = $product['price']; $cart_shipping = get_setting_float('shipping_fee', 500.00); ?>
        <div class="summary-row"><span class="muted-meta">Subtotal</span><span id="subtotal"><?= format_price($cart_subtotal) ?></span></div>
        <div class="summary-row"><span class="muted-meta">Shipping</span><span class="text-success"><?= ($cart_shipping !== null && $cart_shipping > 0) ? format_price($cart_shipping) : 'FREE' ?></span></div>
        <div class="summary-row total"><span>Total</span><span id="total"><?= format_price($cart_subtotal + ($cart_shipping ?? 0.0)) ?></span></div>
      </div>

      <button type="submit" class="btn-primary">Place Order</button>
    </form>
  </div>
</div>

<script>
function toggleAddressFields(checkbox) {
    document.getElementById('address-fields').style.display = checkbox.checked ? 'none' : 'block';
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>