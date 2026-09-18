<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
  <title><?= h($page_title ?? APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
  <?php if (!empty($page_css)): ?>
    <link rel="stylesheet" href="<?= BASE_URL . $page_css ?>">
  <?php endif; ?>
</head>
<body>

<nav class="navbar">
  <div class="nav-container">
    <div class="nav-brand-wrap">
      <a href="<?= BASE_URL ?>/landing.php" class="nav-brand">
        <span class="brand-rg">R&amp;G</span> Trading ❄️
      </a>
      <button class="nav-toggle" type="button" aria-label="Toggle navigation menu">☰</button>
    </div>

    <div class="nav-links">
      <?php if (is_admin()): ?>
        <a href="<?= BASE_URL ?>/pages/admin/adminindex.php">Products</a>
      <?php else: ?>
        <a href="<?= BASE_URL ?>/index.php">Products</a>
      <?php endif; ?>

      <?php if (!is_rider()): ?>
        <a href="<?= BASE_URL ?>/pages/cart.php" class="cart-icon" aria-label="View cart">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">
            <path d="M7 4H5a1 1 0 0 0-1 1l1.5 9A2 2 0 0 0 7.5 16h9a2 2 0 0 0 2-1.71L20.42 7H8.21L7 4zm1.5 10a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3zm9 0a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3z"/>
          </svg>
          <span id="cart-count">
            <?= count($_SESSION['cart'] ?? []) ?>
          </span>
        </a>
      <?php endif; ?>
  
  <?php if (is_logged_in()): ?>
        <?php if (is_admin()): ?>
          <a href="<?= BASE_URL ?>/pages/admin/orders.php">Orders</a>
        <?php elseif (is_rider()): ?>
          <a href="<?= BASE_URL ?>/pages/rider/orders.php">Rider Orders</a>
        <?php else: ?>
          <a href="<?= BASE_URL ?>/pages/orders.php">Orders</a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/pages/profile.php">Profile</a>
        <?php if (is_admin()): ?>
          <a href="<?= BASE_URL ?>/pages/admin/dashboard.php" class="nav-admin">⚙️ Admin</a>
        <?php endif; ?>
        <div class="nav-user">
          <span>👤 <?= h(current_user()['first_name'] ?? '') ?></span>
          <form method="POST" action="<?= BASE_URL ?>/logout.php" class="inline-form">
            <button type="submit" class="btn-logout">Logout</button>
          </form>
        </div>

        
      <?php else: ?>
        <a href="<?= BASE_URL ?>/login.php" class="btn-login">Login</a>
        <a href="<?= BASE_URL ?>/register.php" class="btn-register">Register</a>
      <?php endif; ?>
    </div>
  </div>
</nav>

<?php
$flash = get_flash();
if ($flash): ?>
<div class="flash flash-<?= h($flash['type']) ?>">
  <?= h($flash['message']) ?>
  <button onclick="this.parentElement.remove()">✕</button>
</div>
<?php endif; ?>

<main class="main-content main-full">