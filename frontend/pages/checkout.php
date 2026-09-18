<?php
require_once __DIR__ . '/../includes/config.php';
require_login();

$product_id = $_GET['product_id'] ?? '';
$qty        = max(1, intval($_GET['qty'] ?? 1));
$error      = '';
$step       = 'form'; // 'form' or 'confirm'

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

// Variables to hold form data
$form_data = [
    'quantity'          => $qty,
    'payment_method'    => 'gcash',
    'payment_reference' => '',
    'notes'             => '',
    'phone'             => '',
    'use_saved_address' => false,
    'street'            => '',
    'city'              => '',
    'province'          => '',
    'zip'               => '',
    'address'           => null,
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_data['quantity']           = max(1, intval($_POST['quantity'] ?? 1));
    $form_data['payment_method']     = in_array($_POST['payment_method'] ?? 'gcash', ['gcash', 'maya'], true)
        ? $_POST['payment_method']
        : 'gcash';
    $form_data['payment_reference'] = trim((string)($_POST['payment_reference'] ?? ''));
    $form_data['notes']              = trim((string)($_POST['notes'] ?? ''));
    $form_data['phone']              = trim((string)($_POST['phone'] ?? ''));
    $form_data['use_saved_address']  = isset($_POST['use_saved_address']) && $saved_address;

    // Get address (from saved or manual)
    if ($form_data['use_saved_address']) {
        $form_data['address'] = $saved_address;
    } else {
        $form_data['street']    = trim($_POST['street'] ?? '');
        $form_data['city']      = trim($_POST['city'] ?? '');
        $form_data['province']  = trim($_POST['province'] ?? '');
        $form_data['zip']       = trim($_POST['zip'] ?? '');

        $form_data['address'] = [
            'street'   => $form_data['street'],
            'city'     => $form_data['city'],
            'province' => $form_data['province'],
            'zip'      => $form_data['zip'],
        ];
    }

    // Check if this is final confirmation step
    if (isset($_POST['step']) && $_POST['step'] === 'confirm') {
        if (empty($form_data['address']['street']) || empty($form_data['address']['city']) ||
            empty($form_data['address']['province']) || empty($form_data['address']['zip'])) {
            $error = 'Please complete all address fields.';
        } elseif (empty($form_data['phone'])) {
            $error = 'Please provide a phone number for delivery contact.';
        } elseif ($form_data['payment_reference'] === '') {
            $error = 'Please enter the payment reference number.';
        } else {
            $payload = [
                'items' => [['product_id' => $product_id, 'quantity' => $form_data['quantity']]],
                'payment_method' => $form_data['payment_method'],
                'payment_reference' => $form_data['payment_reference'],
                'contact_number' => $form_data['phone'],
                'address' => $form_data['address'],
                'phone' => $form_data['phone'],
                'status' => 'pending',
                'payment_status' => 'pending',
            ];

            if (!empty($form_data['notes'])) {
                $payload['notes'] = $form_data['notes'];
            }

            $order_result = api_request('POST', '/orders', $payload, true);

            if (($order_result['status'] ?? 0) === 201) {
                $order = $order_result['body']['data']['order'] ?? [];
                $order_id = (string)($order['id'] ?? '');
                if ($order_id !== '') {
                    remember_order_update($order_id, [
                        'payment_reference' => $form_data['payment_reference'],
                        'payment_method' => $form_data['payment_method'],
                        'payment_status' => 'pending',
                        'contact_number' => $form_data['phone'],
                        'phone' => $form_data['phone'],
                        'customer_phone' => $form_data['phone'],
                    ]);
                }

                $order_num = $order['order_number'] ?? 'N/A';
                set_flash('success', "Order #{$order_num} placed successfully. Payment is pending verification.");
                header('Location: ' . BASE_URL . '/pages/orders.php');
                exit;
            }

            $fallback_result = create_order_direct($payload, $product, $form_data['quantity']);
            if (($fallback_result['status'] ?? 0) === 201) {
                $order = $fallback_result['body']['data']['order'] ?? [];
                $order_id = (string)($order['id'] ?? '');
                if ($order_id !== '') {
                    remember_order_update($order_id, [
                        'payment_reference' => $form_data['payment_reference'],
                        'payment_method' => $form_data['payment_method'],
                        'payment_status' => 'pending',
                        'contact_number' => $form_data['phone'],
                        'phone' => $form_data['phone'],
                        'customer_phone' => $form_data['phone'],
                    ]);
                }

                $order_num = $order['order_number'] ?? 'N/A';
                set_flash('success', "Order #{$order_num} placed successfully. Payment is pending verification.");
                header('Location: ' . BASE_URL . '/pages/orders.php');
                exit;
            }

            $error = $fallback_result['body']['message'] ?? ($order_result['body']['message'] ?? 'Failed to place order. Please try again.');
        }
    } elseif (isset($_POST['step']) && $_POST['step'] === 'form') {
        // Validate and show confirmation page
        if (empty($form_data['address']['street']) || empty($form_data['address']['city']) ||
            empty($form_data['address']['province']) || empty($form_data['address']['zip'])) {
            $error = 'Please complete all address fields.';
        } else {
            $step = 'confirm';
        }
    }
}

