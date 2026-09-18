<?php
require_once __DIR__ . '/../../includes/config.php';

require_admin();

$cat_res    = api_request('GET', '/products/categories');
$categories = $cat_res['body']['data']['categories'] ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'create') {
      $uploaded_images = [];

if (!empty($_FILES['images']['name'][0])) {

    $upload_dir = __DIR__ . '/../../uploads/products/';

    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {

        if ($_FILES['images']['error'][$key] === 0) {

            $ext = pathinfo($_FILES['images']['name'][$key], PATHINFO_EXTENSION);
            $allowed = ['jpg', 'jpeg', 'png', 'webp'];

if (!in_array(strtolower($ext), $allowed)) {
    continue;
}

            $filename = time() . '_' . uniqid() . '.' . $ext;

            $destination = $upload_dir . $filename;

            if (move_uploaded_file($tmp_name, $destination)) {

                $uploaded_images[] =
                    '/rg-trading-php/uploads/products/' . $filename;
            }
        }

    }
}
        $payload = [
            'category_id'          => ($_POST['category_id'] ?? '') ? intval($_POST['category_id'] ?? '') : null,
            'name'                 => trim($_POST['name']),
            'model_number'         => trim($_POST['model_number']),
            'brand'                => trim($_POST['brand']),
            'description'          => trim($_POST['description']) ?: null,
            'horsepower'           => $_POST['horsepower'] !== '' ? floatval($_POST['horsepower']) : null,
            'cooling_capacity_btu' => $_POST['cooling_capacity_btu'] !== '' ? intval($_POST['cooling_capacity_btu']) : null,
            'energy_rating'        => trim($_POST['energy_rating']) ?: null,
            'price'                => floatval($_POST['price']),
            'stock_qty'            => intval($_POST['stock_qty']),
            'image_url'            => $uploaded_images[0] ?? ($_POST['existing_image'] ?? null),
            'image_urls'           => $uploaded_images,
            'is_active'            => intval($_POST['is_active'] ?? 1),
        ];
        $res = api_request('POST', '/products', $payload, true);
        set_flash($res['status'] === 201 ? 'success' : 'error', $res['body']['message'] ?? 'Failed to create product.');
        header('Location: /rg-trading-php/pages/admin/products.php'); exit;
    }

    if ($action === 'update') {

    $uploaded_images = [];

    // Upload new images
    if (!empty($_FILES['images']['name'][0])) {

        $upload_dir = __DIR__ . '/../../uploads/products/';

        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {

            if ($_FILES['images']['error'][$key] === 0) {

                $ext = strtolower(pathinfo($_FILES['images']['name'][$key], PATHINFO_EXTENSION));

                $allowed = ['jpg', 'jpeg', 'png', 'webp'];

                if (!in_array($ext, $allowed)) {
                    continue;
                }

                $filename = time() . '_' . uniqid() . '.' . $ext;

                $destination = $upload_dir . $filename;

                if (move_uploaded_file($tmp_name, $destination)) {

                    $uploaded_images[] =
                        '/rg-trading-php/uploads/products/' . $filename;
                }
            }
        }
    }

    // Existing images from hidden input
    $existing_images = json_decode($_POST['existing_images'] ?? '[]', true);

    if (!is_array($existing_images)) {
        $existing_images = [];
    }

    // Merge old + new images
    $all_images = array_merge($existing_images, $uploaded_images);

    $pid = $_POST['product_id'];

    $payload = [
        'category_id'          => ($_POST['category_id'] ?? '') ? intval($_POST['category_id']) : null,
        'name'                 => trim($_POST['name']),
        'model_number'         => trim($_POST['model_number']),
        'brand'                => trim($_POST['brand']),
        'description'          => trim($_POST['description']) ?: null,
        'horsepower'           => $_POST['horsepower'] !== '' ? floatval($_POST['horsepower']) : null,
        'cooling_capacity_btu' => $_POST['cooling_capacity_btu'] !== '' ? intval($_POST['cooling_capacity_btu']) : null,
        'energy_rating'        => trim($_POST['energy_rating']) ?: null,
        'price'                => floatval($_POST['price']),
        'stock_qty'            => intval($_POST['stock_qty']),
        'is_active'            => intval($_POST['is_active'] ?? 1),

        // Main thumbnail
        'image_url'            => $all_images[0] ?? null,

        // All images
        'image_urls'           => $all_images,
    ];

    $res = api_request('PUT', '/products/' . $pid, $payload, true);

    set_flash(
        $res['status'] === 200 ? 'success' : 'error',
        $res['body']['message'] ?? 'Failed to update product.'
    );

    header('Location: /rg-trading-php/pages/admin/products.php');
    exit;
}
   // STOCK ADJUSTMENT
