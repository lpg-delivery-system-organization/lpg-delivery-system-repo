<?php
/**
 * Admin Dashboard Portal
 * LPG Delivery System v2
 *
 * Operational dashboard displaying high-level business metrics:
 * Total Orders, Pending Orders, In-Transit Deliveries, Completed Deliveries, and Total Revenue.
 * Displays recent 10 orders table with quick dispatch actions and inventory status alerts.
 */

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/middleware.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../classes/Product.php';
require_once __DIR__ . '/../../classes/Order.php';

// Enforce admin role guard
require_role('admin');

$db = Database::connect();
$orderModel = new Order($db);
$userModel = new User($db);
$productModel = new Product($db);

// Retrieve aggregated operational stats
$dashboardStats = $orderModel->getDashboardStats();

$totalOrders      = (int)($dashboardStats['total_orders'] ?? 0);
$pendingOrders    = (int)($dashboardStats['pending_orders'] ?? 0);
$inTransitOrders  = (int)($dashboardStats['in_transit_orders'] ?? 0);
$deliveredOrders  = (int)($dashboardStats['delivered_orders'] ?? 0);
$cancelledOrders  = (int)($dashboardStats['cancelled_orders'] ?? 0);
$totalRevenue     = (float)($dashboardStats['total_revenue'] ?? 0.0);
$recentOrders     = $dashboardStats['recent_orders'] ?? [];

// Retrieve secondary catalog and user stats
$allProducts = $productModel->getAll();
$totalProducts = count($allProducts);
$activeProducts = 0;
$lowStockProducts = [];

foreach ($allProducts as $p) {
    if ($p['status'] === 'active') {
        $activeProducts++;
    }
    if ((int)$p['stock'] <= 5) {
        $lowStockProducts[] = $p;
    }
}

$totalCustomers = $userModel->countByRole('customer');
$totalRiders = $userModel->countByRole('rider');
$activeRidersList = array_filter($userModel->getAllByRole('rider'), function ($r) {
    return ($r['status'] ?? '') === 'active';
});

$page_title = 'Admin Dashboard';
$current_page = 'dashboard';
$page_js = 'admin.js';
$needs_maps = false; // no maps on this page — skip Leaflet for faster mobile loads
$needs_charts = true; // Sales Report trend chart (Chart.js) is rendered below

// ── Sales Report Parameters ──────────────────────────────────────────────────
// Filters live in the query string so a generated report is shareable,
// bookmarkable, and survives the CSV export / print round-trip.
$reportTypes  = ['daily', 'monthly', 'yearly'];
$reportLabels = ['daily' => 'Daily', 'monthly' => 'Monthly', 'yearly' => 'Yearly'];

$reportType = strtolower((string)($_GET['report_type'] ?? 'daily'));
if (!in_array($reportType, $reportTypes, true)) {
    $reportType = 'daily';
}

// Strict Y-m-d parser: rejects overflow dates such as 2026-02-31.
$parseReportDate = function ($value): ?string {
    $raw  = (string)$value;
    $date = DateTime::createFromFormat('!Y-m-d', $raw);
    return ($date && $date->format('Y-m-d') === $raw) ? $raw : null;
};

// Default window per report type: daily → last 30 days,
// monthly → current year, yearly → last 5 years.
$reportToday = date('Y-m-d');
$reportDefaults = [
    'daily'   => ['from' => date('Y-m-d', strtotime('-29 days')), 'to' => $reportToday],
    'monthly' => ['from' => date('Y-01-01'), 'to' => $reportToday],
    'yearly'  => ['from' => date('Y-01-01', strtotime('-4 years')), 'to' => $reportToday],
];
$reportFrom = $parseReportDate($_GET['date_from'] ?? null) ?? $reportDefaults[$reportType]['from'];
$reportTo   = $parseReportDate($_GET['date_to'] ?? null) ?? $reportDefaults[$reportType]['to'];

if ($reportFrom > $reportTo) {
    [$reportFrom, $reportTo] = [$reportTo, $reportFrom];
}

$reportCsvQuery = http_build_query([
    'report_type' => $reportType,
    'date_from'   => $reportFrom,
    'date_to'     => $reportTo,
    'export'      => 'csv',
]);