// Pre-fill form fields for display
$use_saved_checked = !isset($_POST['use_saved_address']) && $saved_address;
$post_street   = h($_POST['street']   ?? '');
$post_city     = h($_POST['city']     ?? '');
$post_province = h($_POST['province'] ?? '');
$post_zip      = h($_POST['zip']      ?? '');
$post_phone    = h($_POST['phone']    ?? '');

$page_title = 'Order — ' . h($product['name']);
include __DIR__ . '/../includes/header.php';
?>

<?php
// Retrieve authoritative shipping fee for display
$shipping_fee = get_setting_float('shipping_fee', 500.00);
$shipping_fee_display = ($shipping_fee !== null && $shipping_fee > 0) ? format_price($shipping_fee) : 'FREE';
$subtotal_calc = $product['price'] * $qty;
?>

<div class="container-sm">
  <div class="page-header">
    <h1><?= $step === 'form' ? 'Place Order' : 'Review Order' ?></h1>
    <p><a href="<?= BASE_URL ?>/index.php" class="btn-link">← Back to Products</a></p>
  </div>

  <?php if ($error): ?>
    <div class="flash flash-error flash-inline"><?= h($error) ?></div>
  <?php endif; ?>

  <!-- Product Summary (always visible) -->
  <div class="checkout-card">
    <div class="muted-meta"><?= h($product['brand']) ?></div>
    <div class="product-title"><?= h($product['name']) ?></div>
    <div class="model-text">Model: <?= h($product['model_number']) ?></div>
    <div class="row-between">
      <span class="price-large"><?= format_price($product['price']) ?></span>
      <span class="stock-small">In stock: <?= $product['stock_qty'] ?> units</span>
    </div>
  </div>

  <?php if ($step === 'form'): ?>
    <!-- Order Form - Step 1 -->
    <div class="card-panel">
      <form method="POST">
        <input type="hidden" name="step" value="form">

        <div class="form-group">
          <label>Quantity</label>
          <input type="number" name="quantity" value="<?= $qty ?>" min="1" max="<?= $product['stock_qty'] ?>" required>
        </div>

        <div class="form-group">
          <label>Payment Method</label>
          <select name="payment_method" aria-label="Payment Method">
            <option value="gcash" <?= $form_data['payment_method'] === 'gcash' ? 'selected' : '' ?>>GCash</option>
            <option value="maya" <?= $form_data['payment_method'] === 'maya' ? 'selected' : '' ?>>Maya</option>
          </select>
        </div>

        <div class="form-group">
          <div class="confirm-section">
            <h3 class="confirm-heading" id="payment-method-label"><?= h(manual_payment_config($form_data['payment_method'])['label']) ?> QR Code</h3>
            <div class="payment-qr-wrapper" style="text-align:center; margin:12px 0;">
              <img id="payment-qr-image" src="<?= h(manual_payment_config($form_data['payment_method'])['qr']) ?>" alt="<?= h(manual_payment_config($form_data['payment_method'])['label']) ?> QR code" style="max-width:260px; width:100%; border:1px solid #dfe7f1; background:#fff; border-radius:12px; padding:12px;">
            </div>
            <div id="payment-instructions" class="small-muted" style="line-height:1.6;">
              <strong><?= h(manual_payment_config($form_data['payment_method'])['account_name']) ?></strong><br>
              <?= h(manual_payment_config($form_data['payment_method'])['account_number']) ?><br><br>
              <?= h(manual_payment_config($form_data['payment_method'])['instructions']) ?>
            </div>
          </div>
        </div>

        <div class="form-group">
          <label for="payment_reference">Payment Reference Number <span class="text-danger">*</span></label>
          <input type="text" id="payment_reference" name="payment_reference" value="<?= h($form_data['payment_reference']) ?>" placeholder="Enter <?= h(manual_payment_config($form_data['payment_method'])['label']) ?> payment reference" required>
        </div>

        <!-- Delivery Address -->
        <div class="mb-18">
          <label class="form-label-strong">Delivery Address</label>

          <?php if ($saved_address): ?>
            <!-- Saved address option -->
            <div class="address-box">
              <label class="address-label">
                <input type="checkbox" name="use_saved_address" id="use_saved_address" <?= $use_saved_checked ? 'checked' : '' ?> class="mt-3" onchange="toggleAddressFields(this)">
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
          <label class="form-label">Phone Number <span class="text-danger">*</span></label>
          <input type="tel" name="phone" value="<?= $post_phone ?>" placeholder="e.g. 09123456789" pattern="[0-9+\-\s()]*" required>
          <div class="small-muted mt-6">We'll share this with the delivery rider so they can contact you.</div>
        </div>

        <div class="form-group">
          <label>Notes <span class="small-muted">(optional)</span></label>
          <textarea name="notes" rows="3" placeholder="Special instructions for delivery..." class="form-textarea"><?= h($_POST['notes'] ?? '') ?></textarea>
        </div>

        <!-- Order Summary -->
        <div class="summary-card">
          <div class="summary-row"><span class="muted-meta">Quantity</span><span><?= $qty ?> unit<?= $qty !== 1 ? 's' : '' ?></span></div>
          <div class="summary-row"><span class="muted-meta">Subtotal</span><span id="subtotal"><?= format_price($subtotal_calc) ?></span></div>
          <div class="summary-row"><span class="muted-meta">Shipping</span><span class="text-success"><?= $shipping_fee_display ?></span></div>
          <div class="summary-row total"><span>Total</span><span id="total"><?= format_price($subtotal_calc + ($shipping_fee ?? 0.0)) ?></span></div>
        </div>

        <button type="submit" class="btn-primary">Review Order</button>
      </form>
    </div>

  <?php else: ?>
    <!-- Order Confirmation - Step 2 -->
    <div class="card-panel">
      <h2 class="text-large text-ice-strong mb-24">Order Summary</h2>

      <!-- Quantity & Product -->
      <div class="confirm-section">
        <h3 class="confirm-heading">Order Details</h3>
        <div class="confirm-row">
          <span class="confirm-label">Product:</span>
          <span class="confirm-value"><?= h($product['name']) ?></span>
        </div>
        <div class="confirm-row">
          <span class="confirm-label">Model:</span>
          <span class="confirm-value"><?= h($product['model_number']) ?></span>
        </div>
        <div class="confirm-row">
          <span class="confirm-label">Quantity:</span>
          <span class="confirm-value"><?= $form_data['quantity'] ?> unit<?= $form_data['quantity'] !== 1 ? 's' : '' ?></span>
        </div>
        <div class="confirm-row">
          <span class="confirm-label">Unit Price:</span>
          <span class="confirm-value"><?= format_price($product['price']) ?></span>
        </div>
      </div>

      <!-- Delivery Address -->
      <div class="confirm-section">
        <h3 class="confirm-heading">Delivery Address</h3>
        <div class="confirm-row">
          <span class="confirm-label">Street:</span>
          <span class="confirm-value"><?= h($form_data['address']['street']) ?></span>
        </div>
        <div class="confirm-row">
          <span class="confirm-label">City/Municipality:</span>
          <span class="confirm-value"><?= h($form_data['address']['city']) ?></span>
        </div>
        <div class="confirm-row">
          <span class="confirm-label">Province:</span>
          <span class="confirm-value"><?= h($form_data['address']['province']) ?></span>
        </div>
        <div class="confirm-row">
          <span class="confirm-label">ZIP Code:</span>
          <span class="confirm-value"><?= h($form_data['address']['zip']) ?></span>
        </div>
        <div class="confirm-row">
          <span class="confirm-label">Phone Number:</span>
          <span class="confirm-value"><?= h($form_data['phone']) ?></span>
        </div>
      </div>

      <!-- Payment & Notes -->
      <div class="confirm-section">
        <h3 class="confirm-heading">Payment & Notes</h3>
        <div class="confirm-row">
          <span class="confirm-label">Payment Method:</span>
          <span class="confirm-value"><?= ucwords(str_replace('_', ' ', $form_data['payment_method'])) ?></span>
        </div>
        <div class="confirm-row">
          <span class="confirm-label">Payment Reference:</span>
          <span class="confirm-value"><?= h($form_data['payment_reference']) ?></span>
        </div>
        <?php if (!empty($form_data['notes'])): ?>
        <div class="confirm-row">
          <span class="confirm-label">Special Instructions:</span>
          <span class="confirm-value"><?= h($form_data['notes']) ?></span>
        </div>
        <?php endif; ?>
      </div>

      <!-- Price Breakdown -->
      <div class="summary-card">
        <?php $confirm_subtotal = $product['price'] * $form_data['quantity']; ?>
        <div class="summary-row"><span class="muted-meta">Subtotal</span><span><?= format_price($confirm_subtotal) ?></span></div>
        <div class="summary-row"><span class="muted-meta">Shipping</span><span class="text-success"><?php $confirm_shipping = get_setting_float('shipping_fee', 500.00); echo ($confirm_shipping !== null && $confirm_shipping > 0) ? format_price($confirm_shipping) : 'FREE'; ?></span></div>

      <!-- Hidden form fields to preserve data -->
      <form method="POST" id="confirmForm">
        <input type="hidden" name="step" value="confirm">
        <input type="hidden" name="quantity" value="<?= $form_data['quantity'] ?>">
        <input type="hidden" name="payment_method" value="<?= h($form_data['payment_method']) ?>">
        <input type="hidden" name="payment_reference" value="<?= h($form_data['payment_reference']) ?>">
        <input type="hidden" name="notes" value="<?= h($form_data['notes']) ?>">
        <input type="hidden" name="phone" value="<?= h($form_data['phone']) ?>">
        <?php if ($form_data['use_saved_address']): ?>
          <input type="hidden" name="use_saved_address" value="1">
        <?php else: ?>
          <input type="hidden" name="street" value="<?= h($form_data['address']['street']) ?>">
          <input type="hidden" name="city" value="<?= h($form_data['address']['city']) ?>">
          <input type="hidden" name="province" value="<?= h($form_data['address']['province']) ?>">
          <input type="hidden" name="zip" value="<?= h($form_data['address']['zip']) ?>">
        <?php endif; ?>

        <div class="d-flex gap-12 mt-20">
          <button type="button" class="btn-secondary" onclick="history.back()">← Edit Details</button>
          <button type="submit" class="btn-primary flex-1">Place Order</button>
        </div>
      </form>
    </div>

  <?php endif; ?>
