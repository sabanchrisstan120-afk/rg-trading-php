<?php
require_once __DIR__ . '/../includes/config.php';
// SUBMIT REVIEW
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {

    require_login();

  $payload = [
    'product_id' => trim($_POST['product_id']),
    'rating'     => intval($_POST['rating']),
    'comment'    => trim($_POST['comment'])
];
     
   $res = api_request(
    'POST',
    '/reviews',
    $payload,
    true
);




    if ($res['status'] === 201) {
        set_flash('success', 'Review submitted successfully.');
    } else {
        set_flash('error', $res['body']['message'] ?? 'Failed to submit review.');
    }

    header("Location: " . $_SERVER['REQUEST_URI']);
    exit;
}

$id = trim($_GET['id'] ?? '');
if (!$id) { header('Location: /rg-trading-php/index.php'); exit; }

$res     = api_request('GET', '/products/' . urlencode($id));
$product = $res['body']['data']['product'] ?? null;
$review_res = api_request(
    'GET',
    '/reviews/product/' . $id
);

// Same shape inconsistency as /orders — this endpoint may nest under
// body.data.reviews instead of body.reviews depending on the route/version.
$reviews = $review_res['body']['data']['reviews'] ?? $review_res['body']['reviews'] ?? [];
/* COMPUTE AVERAGE */
$total_rating = 0;

foreach ($reviews as $r) {
    $total_rating += intval($r['rating'] ?? 0);
}

$average_rating = count($reviews)
    ? round($total_rating / count($reviews), 1)
    : 0;

    // PRODUCT RECOMMENDATIONS — same category, similar price (±30%)
$rec_res = api_request('GET', '/products?category=' . urlencode($product['category_slug']) . '&limit=4');
$all_rec = $rec_res['body']['data']['products'] ?? [];

$product_price = floatval($product['price']);
$recommendations = array_filter($all_rec, function($p) use ($product, $product_price) {
    if ($p['id'] === $product['id']) return false; // exclude current product
    $p_price = floatval($p['price']);
    $lower   = $product_price * 0.70;
    $upper   = $product_price * 1.30;
    return $p_price >= $lower && $p_price <= $upper;
});
$recommendations = array_slice($recommendations, 0, 4);

if (!$product) {
    set_flash('error', 'Product not found.');
    header('Location: /rg-trading-php/index.php'); exit;
}

$page_title = h($product['name']) . ' — ' . APP_NAME;
include __DIR__ . '/../includes/header.php';

/* ── IMAGE LOGIC (unchanged) ── */
$images = $product['image_urls'] ?? [];
if (is_string($images)) {
    $decoded = json_decode($images, true);
    $images  = is_array($decoded) ? $decoded : [];
}
if (empty($images) && !empty($product['image_url'])) {
    $images = [$product['image_url']];
}
?>

