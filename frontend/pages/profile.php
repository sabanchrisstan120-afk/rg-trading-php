<?php
require_once __DIR__ . '/../includes/config.php';
require_login();

$user = get_authenticated_profile();
$tab  = $_GET['tab'] ?? 'info';
$msg  = '';

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'update_profile') {
    $payload = [
        'first_name' => trim($_POST['first_name']),
        'last_name'  => trim($_POST['last_name']),
        'phone'      => trim($_POST['phone']) ?: null,
    ];
    $res = api_request('PUT', '/auth/me', $payload, true);
    if ($res['status'] === 200) {
        $_SESSION['user'] = array_merge($user, $payload);
        $user = $_SESSION['user'];
        set_flash('success', 'Profile updated successfully.');
    } else {
        set_flash('error', $res['body']['message'] ?? 'Update failed.');
    }
    header('Location: /rg-trading-php/pages/profile.php?tab=info'); exit;
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'change_password') {
    if ($_POST['new_password'] !== $_POST['confirm_password']) {
        set_flash('error', 'New passwords do not match.');
        header('Location: /rg-trading-php/pages/profile.php?tab=security'); exit;
    }
    $res = api_request('PUT', '/auth/change-password', [
        'current_password' => $_POST['current_password'],
        'new_password'     => $_POST['new_password'],
    ], true);
    set_flash($res['status'] === 200 ? 'success' : 'error',
              $res['status'] === 200 ? 'Password changed successfully.' : ($res['body']['message'] ?? 'Failed.'));
    header('Location: /rg-trading-php/pages/profile.php?tab=security'); exit;
}

// Fetch recent orders
$orders_res = api_request('GET', '/orders/my-orders?limit=5', [], true);
if (($orders_res['status'] ?? 0) >= 400) {
    $fallback_orders = api_request('GET', '/orders?limit=5', [], true);
    if (($fallback_orders['status'] ?? 0) >= 200 && ($fallback_orders['status'] ?? 0) < 400) {
        $orders_res = $fallback_orders;
    }
}
$orders     = $orders_res['body']['data']['orders'] ?? $orders_res['body']['orders'] ?? [];

$page_title = 'My Profile — ' . APP_NAME;
include __DIR__ . '/../includes/header.php';
?>

<div class="main-content">
  <div class="page-header">
    <h1>My Account</h1>
    <p>Manage your profile and view your order history</p>
  </div>

  <div class="profile-grid">
    <div class="profile-sidebar">
      <div class="profile-avatar">
        <?= strtoupper(substr($user['first_name'] ?? 'U', 0, 1)) ?>
      </div>
      <div class="profile-name"><?= h(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?></div>
      <div class="profile-email"><?= h($user['email'] ?? '') ?></div>
      <div class="profile-menu">
        <a href="?tab=info"     class="<?= $tab==='info'?'active':'' ?>">Personal Info</a>
        <a href="?tab=orders"   class="<?= $tab==='orders'?'active':'' ?>">My Orders</a>
        <a href="?tab=security" class="<?= $tab==='security'?'active':'' ?>">Security</a>
      </div>
    </div>

    <div class="profile-content">

      <?php if ($tab === 'info'): ?>
        <h2>Personal Information</h2>
        <form method="POST">
          <input type="hidden" name="action" value="update_profile">
          <div class="form-row mb-40">
            <div class="form-group">
              <label>First Name</label>
              <input type="text" name="first_name" value="<?= h($user['first_name'] ?? '') ?>" required>
            </div>
            <div class="form-group">
              <label>Last Name</label>
              <input type="text" name="last_name" value="<?= h($user['last_name'] ?? '') ?>" required>
            </div>
          </div>
          <div class="form-group">
            <label>Email Address</label>
            <input type="email" value="<?= h($user['email'] ?? '') ?>" disabled class="input-disabled">
            <small class="small-muted">Email cannot be changed.</small>
          </div>
          <div class="form-group">
            <label>Phone Number</label>
            <input type="text" name="phone" value="<?= h($user['phone'] ?? '') ?>" placeholder="+63 9XX XXX XXXX">
          </div>
          <div class="form-group">
            <label>Account Role</label>
            <input type="text" value="<?= h(ucfirst($user['role'] ?? '')) ?>" disabled class="input-disabled">
          </div>
          <button type="submit" class="btn-primary mt-22">Save Changes</button>
        </form>

      <?php elseif ($tab === 'orders'): ?>
        <h2>My Orders</h2>
        <?php if (empty($orders)): ?>
          <div class="empty-state mb-40">
            <div class="icon">📦</div>
            <p>No orders yet. <a href="<?= BASE_URL ?>/index.php" class="btn-link">Start shopping</a></p>
          </div>
        <?php else: ?>
          <table class="data-table">
            <thead>
              <tr>
                <th>Order #</th>
                <th>Date</th>
                <th>Total</th>
                <th>Status</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($orders as $o): ?>
                <tr>
                  <td><strong><?= h($o['order_number']) ?></strong></td>
                  <td><?= date('M d, Y', strtotime($o['ordered_at'])) ?></td>
                  <td><strong><?= format_price($o['total_amount']) ?></strong></td>
                  <td><span class="badge badge-<?= h($o['status']) ?>"><?= h($o['status']) ?></span></td>
                  <td><a href="/rg-trading-php/pages/order-detail.php?id=<?= h($o['id']) ?>" class="btn-sm btn-sm-blue">View</a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <div class="mt-15">
            <a href="<?= BASE_URL ?>/pages/orders.php" class="btn-link text-small">View all orders →</a>
          </div>
        <?php endif; ?>

      <?php elseif ($tab === 'security'): ?>
        <h2>Change Password</h2>
        <form method="POST">
          <input type="hidden" name="action" value="change_password">
          <div class="form-group">
            <label>Current Password</label>
            <input type="password" name="current_password" required>
          </div>
          <div class="form-group">
            <label>New Password</label>
            <input type="password" name="new_password" required minlength="8">
          </div>
          <div class="form-group">
            <label>Confirm New Password</label>
            <input type="password" name="confirm_password" required minlength="8">
          </div>
          <button type="submit" class="btn-primary">Change Password</button>
        </form>
      <?php endif; ?>

    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