</div>

<script>
function toggleAddressFields(checkbox) {
    document.getElementById('address-fields').style.display = checkbox.checked ? 'none' : 'block';
}

const paymentMethodSelect = document.querySelector('select[name="payment_method"]');
const paymentQrImage = document.getElementById('payment-qr-image');
const paymentLabel = document.getElementById('payment-method-label');
const paymentInstructions = document.getElementById('payment-instructions');
const paymentReferenceInput = document.getElementById('payment_reference');

if (paymentMethodSelect && paymentQrImage && paymentLabel && paymentInstructions) {
    const paymentConfig = {
        gcash: {
            label: 'GCash',
            qr: '<?= h(manual_payment_config('gcash')['qr']) ?>',
            accountName: '<?= h(manual_payment_config('gcash')['account_name']) ?>',
            accountNumber: '<?= h(manual_payment_config('gcash')['account_number']) ?>',
            instructions: '<?= h(manual_payment_config('gcash')['instructions']) ?>'
        },
        maya: {
            label: 'Maya',
            qr: '<?= h(manual_payment_config('maya')['qr']) ?>',
            accountName: '<?= h(manual_payment_config('maya')['account_name']) ?>',
            accountNumber: '<?= h(manual_payment_config('maya')['account_number']) ?>',
            instructions: '<?= h(manual_payment_config('maya')['instructions']) ?>'
        }
    };

    function updatePaymentUI() {
        const method = paymentMethodSelect.value;
        const config = paymentConfig[method] || paymentConfig.gcash;
        paymentQrImage.src = config.qr;
        paymentQrImage.alt = config.label + ' QR code';
        paymentLabel.textContent = config.label + ' QR Code';
        paymentInstructions.innerHTML = '<strong>' + config.accountName + '</strong><br>' + config.accountNumber + '<br><br>' + config.instructions;
        if (paymentReferenceInput) {
            paymentReferenceInput.placeholder = 'Enter ' + config.label + ' payment reference';
            paymentReferenceInput.setAttribute('aria-label', config.label + ' payment reference');
        }
    }

    paymentMethodSelect.addEventListener('change', updatePaymentUI);
    updatePaymentUI();
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>