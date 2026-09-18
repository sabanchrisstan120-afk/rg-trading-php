<?php
require_once __DIR__ . '/../../includes/config.php';
require_admin();

$period  = intval($_GET['period'] ?? 30);
$months  = max(1, (int)ceil($period / 30));
$summary = api_request('GET', '/admin/dashboard/summary?period=' . $period, [], true);
$data    = $summary['body']['data'] ?? [];

$rev       = $data['revenue']    ?? [];
$orders    = $data['orders']     ?? [];
$customers = $data['customers']  ?? [];
$top       = $data['top_product'] ?? null;

// Revenue trends for chart
$trends_result = api_request('GET', '/admin/dashboard/revenue-trends?granularity=day&months=' . $months, [], true);
$trends        = $trends_result['body']['data']['trends'] ?? [];

// Seasonal demand and peak periods
$seasonal_result = api_request('GET', '/admin/dashboard/seasonal-demand', [], true);
$seasonal        = $seasonal_result['body']['data']['months'] ?? [];
$peak_result     = api_request('GET', '/admin/dashboard/peak-periods', [], true);
$peak_hours      = $peak_result['body']['data']['hours'] ?? [];

// Repeat customers and top products
$repeat_result     = api_request('GET', '/admin/dashboard/repeat-customers', [], true);
$repeat_customers  = $repeat_result['body']['data']['customers'] ?? [];
$top_products_result = api_request('GET', '/admin/dashboard/top-products?limit=5', [], true);
$top_products        = $top_products_result['body']['data']['products'] ?? [];

// Payment methods from order data
$orders_for_payments = api_request('GET', '/admin/orders?limit=1000', [], true);
$payment_rows = $orders_for_payments['body']['data']['orders'] ?? $orders_for_payments['body']['orders'] ?? [];
$payment_method_counts = [];
foreach ($payment_rows as $row) {
    $method = trim((string)($row['payment_method'] ?? 'unknown'));
    if ($method === '') {
        $method = 'unknown';
    }
    if (!isset($payment_method_counts[$method])) {
        $payment_method_counts[$method] = 0;
    }
    $payment_method_counts[$method]++;
}

// Recent orders for dashboard table
$recent_orders_result = api_request('GET', '/admin/orders?limit=5', [], true);
$recent_orders = $recent_orders_result['body']['data']['orders'] ?? $recent_orders_result['body']['orders'] ?? [];

$page_title = 'Admin Dashboard — ' . APP_NAME;
include __DIR__ . '/../../includes/header.php';
?>