// ── CSV Export (runs before any HTML output) ─────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $csvSummary = $orderModel->getSalesReportSummary($reportFrom, $reportTo);
    $csvSeries  = $orderModel->getSalesReportSeries($reportFrom, $reportTo, $reportType);
    $csvRows    = $orderModel->getSalesReportRows($reportFrom, $reportTo);
    $csvVat     = sales_vat_split((float)$csvSummary['revenue']);

    $csvData = [
        ['Sales Report', $reportLabels[$reportType] . ' Report'],
        ['Date Range', $reportFrom . ' to ' . $reportTo],
        ['Sales Basis', 'Delivered orders only'],
        ['Pricing', 'VAT: 12% output VAT extracted from VAT-inclusive sales (BIR Form 2550Q)'],
        ['Generated', date('Y-m-d H:i:s')],
        null,
        ['Summary'],
        ['Metric', 'Value'],
        ['Total Revenue (PHP)', number_format($csvSummary['revenue'], 2, '.', '')],
        ['Sales, VAT-exclusive (PHP)', number_format($csvVat['net'], 2, '.', '')],
        ['Add: 12% Output VAT (PHP)', number_format($csvVat['vat'], 2, '.', '')],
        ['Orders Delivered', $csvSummary['orders']],
        ['Units Sold', $csvSummary['units']],
        ['Average Order Value (PHP)', number_format($csvSummary['avg_order'], 2, '.', '')],
        ['COD Revenue (PHP)', number_format($csvSummary['cod_revenue'], 2, '.', '')],
        ['GCash Revenue (PHP)', number_format($csvSummary['gcash_revenue'], 2, '.', '')],
        ['Top Product', $csvSummary['top_product'] ?? 'N/A'],
        ['Top Product Units', $csvSummary['top_product_units']],
        null,
        ['Breakdown by Period'],
        ['Period', 'Orders', 'Units', 'Gross Sales (PHP)', 'VAT 12% (PHP)', 'Net Sales (PHP)'],
    ];
    foreach ($csvSeries as $bucket) {
        $bVat = sales_vat_split((float)$bucket['revenue']);
        $csvData[] = [
            $bucket['label'],
            $bucket['orders'],
            $bucket['units'],
            number_format($bVat['gross'], 2, '.', ''),
            number_format($bVat['vat'], 2, '.', ''),
            number_format($bVat['net'], 2, '.', ''),
        ];
    }
    $csvData[] = null;
    $csvData[] = ['Transactions'];
    $csvData[] = ['Order #', 'Delivered At', 'Customer', 'Product', 'Quantity', 'Unit Price (PHP)', 'Gross Sales (PHP)', 'VAT 12% (PHP)', 'Net Sales (PHP)', 'Payment Method'];
    foreach ($csvRows as $tx) {
        $txVat = sales_vat_split((float)$tx['total_amount']);
        $csvData[] = [
            order_receipt_number((int)$tx['id']),
            format_date($tx['delivered_at'], 'Y-m-d H:i:s'),
            $tx['customer_name'],
            $tx['product_name'],
            $tx['quantity'],
            number_format((float)$tx['unit_price'], 2, '.', ''),
            number_format($txVat['gross'], 2, '.', ''),
            number_format($txVat['vat'], 2, '.', ''),
            number_format($txVat['net'], 2, '.', ''),
            strtoupper((string)$tx['payment_method']),
        ];
    }

    stream_csv(
        build_csv($csvData),
        'sales-report_' . $reportType . '_' . $reportFrom . '_' . $reportTo . '.csv'
    );
    return; // TEST_MODE records the payload instead of exiting
}

// ── Sales Report Data (for the dashboard section) ────────────────────────────
$reportSummary = $orderModel->getSalesReportSummary($reportFrom, $reportTo);
$reportSeries  = $orderModel->getSalesReportSeries($reportFrom, $reportTo, $reportType);
$reportRows    = $orderModel->getSalesReportRows($reportFrom, $reportTo);
$reportHasData = $reportSummary['orders'] > 0;
$reportGrandTotal = (float)$reportSummary['revenue'];
$reportVat = sales_vat_split($reportGrandTotal);

// Totals across the transaction rows (used by the print/CSV totals footer).
$reportTotals = ['qty' => 0, 'gross' => 0.0, 'vat' => 0.0, 'net' => 0.0];
foreach ($reportRows as $txRow) {
    $txSplit = sales_vat_split((float)$txRow['total_amount']);
    $reportTotals['qty']   += (int)$txRow['quantity'];
    $reportTotals['gross'] += $txSplit['gross'];
    $reportTotals['vat']   += $txSplit['vat'];
    $reportTotals['net']   += $txSplit['net'];
}

// Totals across the period breakdown rows.
$reportSeriesTotals = ['orders' => 0, 'units' => 0, 'gross' => 0.0, 'vat' => 0.0, 'net' => 0.0];
foreach ($reportSeries as $bucketRow) {
    $bucketSplit = sales_vat_split((float)$bucketRow['revenue']);
    $reportSeriesTotals['orders'] += (int)$bucketRow['orders'];
    $reportSeriesTotals['units']  += (int)$bucketRow['units'];
    $reportSeriesTotals['gross']  += $bucketSplit['gross'];
    $reportSeriesTotals['vat']    += $bucketSplit['vat'];
    $reportSeriesTotals['net']    += $bucketSplit['net'];
}

