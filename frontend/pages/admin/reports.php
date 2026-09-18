<?php
require_once __DIR__ . '/../../includes/config.php';
require_admin();
header('Location: /rg-trading-php/pages/admin/dashboard.php');
exit;
?>

<!-- admin reports CSS moved to assets/css/style.css -->

<div class="admin-layout">
  <?php include __DIR__ . '/../../includes/admin-sidebar.php'; ?>

  <div class="admin-main">
    <div class="admin-header admin-header--split">
      <div><h1>Sales Reports</h1><p>Analytics and business performance overview</p></div>
      <form method="GET" class="form-inline">
        <label class="muted-small">Period:</label>
        <select name="period" onchange="this.form.submit()" class="form-select">
          <option value="7"  <?= $period===7?'selected':'' ?>>Last 7 days</option>
          <option value="30" <?= $period===30?'selected':'' ?>>Last 30 days</option>
          <option value="90" <?= $period===90?'selected':'' ?>>Last 90 days</option>
        </select>
      </form>
    </div>

    <!-- KPI Cards -->
    <div class="stats-grid">
      <?php
      $rev = $s['revenue'] ?? [];
      $ord = $s['orders']  ?? [];
      if (empty($ord)) {
          $ord = [
              'period_orders' => $order_status_counts['pending'] + $order_status_counts['delivered'] + $order_status_counts['other'],
              'pending_orders' => $order_status_counts['pending'],
              'delivered_orders' => $order_status_counts['delivered'],
              'total_orders' => $order_status_counts['pending'] + $order_status_counts['delivered'] + $order_status_counts['other'],
          ];
      }
      $cus = $s['customers'] ?? [];
      $growth = $rev['growth_pct'] ?? null;
      ?>
      <div class="stat-card">
        <div class="stat-label">Total Revenue</div>
        <div class="stat-value">₱<?= number_format(floatval($rev['total'] ?? 0), 0) ?></div>
        <div class="stat-sub">All time</div>
        <?php if ($growth !== null): ?>
          <div class="stat-growth <?= $growth >= 0 ? 'up' : 'down' ?>">
            <?= $growth >= 0 ? '↑' : '↓' ?> <?= abs($growth) ?>% vs prev period
          </div>
        <?php endif; ?>
      </div>
      <div class="stat-card green">
        <div class="stat-label">Orders (<?= $period ?>d)</div>
        <div class="stat-value"><?= number_format($ord['period_orders'] ?? 0) ?></div>
        <div class="stat-sub"><?= $ord['pending_orders'] ?? 0 ?> pending · <?= $ord['delivered_orders'] ?? 0 ?> delivered</div>
      </div>
      <div class="stat-card orange">
        <div class="stat-label">Customers</div>
        <div class="stat-value"><?= number_format($cus['total_customers'] ?? 0) ?></div>
        <div class="stat-sub">+<?= $cus['new_customers'] ?? 0 ?> new · <?= $cus['repeat_customers'] ?? 0 ?> repeat</div>
      </div>
      <div class="stat-card purple">
        <div class="stat-label">Period Revenue</div>
        <div class="stat-value">₱<?= number_format(floatval($rev['period'] ?? 0), 0) ?></div>
        <div class="stat-sub">Last <?= $period ?> days</div>
      </div>
    </div>

    <div class="report-grid">
      <!-- Revenue Trend Chart -->
      <div class="admin-card">
        <div class="admin-card-header"><h3>Revenue Trend</h3></div>
        <div class="admin-card-body">
          <div class="chart-wrap"><canvas id="revenueChart"></canvas></div>
        </div>
      </div>

      <!-- Seasonal Demand Chart -->
      <div class="admin-card">
        <div class="admin-card-header"><h3>Monthly Sales Pattern</h3></div>
        <div class="admin-card-body">
          <div class="chart-wrap"><canvas id="seasonChart"></canvas></div>
        </div>
      </div>
    </div>

    <div class="report-grid">
      <div class="admin-card">
        <div class="admin-card-header"><h3>Order Status Distribution</h3></div>
        <div class="admin-card-body">
          <div class="chart-wrap"><canvas id="orderStatusPieChart"></canvas></div>
        </div>
      </div>

      <div class="admin-card">
        <div class="admin-card-header"><h3>Payment Methods</h3></div>
        <div class="admin-card-body">
          <div class="chart-wrap"><canvas id="paymentMethodPieChart"></canvas></div>
        </div>
      </div>
    </div>

    <div class="report-grid">
      <div class="admin-card">
        <div class="admin-card-header"><h3>Peak Sales Periods</h3></div>
        <div class="admin-card-body">
          <div class="chart-wrap"><canvas id="peakHoursChart"></canvas></div>
        </div>
      </div>
      <div class="admin-card">
        <div class="admin-card-header"><h3>Top Product Sales (Revenue)</h3></div>
        <div class="admin-card-body">
          <div class="chart-wrap"><canvas id="topProductSalesChart"></canvas></div>
        </div>
      </div>
    </div>

    <div class="report-grid">
      <!-- Top Products -->
      <div class="admin-card">
        <div class="admin-card-header"><h3>Top Selling Products</h3></div>
        <div class="admin-card-body card-no-padding">
          <table class="data-table">
            <thead><tr><th>Product</th><th class="text-right">Units Sold</th><th class="text-right">Revenue</th></tr></thead>
            <tbody>
              <?php foreach (array_slice($top_prods, 0, 8) as $tp): ?>
                <tr>
                  <td>
                    <div class="font-700 text-small"><?= h($tp['name']) ?></div>
                    <div class="muted-small"><?= h($tp['model_number'] ?? '') ?></div>
                  </td>
                  <td class="text-right font-700"><?= number_format($tp['units_sold'] ?? 0) ?></td>
                  <td class="text-right font-700 text-success"><?= format_price($tp['total_revenue'] ?? $tp['revenue_generated'] ?? 0) ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($top_prods)): ?>
                <tr><td colspan="3" class="table-empty">No sales data yet.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Repeat Customers -->
      <div class="admin-card">
        <div class="admin-card-header"><h3>Repeat Customers</h3></div>
        <div class="admin-card-body card-no-padding">
          <table class="data-table">
            <thead><tr><th>Customer</th><th class="text-center">Orders</th><th class="text-right">Lifetime Value</th></tr></thead>
            <tbody>
              <?php foreach (array_slice($repeat, 0, 8) as $r): ?>
                <tr>
                  <td>
                    <div class="font-700"><?= h(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?></div>
                    <div class="muted-small"><?= h($r['email'] ?? '') ?></div>
                  </td>
                  <td class="text-center"><span class="badge badge-soft-blue"><?= $r['order_count'] ?? 0 ?></span></td>
                  <td class="text-right font-700 text-accent-dark"><?= format_price($r['total_spent'] ?? 0) ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($repeat)): ?>
                <tr><td colspan="3" class="table-empty">No repeat customers yet.</td></tr>
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
var revData  = <?= json_encode($rev_data) ?>;
var seasData = <?= json_encode($season) ?>;
var orderStats = <?= json_encode($ord) ?>;
var paymentMethodCounts = <?= json_encode($payment_method_counts) ?>;
var peakHoursData = <?= json_encode($peak_hrs) ?>;
var topProductData = <?= json_encode(array_slice($top_prods, 0, 10)) ?>;

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