<div class="admin-layout">

  <?php include __DIR__ . '/../../includes/admin-sidebar.php'; ?>

  <!-- Main Content -->
  <div class="admin-main">
    <div class="admin-header">
      <h1>Dashboard</h1>
      <p>Welcome back, <?= h(current_user()['first_name'] ?? 'Admin') ?>. Here's what's happening.</p>
    </div>

    <!-- Period Filter -->
    <div class="display-flex gap-8 mb-24 align-items-center flex-wrap">
      <?php foreach ([7 => '7 days', 30 => '30 days', 90 => '90 days'] as $val => $label): ?>
        <a href="?period=<?= $val ?>" class="pill <?= $period === $val ? 'active' : '' ?>">
          <?= $label ?>
        </a>
      <?php endforeach; ?>
      <a href="<?= BASE_URL ?>/pages/admin/dashboard-export.php?period=<?= $period ?>" class="btn btn-primary">Export to Excel</a>
    </div>

    <!-- KPI Cards -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-label">Revenue (<?= $period ?>d)</div>
        <div class="stat-value"><?= format_price($rev['period'] ?? 0) ?></div>
        <div class="stat-sub">
          Total: <?= format_price($rev['total'] ?? 0) ?>
          <?php $growth = isset($rev['growth_pct']) ? $rev['growth_pct'] : null; ?>
          <?php if ($growth !== null && $growth !== ''): ?>
            · <span class="<?= $growth >= 0 ? 'text-success' : 'text-danger' ?>">
              <?= $growth >= 0 ? '↑' : '↓' ?> <?= abs($growth) ?>%
            </span>
          <?php endif; ?>
        </div>
      </div>
      <div class="stat-card green">
        <div class="stat-label">Orders (<?= $period ?>d)</div>
        <div class="stat-value"><?= $orders['period_orders'] ?? 0 ?></div>
        <div class="stat-sub">Total: <?= $orders['total_orders'] ?? 0 ?> · Pending: <?= $orders['pending_orders'] ?? 0 ?></div>
      </div>
      <div class="stat-card orange">
        <div class="stat-label">New Customers (<?= $period ?>d)</div>
        <div class="stat-value"><?= $customers['new_customers'] ?? 0 ?></div>
        <div class="stat-sub">Total: <?= $customers['total_customers'] ?? 0 ?> · Repeat: <?= $customers['repeat_customers'] ?? 0 ?></div>
      </div>
      <div class="stat-card red">
        <div class="stat-label">Top Product</div>
        <div class="stat-value text-16"><?= h($top['name'] ?? 'N/A') ?></div>
        <div class="stat-sub"><?= $top ? $top['units_sold'] . ' units sold' : 'No data yet' ?></div>
      </div>
    </div>

    <div class="report-grid">
      <div class="admin-card">
        <div class="admin-card-header"><h3>Revenue Trend</h3></div>
        <div class="admin-card-body"><div class="chart-wrap"><canvas id="revenueChart"></canvas></div></div>
      </div>
      <div class="admin-card">
        <div class="admin-card-header"><h3>Monthly Sales Pattern</h3></div>
        <div class="admin-card-body"><div class="chart-wrap"><canvas id="seasonChart"></canvas></div></div>
      </div>
    </div>

    <div class="report-grid">
      <div class="admin-card">
        <div class="admin-card-header"><h3>Order Status Distribution</h3></div>
        <div class="admin-card-body"><div class="chart-wrap"><canvas id="orderStatusPieChart"></canvas></div></div>
      </div>
      <div class="admin-card">
        <div class="admin-card-header"><h3>Payment Methods</h3></div>
        <div class="admin-card-body"><div class="chart-wrap"><canvas id="paymentMethodPieChart"></canvas></div></div>
      </div>
    </div>

    <div class="report-grid">
      <div class="admin-card">
        <div class="admin-card-header"><h3>Peak Sales Periods</h3></div>
        <div class="admin-card-body"><div class="chart-wrap"><canvas id="peakHoursChart"></canvas></div></div>
      </div>
      <div class="admin-card">
        <div class="admin-card-header"><h3>Top Product Revenue</h3></div>
        <div class="admin-card-body"><div class="chart-wrap"><canvas id="topProductSalesChart"></canvas></div></div>
      </div>
    </div>

    <div class="report-grid">
      <div class="admin-card">
        <div class="admin-card-header"><h3>Repeat Customers</h3></div>
        <div class="admin-card-body p-0">
          <table class="data-table">
            <thead><tr><th>Customer</th><th class="text-center">Orders</th><th class="text-right">Lifetime Value</th></tr></thead>
            <tbody>
              <?php foreach (array_slice($repeat_customers, 0, 8) as $r): ?>
                <tr>
                  <td>
                    <div class="font-600"><?= h(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))) ?></div>
                    <div class="text-11 text-muted-light"><?= h($r['email'] ?? '') ?></div>
                  </td>
                  <td class="text-center"><?= number_format($r['order_count'] ?? 0) ?></td>
                  <td class="text-right font-700 text-strong-dark"><?= format_price(floatval($r['total_spent'] ?? 0)) ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($repeat_customers)): ?>
                <tr><td colspan="3" class="text-center text-muted-light p-24">No repeat customer data yet.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="display-grid gap-20 grid-2">

      <!-- Top Products -->
      <div class="admin-card">
        <div class="admin-card-header">
          <h3>Top Products</h3>
          <a href="/rg-trading-php/pages/admin/products.php" class="text-12 text-blue">View all →</a>
        </div>
        <div class="admin-card-body p-0">
          <table class="data-table">
            <thead>
              <tr><th>Product</th><th>Units Sold</th><th>Revenue</th></tr>
            </thead>
            <tbody>
              <?php foreach ($top_products as $p): ?>
                <tr>
                  <td>
                    <div class="font-600 text-12"><?= h($p['name']) ?></div>
                    <div class="text-11 text-muted-light"><?= h($p['brand']) ?></div>
                  </td>
                  <td><?= $p['units_sold'] ?></td>
                  <td><?= format_price($p['revenue_generated']) ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($top_products)): ?>
                <tr><td colspan="3" class="text-center text-muted-light p-20">No sales data yet</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Recent Orders -->
      <div class="admin-card">
        <div class="admin-card-header">
          <h3>Recent Orders</h3>
          <a href="/rg-trading-php/pages/admin/orders.php" class="text-12 text-blue">View all →</a>
        </div>
        <div class="admin-card-body p-0">
          <table class="data-table">
            <thead>
              <tr><th>Order</th><th>Customer</th><th>Total</th><th>Status</th></tr>
            </thead>
            <tbody>
              <?php foreach ($recent_orders as $o): ?>
                <tr>
                  <td class="font-600 text-12"><?= h($o['order_number']) ?></td>
                  <td class="text-12"><?= h($o['first_name'] . ' ' . $o['last_name']) ?></td>
                  <td><?= format_price($o['total_amount']) ?></td>
                  <td><span class="badge badge-<?= h($o['status']) ?>"><?= h($o['status']) ?></span></td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($recent_orders)): ?>
                <tr><td colspan="4" class="text-center text-muted-light p-20">No orders yet</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
