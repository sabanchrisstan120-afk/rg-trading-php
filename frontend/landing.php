<?php
require_once __DIR__ . '/includes/config.php';

// API base for server-side requests only.
// Use the configured API_BASE; do not hardcode localhost in production.
$api_base = API_BASE;

$cat_result = api_request('GET', '/products/categories');
$categories = $cat_result['body']['data']['categories'] ?? [];

$prod_result = api_request('GET', '/products?' . http_build_query(['limit' => 1, 'page' => 1]));
$total_products = (int) ($prod_result['body']['data']['pagination']['total'] ?? 0);
$category_count = count($categories);

$page_title = 'Home — ' . APP_NAME;
$main_class = 'main-content landing-page';
include __DIR__ . '/includes/header.php';
?>

<section class="lp-hero" aria-labelledby="lp-hero-title">
  <div class="lp-hero-inner">
    <p class="lp-eyebrow">Iloilo &amp; Western Visayas</p>
    <h1 id="lp-hero-title">Premium air conditioning systems for homes and businesses</h1>
    <p class="lp-lead">R&amp;G Trading delivers reliable cooling, transparent pricing, and easy ordering so your space stays comfortable every day.</p>
    <div class="lp-hero-actions">
      <a href="<?= BASE_URL ?>/" class="lp-btn lp-btn-primary">Browse products</a>
     
    </div>
  </div>
</section>

<div class="lp-stats-wrap">
  <ul class="lp-stats" aria-label="Store highlights">
    <li>
      <span class="lp-stat-value"><?= $category_count > 0 ? h((string) $category_count) : '—' ?></span>
      <span class="lp-stat-label">Categories</span>
    </li>
    <li>
      <span class="lp-stat-value"><?= $total_products > 0 ? h((string) $total_products) : '—' ?></span>
      <span class="lp-stat-label">Products listed</span>
    </li>
    <li>
      <span class="lp-stat-value">₱10k+</span>
      <span class="lp-stat-label">Free shipping threshold</span>
    </li>
  </ul>
</div>

<section class="lp-section lp-section-alt" aria-labelledby="lp-features-title">
  <div class="lp-inner">
    <h2 id="lp-features-title" class="lp-section-title">Why customers choose us</h2>
    <p class="lp-section-sub">Quality inventory, clear pricing, and a focused team that understands cooling in the tropics.</p>
    <ul class="lp-features">
      <li class="lp-feature">
        <div class="lp-feature-icon" aria-hidden="true">✓</div>
        <h3>Curated selection</h3>
        <p>Brands and models chosen for reliability, efficiency, and real-world performance in Philippine climates.</p>
      </li>
      <li class="lp-feature">
        <div class="lp-feature-icon" aria-hidden="true">🚚</div>
        <h3>Sensible delivery</h3>
        <p>Orders over ₱10,000 ship free—so larger installs and upgrades are easier on your budget.</p>
      </li>
      <li class="lp-feature">
        <div class="lp-feature-icon" aria-hidden="true">💬</div>
        <h3>Account-based ordering</h3>
        <p>Sign in to place orders and track them in one place, from confirmation to fulfillment.</p>
      </li>
    </ul>
  </div>
</section>

<?php if (!empty($categories)): ?>
<section class="lp-section" aria-labelledby="lp-cat-title">
  <div class="lp-inner">
    <h2 id="lp-cat-title" class="lp-section-title">Shop by category</h2>
    <p class="lp-section-sub">Jump straight into the range that fits your space.</p>
    <div class="lp-cat-grid">
      <?php foreach (array_slice($categories, 0, 6) as $cat): ?>
        <a class="lp-cat-card" href="<?= BASE_URL ?>/index.php?<?= h(http_build_query(['category' => $cat['slug']])) ?>">
          <span class="lp-cat-name"><?= h($cat['name']) ?></span>
          <?php if (isset($cat['product_count'])): ?>
            <span class="lp-cat-meta"><?= (int) $cat['product_count'] ?> products</span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
    <p class="lp-cat-more"><a href="<?= BASE_URL ?>/index.php">View full catalog →</a></p>
  </div>
</section>
<?php endif; ?>

<section class="lp-cta" aria-labelledby="lp-cta-title">
  <div class="lp-inner lp-cta-inner">
    <h2 id="lp-cta-title">Ready to cool your space?</h2>
    <p>Browse the catalog, compare specs, and order when you are set—login required for checkout.</p>
    
  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>