if(revData && revData.length){
  new Chart(document.getElementById('revenueChart'), {
    type:'line',
    data:{
      labels: revData.map(function(d){ return d.period || d.date || ''; }),
      datasets:[{
        label:'Revenue (₱)',
        data: revData.map(function(d){ return parseFloat(d.revenue || d.total_revenue || 0); }),
        borderColor:'#3182ce',
        backgroundColor:'rgba(49,130,206,.08)',
        tension:0.4, fill:true, pointRadius:3,
      }]
    },
    options:{ responsive:true, maintainAspectRatio:false,
      plugins:{ legend:{ display:false } },
      scales:{ y:{ beginAtZero:true, ticks:{ callback:function(v){ return '₱'+v.toLocaleString(); } } } }
    }
  });
} else {
  createEmptyChartMessage('revenueChart', 'No revenue trend data for this period.');
}

if(seasData && seasData.length){
  var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  new Chart(document.getElementById('seasonChart'), {
    type:'bar',
    data:{
      labels: seasData.map(function(d){ return months[(parseInt(d.month||1)-1)] || d.month; }),
      datasets:[{
        label:'Sales (₱)',
        data: seasData.map(function(d){ return parseFloat(d.revenue || d.total_revenue || 0); }),
        backgroundColor:'rgba(56,161,105,.7)',
        borderRadius:6,
      }]
    },
    options:{ responsive:true, maintainAspectRatio:false,
      plugins:{ legend:{ display:false } },
      scales:{ y:{ beginAtZero:true, ticks:{ callback:function(v){ return '₱'+v.toLocaleString(); } } } }
    }
  });
} else {
  createEmptyChartMessage('seasonChart', 'No seasonal sales data yet.');
}

