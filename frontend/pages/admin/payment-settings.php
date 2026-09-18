<?php
require_once __DIR__ . '/../../includes/config.php';
require_admin();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_payment_settings'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Invalid CSRF token.');
        header('Location: ' . BASE_URL . '/pages/admin/payment-settings.php');
        exit;
    }

    $method = normalize_payment_method($_POST['payment_method'] ?? 'gcash');
    $qr_code_path = trim((string)($_POST['qr_code_path'] ?? ''));
    $account_name = trim((string)($_POST['account_name'] ?? ''));
    $account_number = trim((string)($_POST['account_number'] ?? ''));
    $instructions = trim((string)($_POST['instructions'] ?? ''));
    $upload_dir = __DIR__ . '/../../uploads/payment-qr';
    $selected_method = $method;

    if (!is_dir($upload_dir)) {
        @mkdir($upload_dir, 0755, true);
    }

    if (isset($_FILES['qr_image']) && is_array($_FILES['qr_image']) && $_FILES['qr_image']['error'] === UPLOAD_ERR_OK && $_FILES['qr_image']['name'] !== '') {
        $ext = strtolower(pathinfo($_FILES['qr_image']['name'], PATHINFO_EXTENSION));
        $allowed = ['png', 'jpg', 'jpeg', 'webp', 'gif'];

        if (!in_array($ext, $allowed, true)) {
            set_flash('error', 'Only PNG, JPG, WEBP, and GIF QR files are allowed.');
            header('Location: ' . BASE_URL . '/pages/admin/payment-settings.php');
            exit;
        }

        $filename = $method . '-' . time() . '.' . $ext;
        $destination = $upload_dir . '/' . $filename;

        if (!move_uploaded_file($_FILES['qr_image']['tmp_name'], $destination)) {
            set_flash('error', 'Failed to upload the QR code image.');
            header('Location: ' . BASE_URL . '/pages/admin/payment-settings.php');
            exit;
        }

        $qr_code_path = BASE_URL . '/uploads/payment-qr/' . $filename;
    }

    $fallback = payment_settings_defaults($method);
    $qr_code_path = $qr_code_path !== '' ? $qr_code_path : $fallback['qr_code_path'];
    $account_name = $account_name !== '' ? $account_name : $fallback['account_name'];
    $account_number = $account_number !== '' ? $account_number : $fallback['account_number'];
    $instructions = $instructions !== '' ? $instructions : $fallback['instructions'];

    try {
        ensure_payment_settings_table();
        $pdo = get_db_pdo();
        $stmt = $pdo->prepare('INSERT INTO payment_settings (payment_method, qr_code_path, account_name, account_number, instructions)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                qr_code_path = VALUES(qr_code_path),
                account_name = VALUES(account_name),
                account_number = VALUES(account_number),
                instructions = VALUES(instructions),
                updated_at = CURRENT_TIMESTAMP');

        $stmt->execute([
            $method,
            $qr_code_path,
            $account_name,
            $account_number,
            $instructions,
        ]);

        $_SESSION['last_payment_settings_method'] = $method;
        set_flash('success', ucfirst($method) . ' payment settings saved successfully.');
    } catch (Throwable $e) {
        error_log('payment settings save failed: ' . $e->getMessage());
        set_flash('error', 'Could not save payment settings: ' . $e->getMessage());
    }

    $redirect_method = $method ?? 'gcash';
    header('Location: ' . BASE_URL . '/pages/admin/payment-settings.php?payment_method=' . urlencode($redirect_method) . '&ts=' . time());
    exit;
}

$gcash = load_payment_settings('gcash');
$maya = load_payment_settings('maya');
$selected_method = normalize_payment_method($_GET['payment_method'] ?? ($_SESSION['last_payment_settings_method'] ?? 'gcash'));
$active_settings = $selected_method === 'maya' ? $maya : $gcash;

$page_title = 'Payment Settings — Admin — ' . APP_NAME;
include __DIR__ . '/../../includes/header.php';
?>