if ($action === 'stock') {

    $pid = $_POST['product_id'];

    $payload = [
        'adjustment' => intval($_POST['adjustment']),
        'reason'     => trim($_POST['reason'] ?? 'Admin adjustment')
    ];

    $res = api_request(
        'PATCH',
        '/products/' . $pid . '/stock',
        $payload,
        true
    );

    set_flash(
        $res['status'] === 200 ? 'success' : 'error',
        $res['body']['message'] ?? 'Failed to update stock.'
    );

    header('Location: /rg-trading-php/pages/admin/products.php');
    exit;
}

// DEACTIVATE PRODUCT
if ($action === 'delete') {

    $pid = $_POST['product_id'];

    $res = api_request(
        'DELETE',
        '/products/' . $pid,
        [],
        true
    );

    set_flash(
        $res['status'] === 200 ? 'success' : 'error',
        $res['body']['message'] ?? 'Failed to deactivate product.'
    );

    header('Location: /rg-trading-php/pages/admin/products.php');
    exit;
}

// RESTORE PRODUCT
if ($action === 'restore') {

    $pid = $_POST['product_id'];

    $res = api_request(
        'PUT',
        '/products/' . $pid . '/restore',
        [],
        true
    );

    set_flash(
        $res['status'] === 200 ? 'success' : 'error',
        $res['body']['message'] ?? 'Failed to restore product.'
    );

    header('Location: /rg-trading-php/pages/admin/products.php');
    exit;
}

// PERMANENT DELETE
if ($action === 'permanent_delete') {

    $pid = $_POST['product_id'];

    $res = api_request(
        'DELETE',
        '/products/' . $pid . '/permanent',
        [],
        true
    );

    set_flash(
        $res['status'] === 200 ? 'success' : 'error',
        $res['body']['message'] ?? 'Failed to permanently delete product.'
    );

    header('Location: /rg-trading-php/pages/admin/products.php');
    exit;
}
}
$search      = trim($_GET['search'] ?? '');
$page        = max(1, intval($_GET['page'] ?? 1));
$limit       = 10;
$params      = http_build_query(array_filter(['search' => $search, 'page' => $page, 'limit' => $limit]));
$result = api_request('GET', '/products/admin/list?' . $params, [], true);

$products = $result['body']['data']['products'] ?? [];