if (orderStats) {
  var pendingCount = Number(orderStats.pending_orders || 0);
  var deliveredCount = Number(orderStats.delivered_orders || 0);
  var totalOrders = Number(orderStats.total_orders || 0);
  var othersCount = Math.max(totalOrders - pendingCount - deliveredCount, 0);
  var pieValues = [pendingCount, deliveredCount, othersCount];
  var pieTotal = pieValues.reduce(function(sum, val){ return sum + val; }, 0);

  if (pieTotal > 0) {
    new Chart(document.getElementById('orderStatusPieChart'), {
      type: 'pie',
      data: {
        labels: ['Pending', 'Delivered', 'Other Statuses'],
        datasets: [{
          data: pieValues,
          backgroundColor: ['#f6ad55', '#48bb78', '#63b3ed'],
          borderColor: '#ffffff',
          borderWidth: 2
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom' },
          tooltip: {
            callbacks: {
              label: function(ctx) {
                var value = Number(ctx.parsed || 0);
                var percent = pieTotal ? ((value / pieTotal) * 100).toFixed(1) : '0.0';
                return ctx.label + ': ' + value.toLocaleString() + ' (' + percent + '%)';
              }
            }
          }
        }
      }
    });
  } else {
    createEmptyChartMessage('orderStatusPieChart', 'No order data yet.');
  }
} else {
  createEmptyChartMessage('orderStatusPieChart', 'Order summary unavailable.');
}

if (paymentMethodCounts && Object.keys(paymentMethodCounts).length) {
  var methodLabels = Object.keys(paymentMethodCounts).map(function(key) {
    return key.replace(/_/g, ' ').replace(/\b\w/g, function(char) { return char.toUpperCase(); });
  });
  var methodValues = Object.keys(paymentMethodCounts).map(function(key) {
    return Number(paymentMethodCounts[key] || 0);
  });
  var methodTotal = methodValues.reduce(function(sum, val){ return sum + val; }, 0);

  if (methodTotal > 0) {
    new Chart(document.getElementById('paymentMethodPieChart'), {
      type: 'pie',
      data: {
        labels: methodLabels,
        datasets: [{
          data: methodValues,
          backgroundColor: ['#3182ce', '#38a169', '#ed8936', '#805ad5', '#e53e3e', '#718096'],
          borderColor: '#ffffff',
          borderWidth: 2
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom' },
          tooltip: {
            callbacks: {
              label: function(ctx) {
                var value = Number(ctx.parsed || 0);
                var percent = methodTotal ? ((value / methodTotal) * 100).toFixed(1) : '0.0';
                return ctx.label + ': ' + value.toLocaleString() + ' (' + percent + '%)';
              }
            }
          }
        }
      }
    });
  } else {
    createEmptyChartMessage('paymentMethodPieChart', 'No payment method data yet.');
  }
} else {
  createEmptyChartMessage('paymentMethodPieChart', 'No payment method data yet.');
}

if (peakHoursData && peakHoursData.length) {
  new Chart(document.getElementById('peakHoursChart'), {
    type: 'bar',
    data: {
      labels: peakHoursData.map(function(d){ return d.hour || d.label || ''; }),
      datasets: [{
        label: 'Orders',
        data: peakHoursData.map(function(d){ return Number(d.orders || d.count || 0); }),
        backgroundColor: 'rgba(66, 153, 225, 0.75)',
        borderRadius: 8,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        y: { beginAtZero: true, ticks: { precision: 0 } }
      }
    }
  });
} else {
  createEmptyChartMessage('peakHoursChart', 'No peak sales data available.');
}

if (topProductData && topProductData.length) {
  var productLabels = topProductData.map(function(item){
    return item.name || item.model_number || 'Unknown Product';
  });
  var productSales = topProductData.map(function(item){
    return Number(item.total_revenue || item.revenue_generated || 0);
  });

  new Chart(document.getElementById('topProductSalesChart'), {
    type: 'bar',
    data: {
      labels: productLabels,
      datasets: [{
        label: 'Revenue (₱)',
        data: productSales,
        backgroundColor: 'rgba(56, 161, 105, 0.8)',
        borderRadius: 8
      }]
    },
    options: {
      indexAxis: 'y',
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: function(ctx) {
              return 'Revenue: ₱' + Number(ctx.parsed.x || 0).toLocaleString();
            }
          }
        }
      },
      scales: {
        x: {
          beginAtZero: true,
          ticks: {
            callback: function(v){ return '₱' + Number(v).toLocaleString(); }
          }
        }
      }
    }
  });
} else {
  createEmptyChartMessage('topProductSalesChart', 'No product sales data yet.');
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