<div class="admin-layout">
  <?php include __DIR__ . '/../../includes/admin-sidebar.php'; ?>

  <div class="admin-main">
    <div class="admin-header">
      <h1>Payment Settings</h1>
      <p>Manage the QR codes and account details used for the manual GCash and Maya payment flow.</p>
    </div>

    <div class="admin-card mt-16">
      <div class="admin-card-header">
        <h3>Manual Payment Methods</h3>
      </div>
      <div class="admin-card-body">
        <form method="POST" enctype="multipart/form-data">
          <input type="hidden" name="save_payment_settings" value="1">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">

          <div class="form-grid">
            <div class="form-group">
              <label>Payment Method</label>
              <select name="payment_method" id="payment-method-selector" class="form-select">
                <option value="gcash" <?= $selected_method === 'gcash' ? 'selected' : '' ?>>GCash</option>
                <option value="maya" <?= $selected_method === 'maya' ? 'selected' : '' ?>>Maya</option>
              </select>
            </div>

            <div class="form-group">
              <label>Account Name</label>
              <input type="text" name="account_name" value="<?= h($active_settings['account_name']) ?>" placeholder="R&G Trading">
            </div>

            <div class="form-group">
              <label>Account Number / Details</label>
              <input type="text" name="account_number" value="<?= h($active_settings['account_number']) ?>" placeholder="09XXXXXXXXX">
            </div>

            <div class="form-group full">
              <label>QR Image Path</label>
              <input type="text" name="qr_code_path" id="qr_code_path" value="<?= h($active_settings['qr_code_path']) ?>" placeholder="/rg-trading-php/assets/img/gcash_qr.png">
              <small class="form-note">This can be a web URL or a saved upload path from the uploads/payment-qr folder.</small>
            </div>

            <div class="form-group full">
              <label>Upload QR Image</label>
              <input type="file" name="qr_image" id="qr_image_input" accept="image/*">
            </div>

            <div class="form-group full">
              <label>Instructions</label>
              <textarea name="instructions" rows="4" placeholder="Scan the QR code..."><?= h($active_settings['instructions']) ?></textarea>
            </div>
          </div>

          <div class="mt-20">
            <div class="payment-preview-box">
              <div class="small-muted mb-8">Current QR Preview</div>
              <img id="payment-qr-preview" src="<?= h($active_settings['qr_code_path']) ?>" alt="<?= $selected_method === 'maya' ? 'Maya' : 'GCash' ?> QR preview" onerror="this.onerror=null;this.src='<?= BASE_URL ?>/assets/img/payment-placeholder.png';" style="max-width:240px; width:100%; border:1px solid #dfe7f1; background:#fff; border-radius:12px; padding:12px;">
            </div>
          </div>

          <div class="mt-20">
            <button type="submit" class="btn-primary">Save Payment Settings</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
const methodSelector = document.getElementById('payment-method-selector');
const qrInput = document.getElementById('qr_code_path');
const preview = document.getElementById('payment-qr-preview');
const qrUploadInput = document.getElementById('qr_image_input');

const paymentConfig = {
  gcash: {
    accountName: '<?= h($gcash['account_name']) ?>',
    accountNumber: '<?= h($gcash['account_number']) ?>',
    qrPath: '<?= h($gcash['qr_code_path']) ?>',
    instructions: '<?= h(str_replace("'", "\\'", $gcash['instructions'])) ?>'
  },
  maya: {
    accountName: '<?= h($maya['account_name']) ?>',
    accountNumber: '<?= h($maya['account_number']) ?>',
    qrPath: '<?= h($maya['qr_code_path']) ?>',
    instructions: '<?= h(str_replace("'", "\\'", $maya['instructions'])) ?>'
  }
};

function updatePaymentFormPreview(method) {
  const config = paymentConfig[method] || paymentConfig.gcash;
  const accountNameInput = document.querySelector('input[name="account_name"]');
  const accountNumberInput = document.querySelector('input[name="account_number"]');
  const instructionsInput = document.querySelector('textarea[name="instructions"]');
  const qrPathInput = document.querySelector('input[name="qr_code_path"]');
  const placeholderPath = '<?= BASE_URL ?>/assets/img/payment-placeholder.png';

  if (accountNameInput) accountNameInput.value = config.accountName;
  if (accountNumberInput) accountNumberInput.value = config.accountNumber;
  if (instructionsInput) instructionsInput.value = config.instructions;
  if (qrPathInput) qrPathInput.value = config.qrPath;

  if (preview) {
    preview.onerror = function () {
      this.onerror = null;
      this.src = placeholderPath;
    };
    preview.src = config.qrPath || placeholderPath;
    preview.alt = method === 'maya' ? 'Maya QR preview' : 'GCash QR preview';
  }
}

if (methodSelector) {
  methodSelector.addEventListener('change', function () {
    updatePaymentFormPreview(this.value);
  });
}

if (qrInput && preview) {
  qrInput.addEventListener('input', function () {
    const value = this.value.trim();
    const placeholderPath = '<?= BASE_URL ?>/assets/img/payment-placeholder.png';
    preview.onerror = function () {
      this.onerror = null;
      this.src = placeholderPath;
    };
    preview.src = value || placeholderPath;
  });
}

if (qrUploadInput && preview) {
  qrUploadInput.addEventListener('change', function () {
    const file = this.files && this.files[0];
    if (!file) return;

    const url = URL.createObjectURL(file);
    preview.src = url;
    if (qrInput) {
      qrInput.value = '';
    }
  });
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