<div class="pd-wrap">

  <!-- Breadcrumb -->
  <nav class="pd-crumb">
    <a href="/rg-trading-php/index.php">Products</a>
    <?php if ($product['category']): ?>
      <span>›</span>
      <a href="/rg-trading-php/index.php?category=<?= h($product['category_slug']) ?>"><?= h($product['category']) ?></a>
    <?php endif; ?>
    <span>›</span>
    <span><?= h($product['name']) ?></span>
  </nav>

  <!-- Card -->
  <div class="pd-card">

    <!-- ══ GALLERY ══ -->
    <div class="pd-gallery" id="pdGallery">

      <?php if (!empty($images)): ?>

        <!-- Thumbnail strip -->
        <div class="pd-thumbs" id="pdThumbs">
          <button class="pd-thumb-arrow hidden" id="thumbPrev" onclick="scrollThumbs(-1)" aria-label="Previous thumbnails">▲</button>
          <div class="pd-thumb-viewport" id="thumbViewport">
            <?php foreach ($images as $i => $img): ?>
              <div class="pd-thumb <?= $i === 0 ? 'active' : '' ?>"
                   data-index="<?= $i ?>"
                   onclick="goToSlide(<?= $i ?>)"
                   role="button"
                   aria-label="Product image <?= $i + 1 ?>">
                <img src="<?= h($img) ?>" alt="<?= h($product['name']) ?> thumbnail <?= $i + 1 ?>" loading="lazy">
              </div>
            <?php endforeach; ?>
          </div>
          <?php if (count($images) > 1): ?>
            <button class="pd-thumb-arrow" id="thumbNext" onclick="scrollThumbs(1)" aria-label="Next thumbnails">▼</button>
          <?php endif; ?>
        </div>

        <!-- Main image -->
        <div class="pd-main-img-wrap" id="pdMainWrap">
          <div class="pd-main-slides">
            <?php foreach ($images as $i => $img): ?>
              <div class="pd-slide <?= $i === 0 ? 'active' : '' ?>" data-index="<?= $i ?>">
                <img src="<?= h($img) ?>" alt="<?= h($product['name']) ?> image <?= $i + 1 ?>">
              </div>
            <?php endforeach; ?>
          </div>

          <?php if (count($images) > 1): ?>
            <button class="pd-arrow prev" onclick="changeSlide(-1)" aria-label="Previous image">&#8249;</button>
            <button class="pd-arrow next" onclick="changeSlide(1)"  aria-label="Next image">&#8250;</button>
            <div class="pd-dots" id="pdDots">
              <?php foreach ($images as $i => $_): ?>
                <div class="pd-dot <?= $i === 0 ? 'active' : '' ?>"
                     data-index="<?= $i ?>"
                     onclick="goToSlide(<?= $i ?>)"
                     role="button"
                     aria-label="Image <?= $i + 1 ?>"></div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

      <?php else: ?>
        <div class="pd-main-img-wrap"><div class="pd-no-img">❄️</div></div>
      <?php endif; ?>

    </div><!-- /.pd-gallery -->


    <!-- ══ INFO ══ -->
    <div class="pd-info">

      <div>
        <span class="pd-brand"><?= h($product['brand']) ?></span>
      </div>

      <h1 class="pd-name"><?= h($product['name']) ?></h1>
      <span class="pd-model">Model No: <?= h($product['model_number']) ?></span>

      <!-- Price + stock -->
      <div class="pd-price-row">
        <span class="pd-price"><?= format_price($product['price']) ?></span>
        <?php
          $qty = (int)$product['stock_qty'];
          if ($qty <= 0):
        ?>
          <span class="pd-stock-badge stock-out">● Out of Stock</span>
        <?php elseif ($qty <= 5): ?>
          <span class="pd-stock-badge stock-low">● Only <?= $qty ?> left!</span>
        <?php else: ?>
          <span class="pd-stock-badge stock-in">● In Stock (<?= $qty ?>)</span>
        <?php endif; ?>
      </div>
      <!-- Ratings -->
      <div class="pd-stats">
        <div class="avg">⭐ <?= number_format($average_rating, 1) ?></div>
        <div class="count"><?= count($reviews) ?> Reviews</div>
      </div>

<!-- Review List -->
<div class="review-list">
<?php

$review_res = api_request(
    'GET',
    '/reviews/product/' . $id
);

$reviews = $review_res['body']['data']['reviews'] ?? $review_res['body']['reviews'] ?? [];

?>
  <h3 class="section-title">Customer Reviews</h3>

  <?php if (empty($reviews)): ?>

    <div class="pd-review-empty">No reviews yet.</div>

 <?php else: ?>

  <?php foreach ($reviews as $review): ?>

    <div class="review-card">
      <div class="review-meta">
        <div class="review-author"><?= h(trim(($review['first_name'] ?? '') . ' ' . ($review['last_name'] ?? ''))) ?></div>
        <div class="review-rating"><?= str_repeat('⭐', intval($review['rating'] ?? 0)) ?></div>
      </div>
      <div class="review-body"><?= h($review['comment'] ?? '') ?></div>
    </div>

  <?php endforeach; ?>

<?php endif; ?>

</div>
      <!-- Description -->
      <?php if (!empty($product['description'])): ?>
        <p class="pd-desc"><?= h($product['description']) ?></p>
      <?php endif; ?>

      <!-- Specs -->
      <table class="pd-specs">
        <?php if ($product['horsepower']): ?>
          <tr><td>Horsepower</td><td><?= h($product['horsepower']) ?> HP</td></tr>
        <?php endif; ?>
        <?php if ($product['cooling_capacity_btu']): ?>
          <tr><td>Cooling Capacity</td><td><?= number_format($product['cooling_capacity_btu']) ?> BTU</td></tr>
        <?php endif; ?>
        <?php if ($product['energy_rating']): ?>
          <tr><td>Energy Rating</td><td><?= h($product['energy_rating']) ?></td></tr>
        <?php endif; ?>
        <?php if ($product['category']): ?>
          <tr><td>Category</td><td><?= h($product['category']) ?></td></tr>
        <?php endif; ?>
        <?php $pd_shipping = get_setting_float('shipping_fee', 500.00); ?>
        <tr><td>Shipping</td><td><?= ($pd_shipping !== null && $pd_shipping > 0) ? '₱' . number_format($pd_shipping, 2) : '🚚 FREE Shipping' ?></td></tr>
      </table>

      <!-- CTA -->
      <?php if (!is_admin()): ?>
      <div class="pd-cta">
        <?php if (is_logged_in() && $qty > 0): ?>
          <a href="<?= BASE_URL ?>/pages/checkout.php?product_id=<?= h($product['id']) ?>" class="cta-full">
            <button class="btn-order btn-full">Order Now</button>
          </a>
        <?php elseif (!is_logged_in()): ?>
          <a href="<?= BASE_URL ?>/login.php" class="cta-full">
            <button class="btn-order secondary btn-full">Login to Order</button>
          </a>
        <?php else: ?>
          <button class="btn-order btn-full" disabled>Out of Stock</button>
        <?php endif; ?>
      </div>
      <?php endif; ?>


      <?php if (is_logged_in() && !is_admin() && $qty > 0): ?>



        


  <!-- ADD TO CART BUTTON -->
  <form method="POST" action="<?= BASE_URL ?>/pages/add-to-cart.php">
    <input type="hidden" name="product_id" value="<?= h($product['id']) ?>">
    <input type="hidden" name="product_name" value="<?= h($product['name']) ?>">
    <input type="hidden" name="price" value="<?= h($product['price']) ?>">
    <input type="hidden" name="image" value="<?= h($images[0] ?? '') ?>">
    <input type="hidden" name="product_id" value="<?= h($product['id']) ?>">

    <input type="number" name="qty" value="1" min="1" max="<?= (int)$product['stock_qty'] ?>" class="qty-input">

    <button type="submit" name="add_to_cart" class="btn-order secondary btn-full">
      Add to Cart
    </button>
  </form>

