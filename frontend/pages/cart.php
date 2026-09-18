<?php
require_once __DIR__ . '/../includes/config.php';
require_login();

// Handle remove via GET
if (isset($_GET['action']) && $_GET['action'] === 'remove' && !empty($_GET['product_id'])) {
    $pid = $_GET['product_id'];
    if (isset($_SESSION['cart'][$pid])) {
        unset($_SESSION['cart'][$pid]);
        set_flash('success', 'Item removed from cart.');
    }
    header('Location: ' . BASE_URL . '/pages/cart.php');
    exit;
}

// Handle quantity updates via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qty']) && is_array($_POST['qty'])) {
    foreach ($_POST['qty'] as $pid => $q) {
        $q = max(0, intval($q));
        if ($q <= 0) {
            unset($_SESSION['cart'][$pid]);
        } else {
            if (isset($_SESSION['cart'][$pid])) {
                $_SESSION['cart'][$pid]['qty'] = $q;
            }
        }
    }
    set_flash('success', 'Cart updated.');
    header('Location: ' . BASE_URL . '/pages/cart.php');
    exit;
}

$page_title = 'Your Cart';
include __DIR__ . '/../includes/header.php';

$cart = $_SESSION['cart'] ?? [];
$total = 0;
?>

<div class="container-md">
  <div class="page-header">
    <h1>Your Cart</h1>
    <p><a href="<?= BASE_URL ?>/index.php" class="btn-link">← Continue shopping</a></p>
  </div>

  <?php if (empty($cart)): ?>
    <div class="card-center">Your cart is empty. <a href="<?= BASE_URL ?>/index.php" class="btn-link">Browse products</a></div>
  <?php else: ?>
      <table class="table-card">
        <thead class="thead-muted">
          <tr>
            <th class="cell-pad">Product</th>
            <th class="cell-pad">Price</th>
            <th class="cell-pad">Qty</th>
            <th class="cell-pad">Subtotal</th>
            <th class="cell-pad">&nbsp;</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($cart as $pid => $item):
            $subtotal = $item['price'] * $item['qty'];
            $total += $subtotal;
          ?>
          <tr>
            <td class="cell-pad">
              <div class="d-flex items-center gap-12">
                <img src="<?= h($item['image'] ?: BASE_URL.'/assets/img/placeholder.png') ?>" alt="" class="product-thumb">
                <div>
                  <div class="font-strong text-strong"><?= h($item['name']) ?></div>
                </div>
              </div>
            </td>
            <td class="cell-pad"><?= format_price($item['price']) ?></td>
            <td class="cell-pad">
              <span class="qty-display"><?= h($item['qty']) ?></span>
            </td>
            <td class="cell-pad"><?= format_price($subtotal) ?></td>
            <td class="cell-pad">
              <a href="<?= BASE_URL ?>/pages/checkout.php?product_id=<?= h($pid) ?>" class="btn">Checkout</a>
              <a href="<?= BASE_URL ?>/pages/cart.php?action=remove&product_id=<?= h($pid) ?>" class="link-danger">Remove</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <div class="d-flex justify-between items-center mt-16">
        <div></div>
        <div class="text-right">
          <div class="text-muted mb-6">Total</div>
          <div class="font-strong text-large"><?= format_price($total) ?></div>
        </div>
      </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