/*
|--------------------------------------------------------------------------
| ADD RATINGS + REVIEWS
|--------------------------------------------------------------------------
*/
foreach ($products as &$product) {

    $review_res = api_request(
        'GET',
        '/reviews/product/' . $product['id'],
        [],
        true
    );

    $body = $review_res['body'] ?? [];

    // Every other list endpoint in this app wraps its payload as
    // body.data.<key> (see /products, /products/categories, /orders/admin
    // above). This one was only checking body.reviews, which this API
    // never actually returns — so reviews/ratings always came back empty.
    if (isset($body['data']['reviews']) && is_array($body['data']['reviews'])) {
        $raw_reviews = $body['data']['reviews'];
    } elseif (isset($body['data']) && is_array($body['data']) && !is_associative_array($body['data'])) {
        $raw_reviews = $body['data'];
    } elseif (isset($body['reviews']) && is_array($body['reviews'])) {
        $raw_reviews = $body['reviews'];
    } else {
        $raw_reviews = [];
    }

    // The reviews table itself only stores rating/comment/user_id — no
    // name — so the reviewer's name is whatever the API joins in. Flatten
    // it here so it doesn't matter whether the API nests it under `user`,
    // `reviewer`, or `customer`, or returns it flat.
    $reviews = [];
    foreach ($raw_reviews as $r) {
        if (!is_array($r)) {
            continue;
        }
        $reviewer = $r['user'] ?? $r['reviewer'] ?? $r['customer'] ?? [];
        $reviews[] = [
            'id'         => $r['id'] ?? null,
            'rating'     => intval($r['rating'] ?? 0),
            'comment'    => $r['comment'] ?? '',
            'first_name' => $r['first_name'] ?? ($reviewer['first_name'] ?? ''),
            'last_name'  => $r['last_name'] ?? ($reviewer['last_name'] ?? ''),
            'created_at' => $r['created_at'] ?? null,
        ];
    }

    $product['reviews'] = $reviews;

    $total = 0;

    foreach ($reviews as $r) {
        $total += $r['rating'];
    }

    $product['review_count'] = count($reviews);

    $product['average_rating'] =
        count($reviews) > 0
            ? round($total / count($reviews), 1)
            : 0;
}

unset($product);

$pagination  = $result['body']['data']['pagination'] ?? [];
$total_pages = max(1, ceil(($pagination['total'] ?? 0) / $limit));

$page_title = 'Products — Admin — ' . APP_NAME;
include __DIR__ . '/../../includes/header.php';
?>

<!-- admin products CSS moved to assets/css/style.css -->

<div class="admin-layout">
  <?php include __DIR__ . '/../../includes/admin-sidebar.php'; ?>

  <div class="admin-main">
    <div class="admin-header display-flex-between-center flex-wrap gap-12">
      <div><h1>Products</h1><p>Add, edit, adjust stock, and deactivate aircon products</p></div>
      <button class="add-btn" onclick="openCreate()">+ Add New Product</button>
    </div>

    <form method="GET" class="search-bar display-flex gap-8 mb-16">
      <input type="text" name="search" placeholder="Search by name, brand, or model..." value="<?= h($search) ?>" class="flex-1 p-10 border-1 rounded-8 text-13">
      <button type="submit" class="px-16 p-10 bg-blue text-white border-none rounded-8 cursor-pointer text-13">Search</button>
      <?php if ($search): ?>
        <a href="/rg-trading-php/pages/admin/products.php" class="px-14 p-10 bg-light rounded-8 text-13 text-muted text-decoration-none">Clear</a>
      <?php endif; ?>
    </form>

    <div class="admin-card">
      <div class="admin-card-header">
        <h3>All Products (<?= $pagination['total'] ?? count($products) ?>)</h3>
      </div>
      <div class="admin-card-body card-no-padding">
        <table class="data-table">
          <thead>
            <tr>
              <th class="w-52">Image</th>
              <th>Product</th>
              <th>Model</th>
              <th>Brand</th>
              <th>Price</th>
              <th>Rating</th>
              <th>Stock</th>
              <th>Status</th>
              <th>Actions</th>

            </tr>
          </thead>
          <tbody>
            <?php foreach ($products as $p): ?>
              <?php $is_active = $p['is_active'] ?? 0; ?>
              <tr>
                <td>
                  <?php if (!empty($p['image_url'])): ?>
                    <img src="<?= h($p['image_url']) ?>" alt="product" class="product-img">
                  <?php else: ?>
                    <div class="img-ph">❄️</div>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="font-600 text-13"><?= h($p['name']) ?></div>
                  <div class="text-11 text-muted-light"><?= h($p['category'] ?? '') ?></div>
                </td>
                <td class="text-12 text-muted"><?= h($p['model_number']) ?></td>
                <td class="text-13"><?= h($p['brand']) ?></td>
                <td>
  <strong><?= format_price($p['price']) ?></strong>
</td>