<?php endif; ?>
    <!-- ADD REVIEW -->
<?php
$can_review = false;

if (is_logged_in() && !is_admin()) {

    $orders_res = api_request(
        'GET',
        '/orders/my-orders',
        [],
        true
    );

    // Same API can return orders under body.data.orders OR body.orders
    // depending on endpoint/version — orders.php already accounts for this.
    // Missing the fallback here means $orders silently ends up empty and
    // $can_review never becomes true even for a fully paid/delivered order.
    $orders = $orders_res['body']['data']['orders'] ?? $orders_res['body']['orders'] ?? [];

    foreach ($orders as $o) {

        foreach (($o['items'] ?? []) as $item) {

            if (($item['product_id'] ?? '') == $product['id']) {
                $can_review = true;
                break 2;
            }
        }
    }
}
?>

<?php if ($can_review): ?>

<div class="card-panel">
  <h3 class="mb-18">Write a Review</h3>

  <form method="POST">
    <input type="hidden" name="submit_review" value="1">
    <input type="hidden" name="product_id" value="<?= h($product['id']) ?>">

    <!-- Rating -->
    <div class="form-group">
      <label class="form-label-strong">Rating</label>
      <select name="rating" required class="form-select">
        <option value="">Select Rating</option>
        <option value="5">⭐⭐⭐⭐⭐ (5)</option>
        <option value="4">⭐⭐⭐⭐ (4)</option>
        <option value="3">⭐⭐⭐ (3)</option>
        <option value="2">⭐⭐ (2)</option>
        <option value="1">⭐ (1)</option>
      </select>
    </div>

    <!-- Comment -->
    <div class="form-group">
      <label class="form-label-strong">Comment</label>
      <textarea name="comment" required rows="4" placeholder="Write your review..." class="form-textarea"></textarea>
    </div>
    <button type="submit" id="reviewBtn" class="btn-submit">Submit Review</button>
  </form>
</div>

<?php endif; ?>
    </div><!-- /.pd-info -->

  </div><!-- /.pd-card -->
</div><!-- /.pd-wrap -->


<!-- ═══════════════════════════════════════════════════════════════
     GALLERY JAVASCRIPT