var trendsData = <?= json_encode($trends) ?>;
var seasonData = <?= json_encode($seasonal) ?>;
var orderStats = <?= json_encode($orders) ?>;
var paymentMethodCounts = <?= json_encode($payment_method_counts) ?>;
var peakHoursData = <?= json_encode($peak_hours) ?>;
var topProductData = <?= json_encode($top_products) ?>;

function createEmptyChartMessage(canvasId, message) {
  var canvas = document.getElementById(canvasId);
  if (!canvas || !canvas.parentElement) return;
  canvas.style.display = 'none';
  var el = document.createElement('div');
  el.style.display = 'flex';
  el.style.alignItems = 'center';
  el.style.justifyContent = 'center';
  el.style.height = '100%';
  el.style.minHeight = '220px';
  el.style.color = '#a0aec0';
  el.style.fontSize = '13px';
  el.textContent = message;
  canvas.parentElement.appendChild(el);
}

if (trendsData && trendsData.length) {
  new Chart(document.getElementById('revenueChart'), {
    type: 'line',
    data: {
      labels: trendsData.map(function(d){ return d.period || d.date || ''; }),
      datasets: [{
        label: 'Revenue (₱)',
        data: trendsData.map(function(d){ return Number(d.revenue || d.total_revenue || 0); }),
        borderColor: '#3182ce',
        backgroundColor: 'rgba(49,130,206,0.12)',
        tension: 0.35,
        fill: true,
        pointRadius: 3,
      }]
    },
    options: { responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true, ticks: { callback: function(v){ return '₱' + Number(v).toLocaleString(); } } } }
    }
  });
} else {
  createEmptyChartMessage('revenueChart', 'No revenue trend data yet.');
}

if (seasonData && seasonData.length) {
  var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  new Chart(document.getElementById('seasonChart'), {
    type: 'bar',
    data: {
      labels: seasonData.map(function(d){ return months[(parseInt(d.month || 1) - 1)] || d.month || ''; }),
      datasets: [{
        label: 'Sales (₱)',
        data: seasonData.map(function(d){ return Number(d.revenue || d.total_revenue || 0); }),
        backgroundColor: 'rgba(56,161,105,0.7)',
        borderRadius: 6,
      }]
    },
    options: { responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true, ticks: { callback: function(v){ return '₱' + Number(v).toLocaleString(); } } } }
    }
  });
} else {
  createEmptyChartMessage('seasonChart', 'No seasonal demand data yet.');
}