<td>
  <div class="text-13 font-600 text-orange">
    ⭐ <?= number_format($p['average_rating'] ?? 0, 1) ?>
  </div>

  <div class="text-11 text-muted">
    <?= intval($p['review_count'] ?? 0) ?> reviews
  </div>
</td>

                <td>
                  <span class="font-700 <?= (($p['stock_qty'] ?? 0) <= 5) ? 'text-danger' : 'text-success' ?>">
                    <?= intval($p['stock_qty'] ?? 0) ?>
                  </span>
                </td>
                <td>
                <?php $isActive = (bool)($p['is_active'] ?? 1); ?>
                   <span class="badge <?= $isActive ? 'badge-soft-green' : 'badge-soft-red' ?>">
                     <?= $isActive ? 'Active' : 'Inactive' ?>
                      </span>
                </td>
                <td>
                  <div class="action-btns">
                    <button type="button" class="btn-sm btn-sm-blue"
                      onclick='openEdit(
                        <?= json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                          <?= json_encode($categories, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Edit</button>
                      <button
  type="button"
  class="btn-sm bg-orange-light text-orange border-1"
  onclick='openReviews(
      <?= json_encode($p["name"], JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
      <?= json_encode($p["reviews"] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT) ?>
  )'>
  Reviews
</button>
                    <button type="button" class="btn-sm btn-sm-green"
                     onclick='openStock(
                                    <?= json_encode($p["id"]) ?>,
                                <?= json_encode($p["name"], JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                            <?= intval($p["stock_qty"] ?? 0) ?>
                                  )'>Stock</button>

                    <?php if ($is_active): ?>
                      <form method="POST" class="display-inline">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="product_id" value="<?= h($p['id']) ?>">
                        <button type="submit" class="btn-sm btn-sm-red"
                          onclick="return confirm('Deactivate \"<?= h(addslashes($p['name'])) ?>\"?')">Deactivate</button>
                      </form>
                    <?php else: ?>
                      <form method="POST" class="display-inline">
                        <input type="hidden" name="action" value="restore">
                        <input type="hidden" name="product_id" value="<?= h($p['id']) ?>">
                        <button type="submit" class="btn-sm bg-blue-light text-blue border-1">Restore</button>
                      </form>
                      <form method="POST" class="display-inline">
                        <input type="hidden" name="action" value="permanent_delete">
                        <input type="hidden" name="product_id" value="<?= h($p['id']) ?>">
                        <button type="submit" class="btn-sm btn-sm-red"
                          onclick="return confirm('PERMANENTLY delete \"<?= h(addslashes($p['name'])) ?>\"? This cannot be undone!')">Delete Forever</button>
                      </form>
                    <?php endif; ?>
                    
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($products)): ?>
              <tr><td colspan="8" class="table-empty">No products found.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($total_pages > 1): ?>
      <div class="pagination">
        <?php if ($page > 1): ?>
          <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="pagination-arrow">&lsaquo;</a>
        <?php else: ?>
          <span class="pagination-arrow disabled">&lsaquo;</span>
        <?php endif; ?>

        <?php
          $window     = 10;
          $page_start = max(1, min($page - intdiv($window, 2), $total_pages - $window + 1));
          $page_end   = min($total_pages, $page_start + $window - 1);
        ?>
        <?php for ($i = $page_start; $i <= $page_end; $i++): ?>
          <?php if ($i === $page): ?>
            <span class="active"><?= $i ?></span>
          <?php else: ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
          <?php endif; ?>
        <?php endfor; ?>

        <?php if ($page < $total_pages): ?>
          <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="pagination-arrow">&rsaquo;</a>
        <?php else: ?>
          <span class="pagination-arrow disabled">&rsaquo;</span>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- CREATE MODAL -->