════════════════════════════════════════════════════════════════ -->
<script>
(function () {
  'use strict';

  /* ── State ── */
  var current      = 0;
  var thumbOffset  = 0;   // how many thumbs scrolled past
  var VISIBLE      = 5;   // max visible thumbs at once
  var slides       = Array.from(document.querySelectorAll('.pd-slide'));
  var thumbs       = Array.from(document.querySelectorAll('.pd-thumb'));
  var dots         = Array.from(document.querySelectorAll('.pd-dot'));
  var total        = slides.length;
  var thumbPrevBtn = document.getElementById('thumbPrev');
  var thumbNextBtn = document.getElementById('thumbNext');

  if (total <= 1) return; // single image — nothing to wire up

  /* ── Core: go to slide N ── */
  window.goToSlide = function (n) {
    slides[current].classList.remove('active');
    thumbs[current].classList.remove('active');
    if (dots[current]) dots[current].classList.remove('active');

    current = (n + total) % total;

    slides[current].classList.add('active');
    thumbs[current].classList.add('active');
    if (dots[current]) dots[current].classList.add('active');

    ensureThumbVisible(current);
  };

  /* ── Arrow navigation ── */
  window.changeSlide = function (dir) {
    goToSlide(current + dir);
  };

  /* ── Thumb strip scrolling ── */
  window.scrollThumbs = function (dir) {
    thumbOffset = Math.max(0, Math.min(total - VISIBLE, thumbOffset + dir));
    applyThumbScroll();
    updateThumbArrows();
  };

  function applyThumbScroll () {
    var thumbSize = 72 + 8; // thumb height + gap
    var viewport  = document.getElementById('thumbViewport');
    if (viewport) {
      viewport.scrollTop = thumbOffset * thumbSize;
    }
  }

  function ensureThumbVisible (idx) {
    if (idx < thumbOffset) {
      thumbOffset = idx;
      applyThumbScroll();
    } else if (idx >= thumbOffset + VISIBLE) {
      thumbOffset = idx - VISIBLE + 1;
      applyThumbScroll();
    }
    updateThumbArrows();
  }

  function updateThumbArrows () {
    if (!thumbPrevBtn || !thumbNextBtn) return;
    thumbPrevBtn.classList.toggle('hidden', thumbOffset <= 0);
    thumbNextBtn.classList.toggle('hidden', thumbOffset >= total - VISIBLE);
  }

  /* ── Touch / swipe on main image ── */
  var wrap = document.getElementById('pdMainWrap');
  if (wrap) {
    var touchStartX = 0;
    wrap.addEventListener('touchstart', function (e) {
      touchStartX = e.changedTouches[0].screenX;
    }, { passive: true });
    wrap.addEventListener('touchend', function (e) {
      var diff = touchStartX - e.changedTouches[0].screenX;
      if (Math.abs(diff) > 40) changeSlide(diff > 0 ? 1 : -1);
    }, { passive: true });
  }

  /* ── Keyboard navigation ── */
  document.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowLeft')  changeSlide(-1);
    if (e.key === 'ArrowRight') changeSlide(1);
  });

  /* ── Init ── */
  updateThumbArrows();
  if (total <= VISIBLE && thumbNextBtn) thumbNextBtn.classList.add('hidden');

  /* ── Viewport: switch thumbs to horizontal on mobile ── */
  function checkLayout () {
    var viewport = document.getElementById('thumbViewport');
    if (!viewport) return;
    if (window.innerWidth <= 900) {
      viewport.scrollTop  = 0;
      viewport.scrollLeft = thumbOffset * (58 + 8);
    } else {
      applyThumbScroll();
    }
  }
  window.addEventListener('resize', checkLayout);

}());
</script>


<script>
document.querySelector('form').addEventListener('submit', function () {
    const btn = document.getElementById('reviewBtn');
    if (btn) {
        btn.disabled = true;
        btn.innerText = "Submitting...";
    }
});
</script>



<!-- ═══════════════════════════════════════════════════════════════
     PRODUCT RECOMMENDATIONS
════════════════════════════════════════════════════════════════ -->
<?php if (!empty($recommendations)): ?>
<div class="pd-wrap rec-wrap">
  <div class="rec-card">
    <h2 class="rec-title">You Might Also Like</h2>
    <div class="rec-grid">
      <?php foreach ($recommendations as $rec):
        $rec_images = $rec['image_urls'] ?? [];
        if (is_string($rec_images)) $rec_images = json_decode($rec_images, true) ?? [];
        if (empty($rec_images) && !empty($rec['image_url'])) $rec_images = [$rec['image_url']];
        $rec_img = $rec_images[0] ?? null;
        $rec_qty = (int)($rec['stock_qty'] ?? 0);
      ?>
      <a href="<?= BASE_URL ?>/pages/product-detail.php?id=<?= h($rec['id']) ?>" class="link-reset">
        <div class="rec-item">

          <!-- Image -->
          <div class="rec-img">
            <?php if ($rec_img): ?>
              <img src="<?= h($rec_img) ?>"
                   alt="<?= h($rec['name']) ?>"
                   class="rec-img-thumb">
              <?php else: ?>
              <span class="icon-xxl text-muted-light">❄️</span>
            <?php endif; ?>
          </div>

          <!-- Info -->
          <div class="rec-info">
            <div class="rec-brand"><?= h($rec['brand']) ?></div>
            <div class="rec-name"><?= h($rec['name']) ?></div>
            <div class="rec-price"><?= format_price($rec['price']) ?></div>
            <?php if ($rec_qty <= 0): ?>
              <span class="rec-badge rec-badge-out">● Out of Stock</span>
            <?php elseif ($rec_qty <= 5): ?>
              <span class="rec-badge rec-badge-low">● Only <?= $rec_qty ?> left</span>
            <?php else: ?>
              <span class="rec-badge rec-badge-in">● In Stock</span>
            <?php endif; ?>
          </div>

        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>