$chartPayload = [
    'type'    => $reportType,
    'labels'  => array_column($reportSeries, 'label'),
    'revenue' => array_column($reportSeries, 'revenue'),
    'orders'  => array_column($reportSeries, 'orders'),
    'units'   => array_column($reportSeries, 'units'),
];
// Escape "</" so a product name can never terminate the JSON script block.
$chartJson = str_replace('</', '<\/', json_encode($chartPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?: '{}';

require_once __DIR__ . '/../../templates/header.php';
require_once __DIR__ . '/../../templates/components/order-card.php';
?>

<div class="container-fluid px-0" id="adminDashboardContainer">
    <!-- Header Banner -->
    <div class="card mb-4 rounded-3 overflow-hidden position-relative app-banner-dark text-white" data-aos="fade-down">
        <div class="card-body p-4 p-lg-5 position-relative" style="z-index: 2;">
            <div class="row align-items-center g-3">
                <div class="col-lg-8">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="badge bg-primary text-uppercase px-3 py-1 fw-semibold">Administration</span>
                        <span class="badge bg-success bg-opacity-75 text-white px-2 py-1"><i class="bi bi-shield-lock-fill me-1"></i>Secure Portal</span>
                    </div>
                    <h2 class="fw-bold mb-2">System Operations Control Center</h2>
                    <p class="mb-3 text-white-50 fs-6">
                        Monitor live LPG cylinder sales, dispatch pending orders to available delivery riders, manage catalog stock, and review accounts.
                    </p>
                    <div class="d-flex flex-wrap gap-3 text-white small">
                        <span><i class="bi bi-calendar-event me-1 text-primary"></i><?= date('F d, Y') ?></span>
                        <span><i class="bi bi-person-badge me-1 text-info"></i><?= e($_SESSION['user_name'] ?? 'Admin') ?> (<?= e($_SESSION['user_email'] ?? '') ?>)</span>
                        <span><i class="bi bi-truck me-1 text-warning"></i><?= count($activeRidersList) ?> Active Riders</span>
                    </div>
                </div>
                <div class="col-lg-4 text-lg-end">
                    <div class="d-flex flex-column flex-sm-row flex-lg-column gap-2 justify-content-lg-end">
                        <a href="<?= url('pages/admin/orders.php') ?>" class="btn btn-primary fw-bold shadow-sm px-4">
                            <i class="bi bi-box-seam me-2"></i>Dispatch Orders
                        </a>
                        <a href="<?= url('pages/admin/inventory.php') ?>" class="btn btn-outline-light btn-sm fw-semibold px-3">
                            <i class="bi bi-tags me-1"></i>Manage Inventory
                        </a>
                        <a href="#salesReport" class="btn btn-outline-light btn-sm fw-semibold px-3">
                            <i class="bi bi-graph-up-arrow me-1"></i>Sales Report
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 5 Core Operational Statistics Cards -->
    <div class="row g-3 mb-4">
        <!-- Card 1: Total Revenue -->
        <div class="col-sm-6 col-xl" data-aos="fade-up" data-aos-delay="0">
            <div class="card app-stat-card border-0 shadow-sm h-100 p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">Total Sales</span>
                        <h3 class="fw-bold my-1 text-success"><?= e(format_currency($totalRevenue)) ?></h3>
                        <span class="extra-small text-muted">From <?= (int)$deliveredOrders ?> delivered orders <i class="bi bi-arrow-up-right stat-open-hint text-primary"></i></span>
                    </div>
                    <div class="stat-icon-wrapper bg-success-subtle text-success">
                        <i class="bi bi-cash-stack"></i>
                    </div>
                </div>
                <a href="<?= url('pages/admin/orders.php') ?>" class="stretched-link" aria-label="View all orders"></a>
            </div>
        </div>

        <!-- Card 2: Pending Orders -->
        <div class="col-sm-6 col-xl" data-aos="fade-up" data-aos-delay="100">
            <div class="card app-stat-card border-0 shadow-sm h-100 p-3 <?= $pendingOrders > 0 ? 'border-start border-warning border-4' : '' ?>">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">Pending Orders</span>
                        <h3 class="fw-bold my-1 text-warning" data-counter-target="<?= (int)$pendingOrders ?>"><?= (int)$pendingOrders ?></h3>
                        <span class="extra-small text-muted">Awaiting approval <i class="bi bi-arrow-up-right stat-open-hint text-primary"></i></span>
                    </div>
                    <div class="stat-icon-wrapper bg-warning-subtle text-warning">
                        <i class="bi bi-clock-history"></i>
                    </div>
                </div>
                <a href="<?= url('pages/admin/orders.php') ?>" class="stretched-link" aria-label="Review pending orders"></a>
            </div>
        </div>

        <!-- Card 3: In-Transit Deliveries -->
        <div class="col-sm-6 col-xl" data-aos="fade-up" data-aos-delay="200">
            <div class="card app-stat-card border-0 shadow-sm h-100 p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">In-Transit</span>
                        <h3 class="fw-bold my-1 text-info" data-counter-target="<?= (int)$inTransitOrders ?>"><?= (int)$inTransitOrders ?></h3>
                        <span class="extra-small text-muted">Active deliveries <i class="bi bi-arrow-up-right stat-open-hint text-primary"></i></span>
                    </div>
                    <div class="stat-icon-wrapper bg-info-subtle text-info">
                        <i class="bi bi-truck"></i>
                    </div>
                </div>
                <a href="<?= url('pages/admin/orders.php') ?>" class="stretched-link" aria-label="View in-transit deliveries"></a>
            </div>
        </div>

        <!-- Card 4: Completed Deliveries -->
        <div class="col-sm-6 col-xl" data-aos="fade-up" data-aos-delay="300">
            <div class="card app-stat-card border-0 shadow-sm h-100 p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">Delivered</span>
                        <h3 class="fw-bold my-1 text-primary" data-counter-target="<?= (int)$deliveredOrders ?>"><?= (int)$deliveredOrders ?></h3>
                        <span class="extra-small text-muted">Completed orders <i class="bi bi-arrow-up-right stat-open-hint text-primary"></i></span>
                    </div>
                    <div class="stat-icon-wrapper bg-primary-subtle text-primary">
                        <i class="bi bi-check2-circle"></i>
                    </div>
                </div>
                <a href="<?= url('pages/admin/orders.php') ?>" class="stretched-link" aria-label="View completed orders"></a>
            </div>
        </div>

        <!-- Card 5: Total Orders -->
        <div class="col-sm-6 col-xl" data-aos="fade-up" data-aos-delay="400">
            <div class="card app-stat-card border-0 shadow-sm h-100 p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">Total Orders</span>
                        <h3 class="fw-bold my-1 text-dark" data-counter-target="<?= (int)$totalOrders ?>"><?= (int)$totalOrders ?></h3>
                        <span class="extra-small text-muted"><?= (int)$cancelledOrders ?> cancelled <i class="bi bi-arrow-up-right stat-open-hint text-primary"></i></span>
                    </div>
                    <div class="stat-icon-wrapper bg-secondary-subtle text-secondary">
                        <i class="bi bi-receipt"></i>
                    </div>
                </div>
                <a href="<?= url('pages/admin/orders.php') ?>" class="stretched-link" aria-label="View all orders"></a>
            </div>
        </div>
    </div>

    <!-- Secondary Metric Badges & System Shortcuts -->
    <div class="row g-3 mb-4">
        <div class="col-md-4" data-aos="fade-up" data-aos-delay="0">
            <div class="card border-0 shadow-sm p-3 rounded-3 bg-light">
                <div class="d-flex align-items-center gap-3">
                    <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width: 46px; height: 46px;">
                        <i class="bi bi-people fs-4"></i>
                    </div>
                    <div>
                        <div class="fw-bold text-dark fs-5" data-counter-target="<?= (int)$totalCustomers ?>"><?= (int)$totalCustomers ?> Customers</div>
                        <div class="small text-muted">Registered user accounts</div>
                    </div>
                    <span class="btn btn-sm btn-outline-primary ms-auto">View</span>
                    <a href="<?= url('pages/admin/users.php?role=customer') ?>" class="stretched-link" aria-label="View customer accounts"></a>
                </div>
            </div>
        </div>
        <div class="col-md-4" data-aos="fade-up" data-aos-delay="100">
            <div class="card border-0 shadow-sm p-3 rounded-3 bg-light">
                <div class="d-flex align-items-center gap-3">
                    <div class="bg-warning bg-opacity-10 text-warning rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width: 46px; height: 46px;">
                        <i class="bi bi-truck fs-4"></i>
                    </div>
                    <div>
                        <div class="fw-bold text-dark fs-5" data-counter-target="<?= count($activeRidersList) ?>"><?= count($activeRidersList) ?> Active Riders</div>
                        <div class="small text-muted"><?= (int)$totalRiders ?> total enrolled</div>
                    </div>
                    <span class="btn btn-sm btn-outline-warning text-dark ms-auto">View</span>
                    <a href="<?= url('pages/admin/users.php?role=rider') ?>" class="stretched-link" aria-label="View rider accounts"></a>
                </div>
            </div>
        </div>
        <div class="col-md-4" data-aos="fade-up" data-aos-delay="200">
            <div class="card border-0 shadow-sm p-3 rounded-3 bg-light">
                <div class="d-flex align-items-center gap-3">
                    <div class="bg-info bg-opacity-10 text-info rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width: 46px; height: 46px;">
                        <i class="bi bi-tags fs-4"></i>
                    </div>
                    <div>
                        <div class="fw-bold text-dark fs-5" data-counter-target="<?= (int)$activeProducts ?>"><?= (int)$activeProducts ?> Active Products</div>
                        <div class="small text-muted"><?= (int)$totalProducts ?> items in catalog</div>
                    </div>
                    <span class="btn btn-sm btn-outline-info ms-auto">Catalog</span>
                    <a href="<?= url('pages/admin/inventory.php') ?>" class="stretched-link" aria-label="Open product catalog"></a>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════ Sales Report Generator ═══════════ -->
    <div class="row mb-4" id="salesReport">
        <div class="col-12">

            <!-- Print-only document header -->
            <div class="sales-print-only sales-print-doc">
                <div class="sales-print-title"><?= e(defined('APP_NAME') ? APP_NAME : 'LPG Delivery System') ?></div>
                <div class="sales-print-subtitle">SALES REPORT &mdash; <?= e(strtoupper($reportLabels[$reportType])) ?></div>
                <div class="sales-print-meta">
                    Period: <?= e(format_date($reportFrom, 'M d, Y')) ?> to <?= e(format_date($reportTo, 'M d, Y')) ?>
                    &middot; Basis: Delivered orders only
                    &middot; VAT: 12% output VAT extracted from VAT-inclusive sales (BIR Form 2550Q)<br>
                    Prepared by: <?= e($_SESSION['user_name'] ?? 'Admin') ?>
                    &middot; Generated: <?= e(date('M d, Y h:i A')) ?>
                </div>
            </div>

            <!-- Print-only summary table -->
            <div class="sales-print-only">
                <table class="table sales-print-summary mb-0">
                    <thead>
                        <tr><th colspan="2">Summary of Sales</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="fw-semibold">Sales (VAT-exclusive)</td>
                            <td class="text-end fw-bold"><?= e(format_currency($reportVat['net'])) ?></td>
                        </tr>
                        <tr>
                            <td class="fw-semibold">Add: 12% Output VAT</td>
                            <td class="text-end"><?= e(format_currency($reportVat['vat'])) ?></td>
                        </tr>
                        <tr>
                            <td class="fw-semibold">Gross Sales (VAT-inclusive)</td>
                            <td class="text-end fw-bold"><?= e(format_currency($reportVat['gross'])) ?></td>
                        </tr>
                        <tr>
                            <td class="fw-semibold">Orders Delivered / Units Sold</td>
                            <td class="text-end"><?= (int)$reportSummary['orders'] ?> / <?= (int)$reportSummary['units'] ?></td>
                        </tr>
                        <tr>
                            <td class="fw-semibold">Average Order Value</td>
                            <td class="text-end"><?= e(format_currency($reportSummary['avg_order'])) ?></td>
                        </tr>
                        <tr>
                            <td class="fw-semibold">COD Revenue / GCash Revenue</td>
                            <td class="text-end"><?= e(format_currency($reportSummary['cod_revenue'])) ?> / <?= e(format_currency($reportSummary['gcash_revenue'])) ?></td>
                        </tr>
                        <tr>
                            <td class="fw-semibold">Top Product</td>
                            <td class="text-end">
                                <?= $reportSummary['top_product'] !== null
                                    ? e($reportSummary['top_product']) . ' (' . (int)$reportSummary['top_product_units'] . ' units)'
                                    : 'N/A' ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-header bg-white py-3 border-bottom d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div>
                        <h5 class="card-title mb-0 fw-bold d-flex align-items-center gap-2">
                            <i class="bi bi-graph-up-arrow text-success"></i>Sales Report Generator
                        </h5>
                        <small class="text-muted">
                            <?= e($reportLabels[$reportType]) ?> report &middot;
                            <?= e(format_date($reportFrom, 'M d, Y')) ?> to <?= e(format_date($reportTo, 'M d, Y')) ?> &middot;
                            delivered orders only
                        </small>
                    </div>
                    <div class="d-flex gap-2 flex-wrap no-print">
                        <a href="<?= e(url('pages/admin/dashboard.php?' . $reportCsvQuery)) ?>" class="btn btn-outline-success btn-sm fw-semibold" id="salesCsvLink">
                            <i class="bi bi-file-earmark-spreadsheet me-1"></i>Export CSV
                        </a>
                        <button type="button" class="btn btn-outline-primary btn-sm fw-semibold" id="salesPrintBtn">
                            <i class="bi bi-printer me-1"></i>Print / Save PDF
                        </button>
                    </div>
                </div>

                <div class="card-body p-3 p-md-4">
                    <!-- Filter Bar -->
                    <form method="get" action="<?= url('pages/admin/dashboard.php') ?>" id="salesReportForm" class="row g-2 align-items-end mb-4 no-print">
                        <input type="hidden" name="report_type" id="salesReportTypeInput" value="<?= e($reportType) ?>">
                        <div class="col-12 col-md-4 col-xl-3">
                            <label class="form-label fw-semibold small text-muted mb-1">Report Type</label>
                            <div class="btn-group w-100" role="group" aria-label="Report type">
                                <?php foreach ($reportTypes as $rt): ?>
                                    <button type="button"
                                            class="btn btn-sm sales-report-type-btn <?= $rt === $reportType ? 'btn-primary active' : 'btn-outline-secondary' ?>"
                                            data-type="<?= e($rt) ?>"><?= e($reportLabels[$rt]) ?></button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-6 col-md-3 col-xl-2">
                            <label for="salesDateFrom" class="form-label fw-semibold small text-muted mb-1">From</label>
                            <input type="date" class="form-control" name="date_from" id="salesDateFrom" value="<?= e($reportFrom) ?>" max="<?= e($reportTo) ?>">
                        </div>
                        <div class="col-6 col-md-3 col-xl-2">
                            <label for="salesDateTo" class="form-label fw-semibold small text-muted mb-1">To</label>
                            <input type="date" class="form-control" name="date_to" id="salesDateTo" value="<?= e($reportTo) ?>" min="<?= e($reportFrom) ?>" max="<?= e($reportToday) ?>">
                        </div>
                        <div class="col-12 col-md-2 col-xl-2">
                            <button type="submit" class="btn btn-primary fw-semibold w-100">
                                <i class="bi bi-search me-1"></i>Generate
                            </button>
                        </div>
                        <div class="col-12 col-xl-3">
                            <div class="d-flex flex-wrap gap-1 justify-content-xl-end">
                                <button type="button" class="btn btn-outline-secondary btn-sm sales-report-preset" data-preset="today">Today</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm sales-report-preset" data-preset="last7">Last 7 Days</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm sales-report-preset" data-preset="thisMonth">This Month</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm sales-report-preset" data-preset="thisYear">This Year</button>
                            </div>
                        </div>
                    </form>

                    <!-- Summary Cards (replaced by the print-only summary table when printing) -->
                    <div class="row g-3 mb-4 no-print">
                        <div class="col-sm-6 col-xl-3">
                            <div class="card app-stat-card border-0 shadow-sm h-100 p-3">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div>
                                        <span class="text-muted small text-uppercase fw-semibold">Total Revenue</span>
                                        <h3 class="fw-bold my-1 text-success"><?= e(format_currency($reportSummary['revenue'])) ?></h3>
                                        <span class="extra-small text-muted">
                                            COD <?= e(format_currency($reportSummary['cod_revenue'])) ?> &middot;
                                            GCash <?= e(format_currency($reportSummary['gcash_revenue'])) ?>
                                        </span>
                                    </div>
                                    <div class="stat-icon-wrapper bg-success-subtle text-success"><i class="bi bi-cash-stack"></i></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-xl-3">
                            <div class="card app-stat-card border-0 shadow-sm h-100 p-3">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div>
                                        <span class="text-muted small text-uppercase fw-semibold">Orders Delivered</span>
                                        <h3 class="fw-bold my-1 text-primary"><?= (int)$reportSummary['orders'] ?></h3>
                                        <span class="extra-small text-muted">Completed in selected range</span>
                                    </div>
                                    <div class="stat-icon-wrapper bg-primary-subtle text-primary"><i class="bi bi-check2-circle"></i></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-xl-3">
                            <div class="card app-stat-card border-0 shadow-sm h-100 p-3">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div>
                                        <span class="text-muted small text-uppercase fw-semibold">Units Sold</span>
                                        <h3 class="fw-bold my-1 text-info"><?= (int)$reportSummary['units'] ?></h3>
                                        <span class="extra-small text-muted">
                                            <?= $reportSummary['top_product'] !== null
                                                ? 'Top: ' . e($reportSummary['top_product']) . ' (' . (int)$reportSummary['top_product_units'] . ')'
                                                : 'No deliveries in range' ?>
                                        </span>
                                    </div>
                                    <div class="stat-icon-wrapper bg-info-subtle text-info"><i class="bi bi-box-seam"></i></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-xl-3">
                            <div class="card app-stat-card border-0 shadow-sm h-100 p-3">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div>
                                        <span class="text-muted small text-uppercase fw-semibold">Avg Order Value</span>
                                        <h3 class="fw-bold my-1 text-dark"><?= e(format_currency($reportSummary['avg_order'])) ?></h3>
                                        <span class="extra-small text-muted">Revenue per delivered order</span>
                                    </div>
                                    <div class="stat-icon-wrapper bg-secondary-subtle text-secondary"><i class="bi bi-receipt"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Chart & Period Breakdown -->
                    <div class="row g-4 mb-4">
                        <div class="col-lg-7 no-print">
                            <div class="card border-0 shadow-sm rounded-3 h-100">
                                <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
                                    <h6 class="card-title mb-0 fw-bold d-flex align-items-center gap-2">
                                        <i class="bi bi-graph-up text-success"></i>Sales Trend
                                    </h6>
                                    <button type="button" class="btn btn-outline-secondary btn-sm sales-report-no-print" id="salesChartToggle" title="Switch between line and bar chart">
                                        <i class="bi bi-bar-chart-line me-1"></i><span id="salesChartToggleLabel">Bar view</span>
                                    </button>
                                </div>
                                <div class="card-body">
                                    <div style="position: relative; height: 300px;">
                                        <canvas id="salesTrendChart" aria-label="Sales trend chart" role="img"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-5 no-print">
                            <div class="card border-0 shadow-sm rounded-3 h-100">
                                <div class="card-header bg-white py-3 border-bottom">
                                    <h6 class="card-title mb-0 fw-bold d-flex align-items-center gap-2">
                                        <i class="bi bi-table text-primary"></i>Breakdown by Period
                                    </h6>
                                </div>
                                <div class="card-body p-0">
                                    <div class="sales-report-table-scroll">
                                        <table class="table table-hover align-middle mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th class="ps-4">Period</th>
                                                    <th class="text-end">Orders</th>
                                                    <th class="text-end">Units</th>
                                                    <th class="text-end">Gross Sales</th>
                                                    <th class="text-end">VAT (12%)</th>
                                                    <th class="text-end pe-4">Net Sales</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($reportSeries as $bucket): ?>
                                                    <?php
                                                    $share = $reportGrandTotal > 0 ? ($bucket['revenue'] / $reportGrandTotal * 100) : 0;
                                                    $bucketSplit = sales_vat_split((float)$bucket['revenue']);
                                                    ?>
                                                    <tr>
                                                        <td class="ps-4 fw-semibold text-dark">
                                                            <?= e($bucket['label']) ?>
                                                            <div class="progress mt-1" style="height: 4px;">
                                                                <div class="progress-bar bg-success" style="width: <?= e(number_format($share, 1)) ?>%"></div>
                                                            </div>
                                                        </td>
                                                        <td class="text-end"><?= (int)$bucket['orders'] ?></td>
                                                        <td class="text-end"><?= (int)$bucket['units'] ?></td>
                                                        <td class="text-end fw-bold text-dark"><?= e(format_currency($bucketSplit['gross'])) ?></td>
                                                        <td class="text-end"><?= e(format_currency($bucketSplit['vat'])) ?></td>
                                                        <td class="text-end pe-4 fw-bold text-dark"><?= e(format_currency($bucketSplit['net'])) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                            <tfoot class="sales-report-totals">
                                                <tr>
                                                    <td class="ps-4 fw-bold">TOTAL</td>
                                                    <td class="text-end fw-bold"><?= (int)$reportSeriesTotals['orders'] ?></td>
                                                    <td class="text-end fw-bold"><?= (int)$reportSeriesTotals['units'] ?></td>
                                                    <td class="text-end fw-bold"><?= e(format_currency($reportSeriesTotals['gross'])) ?></td>
                                                    <td class="text-end fw-bold"><?= e(format_currency($reportSeriesTotals['vat'])) ?></td>
                                                    <td class="text-end pe-4 fw-bold"><?= e(format_currency($reportSeriesTotals['net'])) ?></td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Transaction Detail -->
                    <div class="card border-0 shadow-sm rounded-3">
                        <div class="card-header bg-white py-3 border-bottom d-flex flex-wrap align-items-center justify-content-between gap-2">
                            <h6 class="card-title mb-0 fw-bold d-flex align-items-center gap-2">
                                <i class="bi bi-list-check text-info"></i>Transaction Detail
                            </h6>
                            <span class="badge bg-secondary rounded-pill"><?= count($reportRows) ?> record(s)</span>
                        </div>
                        <div class="card-body p-0">
                            <?php if (!$reportHasData): ?>
                                <div class="text-center py-5 px-3">
                                    <div class="mb-3 text-muted"><i class="bi bi-bar-chart-line fs-1 opacity-50"></i></div>
                                    <h6 class="fw-bold">No delivered orders in this range</h6>
                                    <p class="text-muted small mb-0">Adjust the date filter or pick another report type, then click Generate.</p>
                                </div>
                            <?php else: ?>
                                <div class="sales-report-table-scroll sales-report-tx-scroll">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th class="ps-4">Order #</th>
                                                <th>Delivered</th>
                                                <th>Customer</th>
                                                <th>Product</th>
                                                <th class="text-end">Qty</th>
                                                <th class="text-end">Unit Price</th>
                                                <th class="text-end">Gross</th>
                                                <th class="text-end">VAT 12%</th>
                                                <th class="text-end">Net</th>
                                                <th class="text-end pe-4">Payment</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($reportRows as $tx): ?>
                                                <?php $txSplit = sales_vat_split((float)$tx['total_amount']); ?>
                                                <tr class="app-clickable-row" data-href="<?= url('pages/admin/order-detail.php?id=' . (int)$tx['id']) ?>">
                                                    <td class="ps-4 fw-bold text-primary"><?= e(order_receipt_number((int)$tx['id'])) ?></td>
                                                    <td class="text-muted small"><?= e(format_date($tx['delivered_at'], 'M d, Y')) ?></td>
                                                    <td class="fw-semibold text-dark"><?= e($tx['customer_name']) ?></td>
                                                    <td class="text-dark text-truncate" style="max-width: 200px;" title="<?= e($tx['product_name']) ?>"><?= e($tx['product_name']) ?></td>
                                                    <td class="text-end"><?= (int)$tx['quantity'] ?></td>
                                                    <td class="text-end"><?= e(format_currency($tx['unit_price'])) ?></td>
                                                    <td class="text-end fw-bold text-dark"><?= e(format_currency($txSplit['gross'])) ?></td>
                                                    <td class="text-end"><?= e(format_currency($txSplit['vat'])) ?></td>
                                                    <td class="text-end fw-bold text-dark"><?= e(format_currency($txSplit['net'])) ?></td>
                                                    <td class="text-end pe-4">
                                                        <span class="badge <?= strtoupper((string)$tx['payment_method']) === 'GCASH' ? 'bg-primary-subtle text-primary' : 'bg-success-subtle text-success' ?>">
                                                            <?= e(strtoupper((string)$tx['payment_method'])) ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot class="sales-report-totals">
                                            <tr>
                                                <td class="ps-4 fw-bold">TOTAL</td>
                                                <td></td>
                                                <td></td>
                                                <td></td>
                                                <td class="text-end fw-bold"><?= (int)$reportTotals['qty'] ?></td>
                                                <td></td>
                                                <td class="text-end fw-bold"><?= e(format_currency($reportTotals['gross'])) ?></td>
                                                <td class="text-end fw-bold"><?= e(format_currency($reportTotals['vat'])) ?></td>
                                                <td class="text-end fw-bold"><?= e(format_currency($reportTotals['net'])) ?></td>
                                                <td class="pe-4"></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Data consumed by initSalesReport() in assets/js/admin.js -->
                    <script type="application/json" id="salesChartData"><?= $chartJson ?></script>
                </div>
            </div>

            <!-- Print-only signature lines -->
            <div class="sales-print-only sales-print-sign">
                <div class="sales-print-sign-col">Prepared by:<span><?= e($_SESSION['user_name'] ?? 'Admin') ?></span></div>
                <div class="sales-print-sign-col">Checked by:<span>&nbsp;</span></div>
            </div>
        </div>
    </div>

    <!-- Main Content Area: Recent Orders & Sidebar Info -->
    <div class="row g-4">
        <!-- Recent Orders Table (Top 10) -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-3 h-100">
                <div class="card-header bg-white py-3 border-bottom d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div>
                        <h5 class="card-title mb-0 fw-bold d-flex align-items-center gap-2">
                            <i class="bi bi-clock-history text-primary"></i>Recent Orders
                        </h5>
                        <small class="text-muted">Displaying latest 10 transactions across the platform</small>
                    </div>
                    <a href="<?= url('pages/admin/orders.php') ?>" class="btn btn-outline-primary btn-sm fw-semibold">
                        View All Orders (<?= $totalOrders ?>) <i class="bi bi-arrow-right ms-1"></i>
                    </a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($recentOrders)): ?>
                        <div class="text-center py-5 px-3">
                            <div class="mb-3 text-muted">
                                <i class="bi bi-box-seam fs-1 opacity-50"></i>
                            </div>
                            <h6 class="fw-bold">No orders placed yet</h6>
                            <p class="text-muted small mb-0">Customer orders will appear here in real-time.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-4">Order</th>
                                        <th>Customer</th>
                                        <th>Product</th>
                                        <th>Amount</th>
                                        <th>Payment</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                        <th class="text-end pe-4">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentOrders as $order): ?>
                                        <?php
                                        $orderId = (int)$order['id'];
                                        $status = (string)$order['status'];
                                        $paymentMethod = strtoupper((string)($order['payment_method'] ?? 'COD'));
                                        ?>
                                        <tr class="app-clickable-row" data-href="<?= url('pages/admin/order-detail.php?id=' . $orderId) ?>">
                                            <td class="ps-4 fw-bold text-primary">#<?= $orderId ?></td>
                                            <td>
                                                <div class="fw-semibold text-dark"><?= e($order['customer_name'] ?? 'Customer') ?></div>
                                                <?php if (!empty($order['rider_name'])): ?>
                                                    <small class="text-muted extra-small"><i class="bi bi-truck me-1"></i><?= e($order['rider_name']) ?></small>
                                                <?php else: ?>
                                                    <small class="text-muted extra-small fst-italic">Unassigned</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="fw-medium text-dark text-truncate" style="max-width: 210px;" title="<?= e($order['product_name'] ?? 'LPG Cylinder') ?>"><?= e($order['product_name'] ?? 'LPG Cylinder') ?></div>
                                                <small class="text-muted extra-small"><?= (int)$order['quantity'] ?> unit(s)</small>
                                            </td>
                                            <td class="fw-bold text-dark"><?= e(format_currency($order['total_amount'])) ?></td>
                                            <td>
                                                <span class="badge <?= $paymentMethod === 'GCASH' ? 'bg-primary-subtle text-primary' : 'bg-success-subtle text-success' ?>">
                                                    <?= e($paymentMethod) ?>
                                                </span>
                                            </td>
                                            <td><?= get_order_status_badge($status) ?></td>
                                            <td class="text-muted small"><?= e(format_date($order['created_at'], 'M d, Y')) ?></td>
                                            <td class="text-end pe-4">
                                                <a href="<?= url('pages/admin/order-detail.php?id=' . $orderId) ?>" class="btn btn-light btn-sm p-1 px-2" title="Manage Order">
                                                    <i class="bi bi-arrow-right"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Sidebar / Alerts & Quick Navigation -->
        <div class="col-lg-4">
            <!-- Low Stock Alert Card -->
            <div class="card border-0 shadow-sm rounded-3 mb-4">
                <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
                    <h6 class="card-title mb-0 fw-bold d-flex align-items-center gap-2">
                        <i class="bi bi-exclamation-triangle-fill text-warning"></i>Inventory Stock Alerts
                    </h6>
                    <span class="badge <?= count($lowStockProducts) > 0 ? 'bg-danger' : 'bg-success' ?> rounded-pill">
                        <?= count($lowStockProducts) ?>
                    </span>
                </div>
                <div class="card-body p-3">
                    <?php if (empty($lowStockProducts)): ?>
                        <div class="text-center py-3 text-muted">
                            <i class="bi bi-check-circle fs-3 text-success d-block mb-1"></i>
                            <span class="small">All product inventory levels are healthy (&gt; 5 units).</span>
                        </div>
                    <?php else: ?>
                        <ul class="list-group list-group-flush small">
                            <?php foreach ($lowStockProducts as $lp): ?>
                                <li class="list-group-item px-0 py-2 d-flex align-items-center justify-content-between">
                                    <div>
                                        <div class="fw-semibold text-dark"><?= e($lp['name']) ?></div>
                                        <span class="badge bg-secondary-subtle text-secondary extra-small"><?= e($lp['brand']) ?> (<?= e($lp['weight']) ?>)</span>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge <?= (int)$lp['stock'] === 0 ? 'bg-danger' : 'bg-warning text-dark' ?>">
                                            <?= (int)$lp['stock'] === 0 ? 'Out of Stock' : (int)$lp['stock'] . ' left' ?>
                                        </span>
                                        <div class="extra-small mt-1">
                                            <a href="<?= url('pages/admin/inventory.php') ?>" class="text-decoration-none text-primary">Restock</a>
                                        </div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Management Shortcuts -->
            <div class="card border-0 shadow-sm rounded-3 mb-4">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="card-title mb-0 fw-bold d-flex align-items-center gap-2">
                        <i class="bi bi-grid-fill text-primary"></i>Management Hub
                    </h6>
                </div>
                <div class="card-body p-3">
                    <div class="d-grid gap-2">
                        <a href="<?= url('pages/admin/orders.php') ?>" class="btn btn-outline-primary d-flex align-items-center justify-content-between p-3 text-start">
                            <div>
                                <div class="fw-bold"><i class="bi bi-box-seam me-2"></i>Order Dispatch
                                    <?php if ($pendingOrders > 0): ?><span class="badge bg-warning text-dark ms-1"><?= (int)$pendingOrders ?> pending</span><?php endif; ?>
                                </div>
                                <small class="text-muted">Approve, assign riders, and update delivery status</small>
                            </div>
                            <i class="bi bi-chevron-right"></i>
                        </a>
                        <a href="<?= url('pages/admin/inventory.php') ?>" class="btn btn-outline-secondary d-flex align-items-center justify-content-between p-3 text-start text-dark">
                            <div>
                                <div class="fw-bold"><i class="bi bi-tags me-2 text-info"></i>Product Inventory
                                    <?php if (count($lowStockProducts) > 0): ?><span class="badge bg-danger ms-1"><?= count($lowStockProducts) ?> low</span><?php endif; ?>
                                </div>
                                <small class="text-muted">Update stock, adjust prices, and add new products</small>
                            </div>
                            <i class="bi bi-chevron-right"></i>
                        </a>
                        <a href="<?= url('pages/admin/users.php') ?>" class="btn btn-outline-secondary d-flex align-items-center justify-content-between p-3 text-start text-dark">
                            <div>
                                <div class="fw-bold"><i class="bi bi-people me-2 text-warning"></i>User Accounts
                                    <span class="badge bg-secondary ms-1"><?= (int)$totalCustomers + (int)$totalRiders ?> users</span>
                                </div>
                                <small class="text-muted">Inspect customer valid IDs and toggle user statuses</small>
                            </div>
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../../templates/footer.php';
?>
