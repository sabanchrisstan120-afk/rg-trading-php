<?php
require_once __DIR__ . '/../../includes/config.php';
require_admin();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_shipping_fee'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Invalid CSRF token.');
        header('Location: ' . BASE_URL . '/pages/admin/shipping-settings.php');
        exit;
    }

    $raw = trim((string)($_POST['shipping_fee'] ?? ''));
    if ($raw === '') {
        set_flash('error', 'Shipping fee cannot be empty.');
        header('Location: ' . BASE_URL . '/pages/admin/shipping-settings.php');
        exit;
    }

    // Normalize decimal separator and validate numeric
    if (!is_numeric($raw)) {
        set_flash('error', 'Invalid shipping fee. Enter a non-negative number with up to two decimal places.');
        header('Location: ' . BASE_URL . '/pages/admin/shipping-settings.php');
        exit;
    }

    $value = (float)$raw;
    if ($value < 0) {
        set_flash('error', 'Shipping fee cannot be negative.');
        header('Location: ' . BASE_URL . '/pages/admin/shipping-settings.php');
        exit;
    }

    // Limit to a reasonable maximum (e.g., 1,000,000.00)
    if ($value > 1000000) {
        set_flash('error', 'Shipping fee is unreasonably large.');
        header('Location: ' . BASE_URL . '/pages/admin/shipping-settings.php');
        exit;
    }

    $stored = number_format($value, 2, '.', '');
    $ok = set_setting('shipping_fee', $stored);
    if ($ok) {
        set_flash('success', 'Shipping fee updated successfully.');
    } else {
        set_flash('error', 'Could not save shipping fee.');
    }

    header('Location: ' . BASE_URL . '/pages/admin/shipping-settings.php');
    exit;
}

$current = get_setting('shipping_fee', '500.00');
$page_title = 'Shipping Settings — Admin — ' . APP_NAME;
include __DIR__ . '/../../includes/header.php';
?>

<div class="admin-layout">
  <?php include __DIR__ . '/../../includes/admin-sidebar.php'; ?>

  <div class="admin-main">
    <div class="admin-header">
      <h1>Shipping Settings</h1>
      <p>Configure the site-wide shipping fee applied to new orders. Existing orders retain their recorded shipping fee.</p>
    </div>

    <div class="admin-card mt-16">
      <div class="admin-card-header">
        <h3>Shipping Fee</h3>
      </div>
      <div class="admin-card-body">
        <form method="POST">
          <input type="hidden" name="save_shipping_fee" value="1">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">

          <div class="form-grid">
            <div class="form-group">
              <label>Current Shipping Fee</label>
              <div class="form-note"><?= ($current !== null && is_numeric($current) && (float)$current === 0.0) ? 'FREE' : (is_numeric($current) ? format_price((float)$current) : h($current)) ?></div>
            </div>

            <div class="form-group full">
              <label>New Shipping Fee (₱)</label>
              <input type="text" name="shipping_fee" value="<?= h($current) ?>" placeholder="e.g. 500.00">
              <small class="form-note">Enter a non-negative decimal value. Use 0.00 for free shipping.</small>
            </div>
          </div>

          <div class="mt-20">
            <button type="submit" class="btn-primary">Save Shipping Fee</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