<div class="modal-overlay" id="createModal">
  <div class="modal">
    <div class="modal-header">
      <h2>Add New Product</h2>
      <button class="modal-close" onclick="closeModal('createModal')">&times;</button>
    </div>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="create">
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group full">
            <label>Product Name *</label>
            <input type="text" name="name" required placeholder="e.g. Carrier Optima Window 1.0HP">
          </div>
          <div class="form-group">
            <label>Model Number *</label>
            <input type="text" name="model_number" required placeholder="e.g. WCARZ010EC">
          </div>
          <div class="form-group">
            <label>Brand *</label>
            <input type="text" name="brand" required placeholder="e.g. Carrier">
          </div>
          <div class="form-group">
            <label>Category</label>
            <select name="category_id">
              <option value="">— Select —</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= h($cat['id']) ?>"><?= h($cat['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Energy Rating</label>
            <input type="text" name="energy_rating" placeholder="e.g. Inverter">
          </div>
          <div class="form-group">
            <label>Horsepower (HP)</label>
            <input type="number" step="0.25" min="0" name="horsepower" placeholder="e.g. 1.0">
          </div>
          <div class="form-group">
            <label>Cooling Capacity (BTU)</label>
            <input type="number" min="0" name="cooling_capacity_btu" placeholder="e.g. 9000">
          </div>
          <div class="form-group">
            <label>Price (₱) *</label>
            <input type="number" step="0.01" min="0" name="price" required placeholder="e.g. 13500.00">
          </div>
          <div class="form-group">
            <label>Initial Stock *</label>
            <input type="number" min="0" name="stock_qty" required value="0">
          </div>
          <div class="form-group">
            <label>Status</label>
            <select name="is_active" id="c_active">
              <option value="1">Active</option>
              <option value="0">Inactive</option>
            </select>
          </div>
          <div class="form-group full">
  <label>Product Images</label>

  <input type="file"
         name="images[]"
         id="c_images"
         accept="image/png,image/jpeg,image/jpg,image/webp"
         multiple
         onchange="previewMultiple(this, 'c_preview_wrap')">

  <small class="muted-small">
    Upload PNG, JPG, JPEG, or WEBP images
  </small>

  <div id="c_preview_wrap" class="preview-grid"></div>
</div>
          <div class="form-group full">
            <label>Description</label>
            <textarea name="description" placeholder="Optional short description..."></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="mfbtn mfbtn-cancel" onclick="closeModal('createModal')">Cancel</button>
        <button type="submit" class="mfbtn mfbtn-primary">Save Product</button>
      </div>
    </form>
  </div>
</div>

<!-- EDIT MODAL -->
<div class="modal-overlay" id="editModal">
  <div class="modal">
    <div class="modal-header">
      <h2>Edit Product</h2>
      <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
    </div>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="product_id" id="e_id">
      <input type="hidden" name="existing_image" id="e_existing_image">
      <input type="hidden" name="existing_images" id="e_existing_images">
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group full">
            <label>Product Name *</label>
            <input type="text" name="name" id="e_name" required>
          </div>
          <div class="form-group">
            <label>Model Number *</label>
            <input type="text" name="model_number" id="e_model" required>
          </div>
          <div class="form-group">
            <label>Brand *</label>
            <input type="text" name="brand" id="e_brand" required>
          </div>
          <div class="form-group">
            <label>Category</label>
            <select name="category_id" id="e_cat"></select>
          </div>
          <div class="form-group">
            <label>Energy Rating</label>
            <input type="text" name="energy_rating" id="e_energy">
          </div>
          <div class="form-group">
            <label>Horsepower (HP)</label>
            <input type="number" step="0.25" min="0" name="horsepower" id="e_hp">
          </div>
          <div class="form-group">
            <label>Cooling Capacity (BTU)</label>
            <input type="number" min="0" name="cooling_capacity_btu" id="e_btu">
          </div>
          <div class="form-group">
            <label>Price (₱) *</label>
            <input type="number" step="0.01" min="0" name="price" id="e_price" required>
          </div>
          <div class="form-group">
            <label>Stock Quantity *</label>
            <input type="number" min="0" name="stock_qty" id="e_stock" required>
          </div>
         <div class="form-group full">
            <label>Product Images</label>


            <div class="form-group">
  <label>Status</label>

  <select name="is_active" id="e_active">
    <option value="1">Active</option>
    <option value="0">Inactive</option>
  </select>