if (orderStats) {
  var pending = Number(orderStats.pending_orders || 0);
  var delivered = Number(orderStats.delivered_orders || 0);
  var total = Number(orderStats.total_orders || pending + delivered);
  var other = Math.max(total - pending - delivered, 0);
  var values = [pending, delivered, other];
  var totalCount = values.reduce(function(sum, value){ return sum + value; }, 0);
  if (totalCount > 0) {
    new Chart(document.getElementById('orderStatusPieChart'), {
      type: 'pie',
      data: {
        labels: ['Pending','Delivered','Other'],
        datasets: [{
          data: values,
          backgroundColor: ['#f6ad55','#48bb78','#63b3ed'],
          borderColor: '#fff',
          borderWidth: 2,
        }]
      },
      options: { responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: function(ctx){ var v = Number(ctx.parsed || 0); var pct = totalCount ? ((v/totalCount)*100).toFixed(1) : '0.0'; return ctx.label + ': ' + v.toLocaleString() + ' (' + pct + '%)'; } } } }
      }
    });
  } else {
    createEmptyChartMessage('orderStatusPieChart', 'No order status data available.');
  }
} else {
  createEmptyChartMessage('orderStatusPieChart', 'No order status data available.');
}

if (paymentMethodCounts && Object.keys(paymentMethodCounts).length) {
  var labels = Object.keys(paymentMethodCounts).map(function(k){ return k.replace(/_/g, ' ').replace(/\b\w/g, function(ch){ return ch.toUpperCase(); }); });
  var values = Object.keys(paymentMethodCounts).map(function(k){ return Number(paymentMethodCounts[k] || 0); });
  var totalPayment = values.reduce(function(sum, value){ return sum + value; }, 0);
  if (totalPayment > 0) {
    new Chart(document.getElementById('paymentMethodPieChart'), {
      type: 'pie',
      data: { labels: labels, datasets: [{ data: values, backgroundColor: ['#3182ce','#38a169','#ed8936','#805ad5','#e53e3e','#718096'], borderColor: '#fff', borderWidth: 2 }] },
      options: { responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: function(ctx){ var v = Number(ctx.parsed || 0); var pct = totalPayment ? ((v/totalPayment)*100).toFixed(1) : '0.0'; return ctx.label + ': ' + v.toLocaleString() + ' (' + pct + '%)'; } } } }
      }
    });
  } else {
    createEmptyChartMessage('paymentMethodPieChart', 'No payment method data available.');
  }
} else {
  createEmptyChartMessage('paymentMethodPieChart', 'No payment method data available.');
}

if (peakHoursData && peakHoursData.length) {
  new Chart(document.getElementById('peakHoursChart'), {
    type: 'bar',
    data: {
      labels: peakHoursData.map(function(d){ return d.hour || d.label || ''; }),
      datasets: [{ data: peakHoursData.map(function(d){ return Number(d.orders || d.count || 0); }), backgroundColor: 'rgba(66,153,225,0.75)', borderRadius: 8 }]
    },
    options: { responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
    }
  });
} else {
  createEmptyChartMessage('peakHoursChart', 'No peak sales data available.');
}

if (topProductData && topProductData.length) {
  var labels = topProductData.map(function(item){ return item.name || item.model || item.model_number || 'Product'; });
  var values = topProductData.map(function(item){ return Number(item.revenue_generated || item.total_revenue || item.revenue || 0); });
  new Chart(document.getElementById('topProductSalesChart'), {
    type: 'bar',
    data: { labels: labels, datasets: [{ label: 'Revenue (₱)', data: values, backgroundColor: 'rgba(56,161,105,0.8)', borderRadius: 8 }] },
    options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(ctx){ return 'Revenue: ₱' + Number(ctx.parsed.x || 0).toLocaleString(); } } } },
      scales: { x: { beginAtZero: true, ticks: { callback: function(v){ return '₱' + Number(v).toLocaleString(); } } } }
    }
  });
} else {
  createEmptyChartMessage('topProductSalesChart', 'No top product sales data yet.');
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
