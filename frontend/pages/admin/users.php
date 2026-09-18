<?php
require_once __DIR__ . '/../../includes/config.php';
require_admin();

$role   = $_GET['role']   ?? '';
$search = trim($_GET['search'] ?? '');
$page   = max(1, intval($_GET['page'] ?? 1));
$limit  = 10;
$params = http_build_query(array_filter(['role' => $role, 'search' => $search, 'page' => $page, 'limit' => $limit]));

$result     = api_request('GET', '/admin/users?' . $params, [], true);
$users      = $result['body']['data']['users']      ?? [];
$pagination = $result['body']['data']['pagination'] ?? [];
$total_pages = ceil(($pagination['total'] ?? 0) / $limit);

// Handle toggle status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_user_id'])) {
    $toggle_result = api_request('PATCH', '/admin/users/' . $_POST['toggle_user_id'] . '/toggle-status', [], true);
    set_flash($toggle_result['status'] === 200 ? 'success' : 'error',
              $toggle_result['body']['message'] ?? 'Could not update user.');
    header('Location: /rg-trading-php/pages/admin/users.php');
    exit;
}

$page_title = 'Users — Admin — ' . APP_NAME;
include __DIR__ . '/../../includes/header.php';
?>

<div class="admin-layout">
  <?php include __DIR__ . '/../../includes/admin-sidebar.php'; ?>

  <div class="admin-main">
    <div class="admin-header">
      <h1>Users</h1>
      <p>Manage customer and admin accounts</p>
    </div>

    <!-- Role Filter -->
    <div class="filters-wrap">
      <?php foreach (['' => 'All Users', 'customer' => 'Customers', 'admin' => 'Admins'] as $val => $label): ?>
        <a href="?role=<?= urlencode($val) ?>" class="pill <?= $role === $val ? 'active' : '' ?>">
          <?= $label ?>
        </a>
      <?php endforeach; ?>
    </div>

    <form method="GET" class="search-bar mb-20">
      <input type="hidden" name="role" value="<?= h($role) ?>">
      <input type="text" name="search" placeholder="Search by name or email..." value="<?= h($search) ?>">
      <button type="submit">Search</button>
    </form>

    <div class="admin-card">
      <div class="admin-card-header">
        <h3>Users (<?= $pagination['total'] ?? 0 ?>)</h3>
      </div>
      <div class="admin-card-body card-no-padding">
        <table class="data-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Email</th>
              <th>Phone</th>
              <th>Role</th>
              <th>Last Login</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $u): ?>
              <tr>
                <td><strong><?= h($u['first_name'] . ' ' . $u['last_name']) ?></strong></td>
                <td class="muted-small"><?= h($u['email']) ?></td>
                <td class="text-small"><?= h($u['phone'] ?? '—') ?></td>
                <td>
                  <?php
                    $roleClassMap = [
                      'admin' => 'badge-processing',
                      'customer' => 'badge-soft-blue',
                    ];
                    $roleClass = $roleClassMap[$u['role']] ?? '';
                  ?>
                  <span class="badge <?= $roleClass ?>"><?= h($u['role']) ?></span>
                </td>
                <td class="muted-small">
                  <?= $u['last_login_at'] ? date('M d, Y', strtotime($u['last_login_at'])) : 'Never' ?>
                </td>
                <td>
                  <span class="badge <?= $u['is_active'] ? 'badge-soft-green' : 'badge-soft-red' ?>">
                    <?= $u['is_active'] ? 'Active' : 'Inactive' ?>
                  </span>
                </td>
                <td>
                  <?php if ($u['id'] !== (current_user()['id'] ?? '')): ?>
                    <form method="POST">
                      <input type="hidden" name="toggle_user_id" value="<?= h($u['id']) ?>">
                      <button type="submit"
                              class="btn-sm <?= $u['is_active'] ? 'btn-sm-red' : 'btn-sm-green' ?>"
                              data-confirm="<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?> this user?">
                        <?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>
                      </button>
                    </form>
                  <?php else: ?>
                    <span class="table-cell-muted">You</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($users)): ?>
              <tr><td colspan="7" class="table-empty">No users found</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Pagination -->
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

<?php include __DIR__ . '/../../includes/footer.php'; ?>