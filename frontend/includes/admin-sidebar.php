<?php
// Reusable admin sidebar — keep links consistent across admin pages
?>
<div class="admin-sidebar" id="adminSidebar" aria-hidden="true" role="navigation">
  <button type="button" class="admin-sidebar-toggle" aria-label="Toggle admin menu" aria-controls="adminSidebar" aria-expanded="false">Menu</button>
  <div class="sidebar-title">Admin Panel</div>
  <a href="<?= BASE_URL ?>/pages/admin/dashboard.php" class="<?= strpos($_SERVER['REQUEST_URI'], '/pages/admin/dashboard.php') !== false ? 'active' : '' ?>"><span class="icon">📊</span> Dashboard</a>
  <a href="<?= BASE_URL ?>/pages/admin/products.php" class="<?= strpos($_SERVER['REQUEST_URI'], '/pages/admin/products.php') !== false ? 'active' : '' ?>"><span class="icon">❄️</span> Products</a>
  <a href="<?= BASE_URL ?>/pages/admin/orders.php" class="<?= strpos($_SERVER['REQUEST_URI'], '/pages/admin/orders.php') !== false ? 'active' : '' ?>"><span class="icon">📦</span> Orders</a>
  <a href="<?= BASE_URL ?>/pages/admin/payment-settings.php" class="<?= strpos($_SERVER['REQUEST_URI'], '/pages/admin/payment-settings.php') !== false ? 'active' : '' ?>"><span class="icon">💳</span> Payment Settings</a>
  <a href="<?= BASE_URL ?>/pages/admin/shipping-settings.php" class="<?= strpos($_SERVER['REQUEST_URI'], '/pages/admin/shipping-settings.php') !== false ? 'active' : '' ?>"><span class="icon">🚚</span> Shipping Settings</a>
  <a href="<?= BASE_URL ?>/pages/admin/users.php" class="<?= strpos($_SERVER['REQUEST_URI'], '/pages/admin/users.php') !== false ? 'active' : '' ?>"><span class="icon">👥</span> Users</a>
  <a href="<?= BASE_URL ?>/pages/admin/categories.php" class="<?= strpos($_SERVER['REQUEST_URI'], '/pages/admin/categories.php') !== false ? 'active' : '' ?>"><span class="icon">🏷️</span> Categories</a>
  <a href="<?= BASE_URL ?>" class="mt-auto border-top pt-12"><span class="icon">🏪</span> View Store</a>
</div>
<div class="admin-sidebar-backdrop" aria-hidden="true"></div>