</div>

  <input type="file"
       name="images[]"
       id="e_images"
       accept="image/png,image/jpeg,image/jpg,image/webp"
       multiple
       onchange="previewMultiple(this, 'e_preview_wrap')">

<small class="muted-small">
  Upload PNG, JPG, JPEG, or WEBP images
</small>


<div id="e_preview_wrap" class="preview-grid"></div>

<div id="existing_images_wrap" class="preview-grid mt-15"></div>



<button type="button" onclick="deleteSelectedImages()" class="btn-danger">Delete Selected Images</button>


</div>
          <div class="form-group full">
            <label>Description</label>
            <textarea name="description" id="e_desc"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="mfbtn mfbtn-cancel" onclick="closeModal('editModal')">Cancel</button>
        <button type="submit" class="mfbtn mfbtn-primary">Update Product</button>
      </div>
    </form>
  </div>
</div>

<!-- STOCK MODAL -->
<div class="modal-overlay" id="stockModal">
  <div class="modal modal-small">
    <div class="modal-header">
      <h2>Adjust Stock</h2>
      <button class="modal-close" onclick="closeModal('stockModal')">&times;</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="stock">
      <input type="hidden" name="product_id" id="s_id">
      <div class="modal-body">
        <p class="mb-12 muted-small">
          Product: <strong id="s_name"></strong><br>
          Current stock: <strong id="s_cur" class="text-success"></strong>
        </p>
        <div class="form-group mb-12">
          <label>Adjustment Amount</label>
          <input type="number" name="adjustment" required placeholder="+10 to add  /  -5 to remove">
          <small class="muted-small">Positive = add stock &nbsp;|&nbsp; Negative = remove stock</small>
        </div>
        <div class="form-group">
          <label>Reason</label>
          <input type="text" name="reason" value="Admin adjustment" placeholder="e.g. New delivery, Damaged goods">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="mfbtn mfbtn-cancel" onclick="closeModal('stockModal')">Cancel</button>
        <button type="submit" class="mfbtn mfbtn-primary">Apply</button>
      </div>
    </form>
  </div>
</div>

<script>
let selectedEditFiles = [];
function openCreate(){
  document.getElementById('createModal').classList.add('open');
}

function closeModal(id){

  document.getElementById(id).classList.remove('open');

  if(id === 'editModal'){

    selectedEditFiles = [];

    document.getElementById('e_preview_wrap').innerHTML = '';

    document.getElementById('e_images').value = '';

  }

}

function openStock(id, name, stock){

  document.getElementById('s_id').value = id;
  document.getElementById('s_name').innerText = name;
  document.getElementById('s_cur').innerText = stock;

  document.getElementById('stockModal')
    .classList.add('open');
}

function openEdit(p, cats){

  document.getElementById('e_id').value    = p.id;
  document.getElementById('e_name').value  = p.name || '';
  document.getElementById('e_model').value = p.model_number || '';
  document.getElementById('e_brand').value = p.brand || '';
  document.getElementById('e_energy').value= p.energy_rating || '';
  document.getElementById('e_hp').value    = p.horsepower || '';
  document.getElementById('e_btu').value   = p.cooling_capacity_btu || '';
  document.getElementById('e_price').value = p.price || '';
  document.getElementById('e_stock').value = p.stock_qty || 0;
  document.getElementById('e_desc').value  = p.description || '';

  document.getElementById('e_active').value =
    p.is_active ? 1 : 0;

  // CATEGORY
  var sel = document.getElementById('e_cat');

  sel.innerHTML = '<option value="">— Select —</option>';

  cats.forEach(function(c){

    var o = document.createElement('option');

    o.value = c.id;
    o.text = c.name;

    if(c.id == p.category_id){
      o.selected = true;
    }

    sel.appendChild(o);

  });

  // IMAGES

  
  let images = p.image_urls || [];

  document.getElementById('e_existing_images').value =
    JSON.stringify(images);

  renderExistingImages(images);

  document.getElementById('editModal')
    .classList.add('open');
}

