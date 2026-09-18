<?php
require_once __DIR__ . '/../../includes/config.php';
require_admin();

$result = api_request('GET', '/products/admin/categories', [], true);
$categories = $result['body']['data']['categories'] ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'create') {
        $payload = [
            'name' => trim($_POST['name'] ?? ''),
            'slug' => trim($_POST['slug'] ?? ''),
            'description' => trim($_POST['description'] ?? '') ?: null,
        ];
        $res = api_request('POST', '/products/admin/categories', $payload, true);
        set_flash($res['status'] === 201 ? 'success' : 'error', $res['body']['message'] ?? 'Category create failed.');
        header('Location: /rg-trading-php/pages/admin/categories.php'); exit;
    }

    if ($action === 'update') {
        $id = intval($_POST['category_id']);
        $payload = [
            'name' => trim($_POST['name'] ?? ''),
            'slug' => trim($_POST['slug'] ?? ''),
            'description' => trim($_POST['description'] ?? '') ?: null,
        ];
        $res = api_request('PUT', '/products/admin/categories/' . $id, $payload, true);
        set_flash($res['status'] === 200 ? 'success' : 'error', $res['body']['message'] ?? 'Category update failed.');
        header('Location: /rg-trading-php/pages/admin/categories.php'); exit;
    }

    if ($action === 'delete') {
        $id = intval($_POST['category_id']);
        $res = api_request('DELETE', '/products/admin/categories/' . $id, [], true);
        set_flash($res['status'] === 200 ? 'success' : 'error', $res['body']['message'] ?? 'Category delete failed.');
        header('Location: /rg-trading-php/pages/admin/categories.php'); exit;
    }
}

$page_title = 'Categories — Admin — ' . APP_NAME;
include __DIR__ . '/../../includes/header.php';
?>

<div class="admin-layout">
  <?php include __DIR__ . '/../../includes/admin-sidebar.php'; ?>

  <div class="admin-main">
    <div class="admin-header admin-header-row">
      <div>
        <h1>Categories</h1>
        <p>Create and manage product categories</p>
      </div>
      <button class="add-btn" onclick="openCreate()">+ Add Category</button>
    </div>

    <div class="admin-card mt-16">
      <div class="admin-card-header">
        <h3>All Categories (<?= count($categories) ?>)</h3>
      </div>
      <div class="admin-card-body p-0">
        <table class="data-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Slug</th>
              <th>Description</th>
              <th>Products</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($categories as $c): ?>
              <tr>
                <td><strong><?= h($c['name']) ?></strong></td>
                <td class="text-mono-muted"><?= h($c['slug']) ?></td>
                <td class="text-muted-wrap"><?= h($c['description'] ?? '—') ?></td>
                <td>
                  <span class="badge badge-soft-blue">
                    <?= intval($c['product_count'] ?? 0) ?>
                  </span>
                </td>
                <td>
                  <div class="action-btns">
                    <button class="btn-sm btn-sm-blue"
                      onclick='openEdit(<?= json_encode($c) ?>)'>Edit</button>
                      <form method="POST" class="inline-form"
                      onsubmit="return confirm('Delete category &quot;<?= h(addslashes($c['name'])) ?>&quot;? Products in this category will be unlinked.');">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="category_id" value="<?= h($c['id']) ?>">
                      <button type="submit" class="btn-sm btn-sm-red">Delete</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($categories)): ?>
              <tr><td colspan="5" class="text-center text-xs-muted p-30">No categories found.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- CREATE MODAL -->
<div class="modal-overlay" id="createModal">
  <div class="modal">
    <div class="modal-header">
      <h2>Add Category</h2>
      <button class="modal-close" onclick="closeModal('createModal')">&times;</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="create">
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group">
            <label>Name *</label>
            <input type="text" name="name" required id="c_name" placeholder="e.g. Window Type">
          </div>
          <div class="form-group">
            <label>Slug *</label>
            <input type="text" name="slug" required id="c_slug" placeholder="e.g. window-type">
            <small class="form-note">Lowercase, numbers, hyphens only. Auto-filled from name.</small>
          </div>
          <div class="form-group full">
            <label>Description</label>
            <textarea name="description" placeholder="Optional description..."></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="mfbtn mfbtn-cancel" onclick="closeModal('createModal')">Cancel</button>
        <button type="submit" class="mfbtn mfbtn-primary">Create Category</button>
      </div>
        <!-- admin categories CSS moved to assets/css/style.css -->

<!-- EDIT MODAL -->
<div class="modal-overlay" id="editModal">
  <div class="modal">
    <div class="modal-header">
      <h2>Edit Category</h2>
      <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="category_id" id="e_id">
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group">
            <label>Name *</label>
            <input type="text" name="name" required id="e_name">
          </div>
          <div class="form-group">
            <label>Slug *</label>
            <input type="text" name="slug" required id="e_slug">
            <small class="form-note">Lowercase, numbers, hyphens only.</small>
          </div>
          <div class="form-group full">
            <label>Description</label>
            <textarea name="description" id="e_desc"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="mfbtn mfbtn-cancel" onclick="closeModal('editModal')">Cancel</button>
        <button type="submit" class="mfbtn mfbtn-primary">Update Category</button>
      </div>
    </form>
  </div>
</div>

<script>
function openCreate(){ document.getElementById('createModal').classList.add('open'); }

function openEdit(c){
  document.getElementById('e_id').value   = c.id;
  document.getElementById('e_name').value = c.name        || '';
  document.getElementById('e_slug').value = c.slug        || '';
  document.getElementById('e_desc').value = c.description || '';
  document.getElementById('editModal').classList.add('open');
}

function closeModal(id){ document.getElementById(id).classList.remove('open'); }

function toSlug(s){
  return (s||'').toLowerCase().trim()
    .replace(/[^a-z0-9]+/g,'-').replace(/-+/g,'-').replace(/^-|-$/g,'');
}

var cName = document.getElementById('c_name');
var cSlug = document.getElementById('c_slug');
if(cName && cSlug){
  cName.addEventListener('input', function(){
    if(!cSlug.dataset.touched) cSlug.value = toSlug(this.value);
  });
  cSlug.addEventListener('input', function(){
    this.dataset.touched = '1';
  });
}

document.querySelectorAll('.modal-overlay').forEach(function(o){
  o.addEventListener('click',function(e){ if(e.target===o) o.classList.remove('open'); });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
