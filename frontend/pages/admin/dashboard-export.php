<?php
require_once __DIR__ . '/../../includes/config.php';
require_admin();

require __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function safe_array($value): array {
    return is_array($value) ? $value : [];
}

function format_payment_method_label(string $method): string {
    $clean = trim(str_replace('_', ' ', strtolower($method)));
    if ($clean === '') {
        return 'Unknown';
    }

    return ucwords($clean);
}

try {
    $period = max(1, min(90, intval($_GET['period'] ?? 30)));
    $months = max(1, (int) ceil($period / 30));

    $summaryResult = api_request('GET', '/admin/dashboard/summary?period=' . $period, [], true);
    $summaryData = safe_array($summaryResult['body']['data'] ?? []);
    $rev = safe_array($summaryData['revenue'] ?? []);
    $orders = safe_array($summaryData['orders'] ?? []);
    $customers = safe_array($summaryData['customers'] ?? []);
    $topProduct = safe_array($summaryData['top_product'] ?? []);

    $trendsResult = api_request('GET', '/admin/dashboard/revenue-trends?granularity=day&months=' . $months, [], true);
    $trends = safe_array($trendsResult['body']['data']['trends'] ?? []);

    $seasonalResult = api_request('GET', '/admin/dashboard/seasonal-demand', [], true);
    $seasonal = safe_array($seasonalResult['body']['data']['months'] ?? []);

    $peakResult = api_request('GET', '/admin/dashboard/peak-periods', [], true);
    $peakHours = safe_array($peakResult['body']['data']['hours'] ?? []);

    $repeatResult = api_request('GET', '/admin/dashboard/repeat-customers', [], true);
    $repeatCustomers = safe_array($repeatResult['body']['data']['customers'] ?? []);

    $topProductsResult = api_request('GET', '/admin/dashboard/top-products?limit=5', [], true);
    $topProducts = safe_array($topProductsResult['body']['data']['products'] ?? []);

    $ordersForPayments = api_request('GET', '/admin/orders?limit=1000', [], true);
    $paymentRows = safe_array($ordersForPayments['body']['data']['orders'] ?? $ordersForPayments['body']['orders'] ?? []);
    $paymentMethodCounts = [];
    foreach ($paymentRows as $row) {
        $method = trim((string) ($row['payment_method'] ?? 'unknown'));
        if ($method === '') {
            $method = 'unknown';
        }
        $paymentMethodCounts[$method] = ($paymentMethodCounts[$method] ?? 0) + 1;
    }

    $generatedAt = (new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get())))->format('Y-m-d H:i:s');
    $filename = 'dashboard-report-' . date('Y-m-d') . '-' . $period . '-days.xlsx';

    $spreadsheet = new Spreadsheet();
    $spreadsheet->getProperties()
        ->setCreator('R&G Trading Admin')
        ->setTitle('Dashboard Report')
        ->setSubject('Admin Dashboard Export');

    $summarySheet = $spreadsheet->getActiveSheet();
    $summarySheet->setTitle('Dashboard Summary');
    $summarySheet->fromArray([
        ['Metric', 'Value'],
        ['Generated At', $generatedAt],
        ['Selected Period (days)', $period],
        ['Revenue for period', (float) ($rev['period'] ?? 0)],
        ['Total Revenue', (float) ($rev['total'] ?? 0)],
        ['Orders for period', (int) ($orders['period_orders'] ?? 0)],
        ['Total Orders', (int) ($orders['total_orders'] ?? 0)],
        ['Pending Orders', (int) ($orders['pending_orders'] ?? 0)],
        ['New Customers', (int) ($customers['new_customers'] ?? 0)],
        ['Total Customers', (int) ($customers['total_customers'] ?? 0)],
        ['Repeat Customers', (int) ($customers['repeat_customers'] ?? 0)],
        ['Top Product', (string) ($topProduct['name'] ?? 'N/A')],
        ['Top Product Units Sold', (int) ($topProduct['units_sold'] ?? 0)],
    ], null, 'A1');
    $summarySheet->getStyle('A1:B1')->getFont()->setBold(true);
    $summarySheet->getStyle('A1:B' . $summarySheet->getHighestRow())->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
    $summarySheet->getColumnDimension('A')->setWidth(28);
    $summarySheet->getColumnDimension('B')->setWidth(22);

    $revenueSheet = $spreadsheet->createSheet();
    $revenueSheet->setTitle('Revenue Trend');
    $revenueSheet->fromArray([['Period', 'Revenue']], null, 'A1');
    $revenueRows = [];
    foreach ($trends as $row) {
        $periodLabel = trim((string) ($row['period'] ?? $row['date'] ?? ''));
        $revenueRows[] = [$periodLabel, (float) ($row['revenue'] ?? $row['total_revenue'] ?? 0)];
    }
    if ($revenueRows) {
        $revenueSheet->fromArray($revenueRows, null, 'A2');
    }
    $revenueSheet->getStyle('A1:B1')->getFont()->setBold(true);
    if ($revenueSheet->getHighestRow() > 1) {
        $revenueSheet->getStyle('B2:B' . $revenueSheet->getHighestRow())->getNumberFormat()->setFormatCode('"₱"#,##0.00');
    }
    $revenueSheet->getColumnDimension('A')->setWidth(18);
    $revenueSheet->getColumnDimension('B')->setWidth(18);

    $seasonalSheet = $spreadsheet->createSheet();
    $seasonalSheet->setTitle('Monthly Sales Pattern');
    $seasonalSheet->fromArray([['Month', 'Revenue']], null, 'A1');
    $monthsMap = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    $seasonalRows = [];
    foreach ($seasonal as $row) {
        $monthIndex = max(1, min(12, intval($row['month'] ?? 1)));
        $label = $monthsMap[$monthIndex - 1] ?? (string) ($row['month'] ?? '');
        $seasonalRows[] = [$label, (float) ($row['revenue'] ?? $row['total_revenue'] ?? 0)];
    }
    if ($seasonalRows) {
        $seasonalSheet->fromArray($seasonalRows, null, 'A2');
    }
    $seasonalSheet->getStyle('A1:B1')->getFont()->setBold(true);
    if ($seasonalSheet->getHighestRow() > 1) {
        $seasonalSheet->getStyle('B2:B' . $seasonalSheet->getHighestRow())->getNumberFormat()->setFormatCode('"₱"#,##0.00');
    }
    $seasonalSheet->getColumnDimension('A')->setWidth(14);
    $seasonalSheet->getColumnDimension('B')->setWidth(18);

    $orderStatusSheet = $spreadsheet->createSheet();
    $orderStatusSheet->setTitle('Order Status Distribution');
    $pendingOrders = (int) ($orders['pending_orders'] ?? 0);
    $deliveredOrders = (int) ($orders['delivered_orders'] ?? 0);
    $totalOrders = (int) ($orders['total_orders'] ?? ($pendingOrders + $deliveredOrders));
    $otherOrders = max(0, $totalOrders - $pendingOrders - $deliveredOrders);
    $orderStatusSheet->fromArray([
        ['Status', 'Order Count'],
        ['Pending', $pendingOrders],
        ['Delivered', $deliveredOrders],
        ['Other', $otherOrders],
    ], null, 'A1');
    $orderStatusSheet->getStyle('A1:B1')->getFont()->setBold(true);
    if ($orderStatusSheet->getHighestRow() > 1) {
        $orderStatusSheet->getStyle('B2:B' . $orderStatusSheet->getHighestRow())->getNumberFormat()->setFormatCode('#,##0');
    }
    $orderStatusSheet->getColumnDimension('A')->setWidth(18);
    $orderStatusSheet->getColumnDimension('B')->setWidth(16);

    $paymentSheet = $spreadsheet->createSheet();
    $paymentSheet->setTitle('Payment Methods');
    $paymentSheet->fromArray([['Payment Method', 'Order Count']], null, 'A1');
    foreach ($paymentMethodCounts as $method => $count) {
        $paymentSheet->fromArray([[$method, (int) $count]], null, 'A' . ($paymentSheet->getHighestRow() + 1));
    }
    $paymentSheet->getStyle('A1:B1')->getFont()->setBold(true);
    if ($paymentSheet->getHighestRow() > 1) {
        $paymentSheet->getStyle('B2:B' . $paymentSheet->getHighestRow())->getNumberFormat()->setFormatCode('#,##0');
    }
    $paymentSheet->getColumnDimension('A')->setWidth(22);
    $paymentSheet->getColumnDimension('B')->setWidth(16);

    $peakSheet = $spreadsheet->createSheet();
    $peakSheet->setTitle('Peak Sales Periods');
    $peakSheet->fromArray([['Hour / Period', 'Order Count']], null, 'A1');
    $peakRows = [];
    foreach ($peakHours as $row) {
        $peakRows[] = [(string) ($row['hour'] ?? $row['label'] ?? ''), (int) ($row['orders'] ?? $row['count'] ?? 0)];
    }
    if ($peakRows) {
        $peakSheet->fromArray($peakRows, null, 'A2');
    }
    $peakSheet->getStyle('A1:B1')->getFont()->setBold(true);
    if ($peakSheet->getHighestRow() > 1) {
        $peakSheet->getStyle('B2:B' . $peakSheet->getHighestRow())->getNumberFormat()->setFormatCode('#,##0');
    }
    $peakSheet->getColumnDimension('A')->setWidth(18);
    $peakSheet->getColumnDimension('B')->setWidth(16);

    $topProductsSheet = $spreadsheet->createSheet();
    $topProductsSheet->setTitle('Top Product Revenue');
    $topProductsSheet->fromArray([['Product', 'Brand', 'Units Sold', 'Revenue Generated']], null, 'A1');
    $topProductRows = [];
    foreach ($topProducts as $product) {
        $topProductRows[] = [
            (string) ($product['name'] ?? $product['model'] ?? $product['model_number'] ?? ''),
            (string) ($product['brand'] ?? ''),
            (int) ($product['units_sold'] ?? 0),
            (float) ($product['revenue_generated'] ?? $product['total_revenue'] ?? $product['revenue'] ?? 0),
        ];
    }
    if ($topProductRows) {
        $topProductsSheet->fromArray($topProductRows, null, 'A2');
    }
    $topProductsSheet->getStyle('A1:D1')->getFont()->setBold(true);
    if ($topProductsSheet->getHighestRow() > 1) {
        $topProductsSheet->getStyle('C2:C' . $topProductsSheet->getHighestRow())->getNumberFormat()->setFormatCode('#,##0');
        $topProductsSheet->getStyle('D2:D' . $topProductsSheet->getHighestRow())->getNumberFormat()->setFormatCode('"₱"#,##0.00');
    }
    foreach (['A', 'B', 'C', 'D'] as $col) {
        $topProductsSheet->getColumnDimension($col)->setAutoSize(true);
    }

    $repeatCustomersSheet = $spreadsheet->createSheet();
    $repeatCustomersSheet->setTitle('Repeat Customers');
    $repeatCustomersSheet->fromArray([['Customer Name', 'Email', 'Order Count', 'Total Spent']], null, 'A1');
    $repeatCustomerRows = [];
    foreach ($repeatCustomers as $customer) {
        $customerName = trim((string) (($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')));
        $repeatCustomerRows[] = [
            $customerName,
            (string) ($customer['email'] ?? ''),
            (int) ($customer['order_count'] ?? 0),
            (float) ($customer['total_spent'] ?? 0),
        ];
    }
    if ($repeatCustomerRows) {
        $repeatCustomersSheet->fromArray($repeatCustomerRows, null, 'A2');
    }
    $repeatCustomersSheet->getStyle('A1:D1')->getFont()->setBold(true);
    if ($repeatCustomersSheet->getHighestRow() > 1) {
        $repeatCustomersSheet->getStyle('C2:C' . $repeatCustomersSheet->getHighestRow())->getNumberFormat()->setFormatCode('#,##0');
        $repeatCustomersSheet->getStyle('D2:D' . $repeatCustomersSheet->getHighestRow())->getNumberFormat()->setFormatCode('"₱"#,##0.00');
    }

    $sheetNameMap = [
        'summarySheet' => 'Dashboard Summary',
        'revenueSheet' => 'Revenue Trend',
        'seasonalSheet' => 'Monthly Sales Pattern',
        'orderStatusSheet' => 'Order Status Distribution',
        'paymentSheet' => 'Payment Methods',
        'peakSheet' => 'Peak Sales Periods',
        'topProductsSheet' => 'Top Product Revenue',
        'repeatCustomersSheet' => 'Repeat Customers',
    ];

    foreach ($sheetNameMap as $sheetKey => $title) {
        $sheet = $spreadsheet->getSheetByName($title);
        if ($sheet) {
            $sheet->freezePane('A2');
            $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('D9EAF7');
            $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->getFont()->setBold(true);
            foreach (range('A', $sheet->getHighestColumn()) as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }
        }
    }

    $writer = new Xlsx($spreadsheet);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Pragma: public');
    $writer->save('php://output');
    exit;
} catch (Throwable $e) {
    error_log('Dashboard export failed: ' . $e->getMessage());
    set_flash('error', 'The dashboard export could not be created.');
    header('Location: ' . BASE_URL . '/pages/admin/dashboard.php');
    exit;
}