function deleteSelectedImages(){

  let existingImages = JSON.parse(
    document.getElementById('e_existing_images').value
  );

  const checked = document.querySelectorAll(
    '.delete-image-checkbox:checked'
  );

  let indexes = [];

  checked.forEach(cb => {
    indexes.push(parseInt(cb.value));
  });

  existingImages = existingImages.filter(
    (img, index) => !indexes.includes(index)
  );

  document.getElementById('e_existing_images').value =
    JSON.stringify(existingImages);

  renderExistingImages(existingImages);

}

function renderExistingImages(images){

  const wrap = document.getElementById('existing_images_wrap');

  wrap.innerHTML = '';

  images.forEach((img, index) => {

    const box = document.createElement('div');
    box.className = 'img-box';

    box.innerHTML = `
      <img src="${img}">

      <input type="checkbox" class="delete-image-checkbox img-checkbox" value="${index}">
    `;

    wrap.appendChild(box);

  });

}

function previewMultiple(input, wrapId){
  function removeSelectedImage(index, wrapId){

  selectedEditFiles.splice(index, 1);

  const input = document.getElementById('e_images');

  const dt = new DataTransfer();

  selectedEditFiles.forEach(file => {
    dt.items.add(file);
  });

  input.files = dt.files;

  const wrap = document.getElementById(wrapId);

  wrap.innerHTML = '';

  selectedEditFiles.forEach((file, i) => {

    const reader = new FileReader();

    reader.onload = function(e){

      const box = document.createElement('div');

      box.className = 'img-box';

      box.innerHTML = `
        <img src="${e.target.result}">

        <button type="button" class="img-remove-btn" onclick="removeSelectedImage(${i}, '${wrapId}')">×</button>
      `;

      wrap.appendChild(box);

    };

    reader.readAsDataURL(file);

  });

}

  const wrap = document.getElementById(wrapId);

  // ADD newly selected files
  Array.from(input.files).forEach(file => {
    selectedEditFiles.push(file);
  });

  // CLEAR preview
  wrap.innerHTML = '';

  // CREATE DataTransfer
  const dt = new DataTransfer();

  // SHOW ALL selected files
  selectedEditFiles.forEach((file, index) => {

    dt.items.add(file);

    const reader = new FileReader();

    reader.onload = function(e){

      const box = document.createElement('div');

        box.className = 'img-box';

        box.innerHTML = `
          <img src="${e.target.result}">

          <button type="button" class="img-remove-btn" onclick="removeSelectedImage(${index}, '${wrapId}')">×</button>
        `;

      wrap.appendChild(box);

    };

    reader.readAsDataURL(file);

  });

  // IMPORTANT
  input.files = dt.files;

}
 
 function openReviews(productName, reviews){
  console.log(reviews);

  document.getElementById('reviewProductTitle').innerText =
    productName + ' Reviews';

  const container =
    document.getElementById('reviewsContainer');

  container.innerHTML = '';

  if(!reviews || reviews.length === 0){

    container.innerHTML = `
      <div class="review-empty">No reviews yet.</div>
    `;

  } else {

    reviews.forEach(r => {

      let stars = '';

      for(let i = 1; i <= 5; i++){

        stars += i <= r.rating
          ? '⭐'
          : '☆';
      }

      container.innerHTML += `
        <div class="review-item">
          <div class="review-row">
            <strong>${r.first_name || ''} ${r.last_name || ''}</strong>
            <span class="review-stars">${stars}</span>
          </div>
          <div class="review-body">${r.comment || ''}</div>
        </div>
      `;
    });
  }

  document.getElementById('reviewsModal')
    .classList.add('open');
}
</script>


<!-- REVIEWS MODAL -->
<div class="modal-overlay" id="reviewsModal">
  <div class="modal modal-small">

    <div class="modal-header">
      <h2 id="reviewProductTitle">Product Reviews</h2>

      <button class="modal-close"
              onclick="closeModal('reviewsModal')">
        &times;
      </button>
    </div>

    <div class="modal-body">

      <div id="reviewsContainer">

      </div>

    </div>

  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>