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
// bookmarkable, and survives the Excel export / print round-trip.
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

// Strict Y-m parser for the monthly picker.
$parseReportMonth = function ($value): ?string {
    $raw  = (string)$value;
    $date = DateTime::createFromFormat('!Y-m', $raw);
    return ($date && $date->format('Y-m') === $raw) ? $raw : null;
};

$reportToday = date('Y-m-d');

// Normalise the type-specific picker. Future periods are clamped so a stray
// query string can never ask for a day/month/year that has not happened yet.
$reportDate = $parseReportDate($_GET['report_date'] ?? null) ?? $reportToday;
if ($reportDate > $reportToday) {
    $reportDate = $reportToday;
}

$reportMonth = $parseReportMonth($_GET['report_month'] ?? null) ?? date('Y-m');
if ($reportMonth > date('Y-m')) {
    $reportMonth = date('Y-m');
}

$reportYear = (int)($_GET['report_year'] ?? date('Y'));
if ($reportYear < 2000 || $reportYear > (int)date('Y')) {
    $reportYear = (int)date('Y');
}

// The picker supplies an exact range: a single day, one whole month, or one
// whole calendar year (January 1 – December 31).
$reportPickerFrom = null;
$reportPickerTo   = null;
if ($reportType === 'daily' && isset($_GET['report_date'])) {
    $reportPickerFrom = $reportDate;
    $reportPickerTo   = $reportDate;
} elseif ($reportType === 'monthly' && isset($_GET['report_month'])) {
    $reportPickerFrom = $reportMonth . '-01';
    $reportPickerTo   = date('Y-m-t', strtotime($reportMonth . '-01'));
} elseif ($reportType === 'yearly' && isset($_GET['report_year'])) {
    $reportPickerFrom = $reportYear . '-01-01';
    $reportPickerTo   = $reportYear . '-12-31';
}

// Default window mirrors the picker so an unparameterised visit lands on the
// current day / month / year.
$reportDefaults = [
    'daily'   => ['from' => $reportDate, 'to' => $reportDate],
    'monthly' => ['from' => $reportMonth . '-01', 'to' => date('Y-m-t', strtotime($reportMonth . '-01'))],
    'yearly'  => ['from' => $reportYear . '-01-01', 'to' => $reportYear . '-12-31'],
];

if ($reportPickerFrom !== null && $reportPickerTo !== null) {
    $reportFrom = $reportPickerFrom;
    $reportTo   = $reportPickerTo;
} else {
    // Explicit range (deep links / direct exports) wins when no picker was sent.
    $explicitFrom = $parseReportDate($_GET['date_from'] ?? null);
    $explicitTo   = $parseReportDate($_GET['date_to'] ?? null);
    if ($explicitFrom !== null && $explicitTo !== null) {
        $reportFrom = $explicitFrom;
        $reportTo   = $explicitTo;
    } else {
        $reportFrom = $reportDefaults[$reportType]['from'];
        $reportTo   = $reportDefaults[$reportType]['to'];
    }
}

if ($reportFrom > $reportTo) {
    [$reportFrom, $reportTo] = [$reportTo, $reportFrom];
}

// Report heading + meta label adapt to the selected report type so the printed
// form and the Excel export read "DAILY/MONTHLY/YEARLY SALES AND REMITTANCE
// REPORT" and label their period as DATE (daily) or PERIOD (monthly/yearly).
$remitReportTitle = strtoupper($reportLabels[$reportType]) . ' SALES AND REMITTANCE REPORT';
$remitPeriodLabel = $reportType === 'daily' ? 'DATE' : 'PERIOD';

// Human-readable period for the form: a single day, a month name, a year, or an
// explicit range when the window is not one clean period.
if ($reportType === 'daily') {
    $remitDateLabel = format_date($reportFrom, 'M d, Y');
} elseif ($reportType === 'monthly'
    && substr($reportFrom, 0, 7) === substr($reportTo, 0, 7)
    && $reportFrom === date('Y-m-01', strtotime($reportFrom))
    && $reportTo === date('Y-m-t', strtotime($reportTo))) {
    $remitDateLabel = format_date($reportFrom, 'F Y');
} elseif ($reportType === 'yearly'
    && substr($reportFrom, 5) === '01-01'
    && substr($reportTo, 5) === '12-31'
    && substr($reportFrom, 0, 4) === substr($reportTo, 0, 4)) {
    $remitDateLabel = substr($reportFrom, 0, 4);
} else {
    $remitDateLabel = format_date($reportFrom, 'M d, Y') . ' to ' . format_date($reportTo, 'M d, Y');
}

// The active picker field/value rides on the export link so the workbook is
// always generated from exactly the same period as the on-screen report.
if ($reportType === 'daily') {
    $reportPickerParam = 'report_date';
    $reportPickerValue = $reportDate;
} elseif ($reportType === 'monthly') {
    $reportPickerParam = 'report_month';
    $reportPickerValue = $reportMonth;
} else {
    $reportPickerParam = 'report_year';
    $reportPickerValue = (string)$reportYear;
}

$reportXlsxQuery = http_build_query([
    'report_type'      => $reportType,
    'date_from'        => $reportFrom,
    'date_to'          => $reportTo,
    $reportPickerParam => $reportPickerValue,
    'export'           => 'xlsx',
]);

// ── Excel Remittance Export (runs before any HTML output) ────────────────────
// Fills the branch's styled "Sales and Remittance Report" template so the
// exported workbook matches the printed form exactly (borders, merges and all).
// The report type drives the title cell (DAILY/MONTHLY/YEARLY …).
if (($_GET['export'] ?? '') === 'xlsx') {
    // Fixed cylinder columns, matching the printed remittance form.
    $cylinderSizes = ['2.7', '5', '11', '22', '50'];

    // Map a product weight ("11kg") to one of the five cylinder columns.
    $sizeForWeight = function ($weight) use ($cylinderSizes): ?string {
        if (preg_match('/(\d+(?:\.\d+)?)/', (string)$weight, $m)) {
            $kg = (float)$m[1];
            foreach ($cylinderSizes as $size) {
                if (abs((float)$size - $kg) < 0.001) {
                    return $size;
                }
            }
        }
        return null;
    };

    $xlsxFilename = 'sales-report_' . $reportType . '_' . $reportFrom . '_' . $reportTo . '.xlsx';

    // ── Monthly / yearly: period breakdown + cylinder summary ────────────────
    if ($reportType !== 'daily') {
        $seriesBucket = $reportType === 'yearly' ? 'monthly' : 'daily';
        $breakdownRows = [];
        foreach ($orderModel->getSalesReportSeries($reportFrom, $reportTo, $seriesBucket) as $bucket) {
            $split = sales_vat_split((float)$bucket['revenue']);
            $breakdownRows[] = [
                'label'  => $seriesBucket === 'monthly'
                    ? format_date($bucket['key'] . '-01', 'F')
                    : $bucket['label'],
                'orders' => (int)$bucket['orders'],
                'units'  => (int)$bucket['units'],
                'gross'  => $split['gross'],
                'vat'    => $split['vat'],
                'net'    => $split['net'],
            ];
        }

        // Cylinder roll-up across every delivered transaction in the range.
        $perSizeQty    = array_fill_keys($cylinderSizes, 0);
        $perSizeAmount = array_fill_keys($cylinderSizes, 0.0);
        $sumQty    = 0;
        $sumAmount = 0.0;
        foreach ($orderModel->getSalesReportRows($reportFrom, $reportTo) as $tx) {
            $qty   = (int)$tx['quantity'];
            $size  = $sizeForWeight($tx['product_weight'] ?? null);
            $split = sales_vat_split((float)$tx['total_amount']);
            $sumQty    += $qty;
            $sumAmount  = round($sumAmount + $split['gross'], 2);
            if ($size !== null) {
                $perSizeQty[$size]   += $qty;
                $perSizeAmount[$size] = round($perSizeAmount[$size] + $split['gross'], 2);
            }
        }
        $sizeRows = [];
        foreach ($cylinderSizes as $size) {
            $sizeRows[] = ['size' => $size, 'qty' => $perSizeQty[$size], 'amount' => $perSizeAmount[$size]];
        }

        stream_xlsx(
            build_sales_breakdown_xlsx(
                $remitReportTitle,
                'CALOOCAN',
                $remitPeriodLabel . ': ' . $remitDateLabel,
                $reportType === 'yearly' ? 'MONTH' : 'DATE',
                $breakdownRows,
                ['rows' => $sizeRows, 'qty' => $sumQty, 'amount' => $sumAmount]
            ),
            $xlsxFilename
        );
        return; // TEST_MODE records the payload instead of exiting
    }

    // ── Daily: fill the styled remittance template ───────────────────────────
    $xlsxRows = $orderModel->getSalesReportRows($reportFrom, $reportTo);

    // The printed form provides exactly 20 transaction rows (spreadsheet 9-28).
    $maxRows = 20;

    $perSizeQty    = array_fill_keys($cylinderSizes, 0);
    $perSizeAmount = array_fill_keys($cylinderSizes, 0.0);

    $totalQty   = 0;
    $totalGross = 0.0;
    $totalVat   = 0.0;
    $totalNet   = 0.0;

    $cells    = [];
    $rowCount = 0;

    foreach ($xlsxRows as $tx) {
        if ($rowCount >= $maxRows) {
            break;
        }
        $rowNum  = 9 + $rowCount;
        $receipt = order_receipt_number((int)$tx['id']);
        $qty     = (int)$tx['quantity'];
        $split   = sales_vat_split((float)$tx['total_amount']);

        $cells['B' . $rowNum] = $receipt;
        $cells['C' . $rowNum] = (int)$tx['id'];
        $cells['D' . $rowNum] = (string)$tx['customer_name'];
        $cells['J' . $rowNum] = $qty;
        $cells['K' . $rowNum] = $split['gross'];
        $cells['M' . $rowNum] = $split['vat'];
        $cells['O' . $rowNum] = $split['net'];

        $size = $sizeForWeight($tx['product_weight'] ?? null);
        if ($size !== null) {
            $col = xlsx_column_letter(4 + array_search($size, $cylinderSizes, true)); // E..I
            $cells[$col . $rowNum] = $qty;
            $perSizeQty[$size]    += $qty;
            $perSizeAmount[$size]  = round($perSizeAmount[$size] + $split['gross'], 2);
        }

        $totalQty  += $qty;
        $totalGross = round($totalGross + $split['gross'], 2);
        $totalVat   = round($totalVat + $split['vat'], 2);
        $totalNet   = round($totalNet + $split['net'], 2);
        $rowCount++;
    }

    // Blank any unused rows (their borders stay) so no stale values remain.
    for (; $rowCount < $maxRows; $rowCount++) {
        $rowNum = 9 + $rowCount;
        foreach (['B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'M', 'O'] as $col) {
            $cells[$col . $rowNum] = null;
        }
    }

    // Per-size cylinder / kilogram roll-ups in the TOTAL rows.
    foreach ($cylinderSizes as $idx => $size) {
        $col = xlsx_column_letter(4 + $idx); // E..I
        $cells[$col . '29'] = $perSizeQty[$size];
        $cells[$col . '30'] = round($perSizeQty[$size] * (float)$size, 2);
    }

    $cells['J2']  = 'BRANCH: CALOOCAN';
    $cells['J3']  = $remitPeriodLabel . ': ' . $remitDateLabel;
    $cells['B6']  = $remitReportTitle; // overrides the template's "DAILY …" title
    $cells['K7']  = 'GROSS SALES (VAT-INCLUSIVE)';
    $cells['M7']  = 'OUTPUT VAT';
    $cells['O7']  = 'NET SALES (VAT-EXCLUSIVE)';
    $cells['J29'] = 'TOTAL QTY: ' . $totalQty;
    $cells['K29'] = 'TOTAL GROSS SALES: ' . format_currency($totalGross);
    $cells['M29'] = 'TOTAL OUTPUT VAT: ' . format_currency($totalVat);
    $cells['O29'] = 'TOTAL NET SALES: ' . format_currency($totalNet);

    // Summary-of-sales block: rows 36-40 per size, row 41 grand total.
    foreach ($cylinderSizes as $idx => $size) {
        $r = 36 + $idx;
        $cells['C' . $r] = $perSizeQty[$size];
        $cells['D' . $r] = $perSizeAmount[$size];
    }
    $cells['C41'] = $totalQty;
    $cells['D41'] = $totalGross;

    // Apply a real numeric peso number format to the money cells.
    $currencyCells = [];
    for ($r = 9; $r < 9 + $maxRows; $r++) {
        $currencyCells[] = 'K' . $r; // GROSS
        $currencyCells[] = 'M' . $r; // VAT
        $currencyCells[] = 'O' . $r; // NET
    }
    for ($r = 36; $r <= 41; $r++) {
        $currencyCells[] = 'D' . $r; // summary AMOUNT column + total
    }

    $template = dirname(__DIR__, 2) . '/templates/reports/sales-remittance.xlsx';
    stream_xlsx(
        fill_xlsx_template($template, $cells, ['currency' => $currencyCells]),
        $xlsxFilename
    );
    return; // TEST_MODE records the payload instead of exiting
}

// ── Sales Report Data (for the dashboard section) ────────────────────────────
$reportSummary = $orderModel->getSalesReportSummary($reportFrom, $reportTo);
// The breakdown is always one level finer than the report type: monthly reports
// break down by day, yearly reports by month (Jan–Dec).
$reportSeriesBucket = $reportType === 'yearly' ? 'monthly' : 'daily';
$reportSeries  = $orderModel->getSalesReportSeries($reportFrom, $reportTo, $reportSeriesBucket);
$reportRows    = $orderModel->getSalesReportRows($reportFrom, $reportTo);
$reportHasData = $reportSummary['orders'] > 0;
$reportGrandTotal = (float)$reportSummary['revenue'];
$reportVat = sales_vat_split($reportGrandTotal);

// Totals across the transaction rows (used by the print totals footer).
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

// ── Remittance form rows (print-only "Sales and Remittance Report") ──────────
// Mirrors the Excel export exactly: five fixed cylinder-size columns and 20
// transaction rows, then per-size and grand totals. The on-screen dashboard
// keeps its richer breakdown; this grid is what Print / Save PDF emits.
$remitSizes   = ['2.7', '5', '11', '22', '50'];
$remitMaxRows = 20;

// Map a product weight ("11kg") to one of the five cylinder columns.
$remitSizeForWeight = function ($weight) use ($remitSizes): ?string {
    if (preg_match('/(\d+(?:\.\d+)?)/', (string)$weight, $m)) {
        $kg = (float)$m[1];
        foreach ($remitSizes as $size) {
            if (abs((float)$size - $kg) < 0.001) {
                return $size;
            }
        }
    }
    return null;
};

$remitPerSizeQty    = array_fill_keys($remitSizes, 0);
$remitPerSizeAmount = array_fill_keys($remitSizes, 0.0);

$remitTotalQty   = 0;
$remitTotalGross = 0.0;
$remitTotalVat   = 0.0;
$remitTotalNet   = 0.0;

$remitRows = [];
foreach ($reportRows as $txRow) {
    if (count($remitRows) >= $remitMaxRows) {
        break;
    }
    $txSplit = sales_vat_split((float)$txRow['total_amount']);
    $qty     = (int)$txRow['quantity'];
    $size    = $remitSizeForWeight($txRow['product_weight'] ?? null);

    $remitRows[] = [
        'id'       => (int)$txRow['id'],
        'receipt'  => order_receipt_number((int)$txRow['id']),
        'customer' => (string)$txRow['customer_name'],
        'size'     => $size,
        'qty'      => $qty,
        'gross'    => $txSplit['gross'],
        'vat'      => $txSplit['vat'],
        'net'      => $txSplit['net'],
    ];

    if ($size !== null) {
        $remitPerSizeQty[$size]    += $qty;
        $remitPerSizeAmount[$size]  = round($remitPerSizeAmount[$size] + $txSplit['gross'], 2);
    }

    $remitTotalQty   += $qty;
    $remitTotalGross  = round($remitTotalGross + $txSplit['gross'], 2);
    $remitTotalVat    = round($remitTotalVat + $txSplit['vat'], 2);
    $remitTotalNet    = round($remitTotalNet + $txSplit['net'], 2);
}

// Cylinder roll-up across every delivered row — the monthly/yearly print
// summary is not capped to the 20-row daily window.
$reportSizeQty    = array_fill_keys($remitSizes, 0);
$reportSizeAmount = array_fill_keys($remitSizes, 0.0);
$reportSizeTotalQty    = 0;
$reportSizeTotalAmount = 0.0;
foreach ($reportRows as $txRow) {
    $txSplit = sales_vat_split((float)$txRow['total_amount']);
    $qty     = (int)$txRow['quantity'];
    $size    = $remitSizeForWeight($txRow['product_weight'] ?? null);
    $reportSizeTotalQty    += $qty;
    $reportSizeTotalAmount  = round($reportSizeTotalAmount + $txSplit['gross'], 2);
    if ($size !== null) {
        $reportSizeQty[$size]    += $qty;
        $reportSizeAmount[$size]  = round($reportSizeAmount[$size] + $txSplit['gross'], 2);
    }
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
                <div class="col-lg-12">
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

            <?php if ($reportType === 'daily'): ?>
            <!-- Print-only Daily Sales and Remittance Report (mirrors the Excel export) -->
            <div class="sales-print-only sales-remittance">
                <div class="remit-title"><?= e($remitReportTitle) ?></div>
                <div class="remit-meta">
                    <span>BRANCH: CALOOCAN</span>
                    <span><?= e($remitPeriodLabel) ?>: <?= e($remitDateLabel) ?></span>
                </div>

                <table class="remit-table">
                    <colgroup>
                        <col style="width: 11%;">
                        <col style="width: 12%;">
                        <col style="width: 16%;">
                        <col style="width: 6%;">
                        <col style="width: 6%;">
                        <col style="width: 6%;">
                        <col style="width: 6%;">
                        <col style="width: 6%;">
                        <col style="width: 6%;">
                        <col style="width: 8%;">
                        <col style="width: 8%;">
                        <col style="width: 9%;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th rowspan="2">INVOICE NO.</th>
                            <th rowspan="2">ORDER NO.</th>
                            <th rowspan="2">CUSTOMER</th>
                            <th colspan="5">CYLINDER SIZES</th>
                            <th rowspan="2">QTY</th>
                            <th rowspan="2">GROSS SALES (VAT-INCLUSIVE)</th>
                            <th rowspan="2">OUTPUT VAT</th>
                            <th rowspan="2">NET SALES (VAT-EXCLUSIVE)</th>
                        </tr>
                        <tr>
                            <?php foreach ($remitSizes as $size): ?>
                                <th><?= e($size) ?> KG</th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($i = 0; $i < $remitMaxRows; $i++): ?>
                            <?php $remitRow = $remitRows[$i] ?? null; ?>
                            <tr>
                                <td><?= $remitRow !== null ? e($remitRow['receipt']) : '&nbsp;' ?></td>
                                <td><?= $remitRow !== null ? (int)$remitRow['id'] : '&nbsp;' ?></td>
                                <td class="remit-left"><?= $remitRow !== null ? e($remitRow['customer']) : '&nbsp;' ?></td>
                                <?php foreach ($remitSizes as $size): ?>
                                    <td><?= ($remitRow !== null && $remitRow['size'] === $size) ? (int)$remitRow['qty'] : '&nbsp;' ?></td>
                                <?php endforeach; ?>
                                <td><?= $remitRow !== null ? (int)$remitRow['qty'] : '&nbsp;' ?></td>
                                <td class="remit-num"><?= $remitRow !== null ? e(format_currency($remitRow['gross'])) : '&nbsp;' ?></td>
                                <td class="remit-num"><?= $remitRow !== null ? e(format_currency($remitRow['vat'])) : '&nbsp;' ?></td>
                                <td class="remit-num"><?= $remitRow !== null ? e(format_currency($remitRow['net'])) : '&nbsp;' ?></td>
                            </tr>
                        <?php endfor; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" class="remit-left">TOTAL CYLINDER</td>
                            <?php foreach ($remitSizes as $size): ?>
                                <td><?= (int)$remitPerSizeQty[$size] ?></td>
                            <?php endforeach; ?>
                            <td><?= (int)$remitTotalQty ?></td>
                            <td class="remit-num"><?= e(format_currency($remitTotalGross)) ?></td>
                            <td class="remit-num"><?= e(format_currency($remitTotalVat)) ?></td>
                            <td class="remit-num"><?= e(format_currency($remitTotalNet)) ?></td>
                        </tr>
                        <tr>
                            <td colspan="3" class="remit-left">TOTAL KG</td>
                            <?php foreach ($remitSizes as $size): ?>
                                <td><?= e(number_format($remitPerSizeQty[$size] * (float)$size, 2)) ?></td>
                            <?php endforeach; ?>
                            <td>&nbsp;</td>
                            <td>&nbsp;</td>
                            <td>&nbsp;</td>
                            <td>&nbsp;</td>
                        </tr>
                    </tfoot>
                </table>

                <table class="remit-summary">
                    <thead>
                        <tr><th colspan="3">SUMMARY OF SALES</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="remit-left"></td>
                            <td colspan="2">REGULAR</td>
                        </tr>
                        <tr>
                            <td class="remit-left">CONTENT</td>
                            <td>QTY</td>
                            <td>AMOUNT</td>
                        </tr>
                        <?php foreach ($remitSizes as $size): ?>
                            <tr>
                                <td class="remit-left"><?= e($size) ?> KG</td>
                                <td><?= (int)$remitPerSizeQty[$size] ?></td>
                                <td class="remit-num"><?= e(format_currency($remitPerSizeAmount[$size])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="remit-summary-total">
                            <td class="remit-left">TOTAL</td>
                            <td><?= (int)$remitTotalQty ?></td>
                            <td class="remit-num"><?= e(format_currency($remitTotalGross)) ?></td>
                        </tr>
                    </tbody>
                </table>

                <div class="remit-prepared">PREPARED BY: ____________________</div>
            </div>
            <?php else: ?>
            <!-- Print-only Monthly / Yearly period breakdown (mirrors the Excel export) -->
            <div class="sales-print-only sales-remittance">
                <div class="remit-title"><?= e($remitReportTitle) ?></div>
                <div class="remit-meta">
                    <span>BRANCH: CALOOCAN</span>
                    <span><?= e($remitPeriodLabel) ?>: <?= e($remitDateLabel) ?></span>
                </div>

                <table class="remit-table remit-table--breakdown">
                    <thead>
                        <tr>
                            <th class="remit-left"><?= $reportType === 'yearly' ? 'MONTH' : 'DATE' ?></th>
                            <th>ORDERS</th>
                            <th>QTY</th>
                            <th>GROSS SALES (VAT-INCLUSIVE)</th>
                            <th>OUTPUT VAT</th>
                            <th>NET SALES (VAT-EXCLUSIVE)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reportSeries as $bucket): ?>
                            <?php
                            $bucketSplit = sales_vat_split((float)$bucket['revenue']);
                            $bucketLabel = $reportType === 'yearly'
                                ? format_date($bucket['key'] . '-01', 'F')
                                : $bucket['label'];
                            ?>
                            <tr>
                                <td class="remit-left"><?= e($bucketLabel) ?></td>
                                <td><?= (int)$bucket['orders'] ?></td>
                                <td><?= (int)$bucket['units'] ?></td>
                                <td class="remit-num"><?= e(format_currency($bucketSplit['gross'])) ?></td>
                                <td class="remit-num"><?= e(format_currency($bucketSplit['vat'])) ?></td>
                                <td class="remit-num"><?= e(format_currency($bucketSplit['net'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="remit-left">TOTAL</td>
                            <td><?= (int)$reportSeriesTotals['orders'] ?></td>
                            <td><?= (int)$reportSeriesTotals['units'] ?></td>
                            <td class="remit-num"><?= e(format_currency($reportSeriesTotals['gross'])) ?></td>
                            <td class="remit-num"><?= e(format_currency($reportSeriesTotals['vat'])) ?></td>
                            <td class="remit-num"><?= e(format_currency($reportSeriesTotals['net'])) ?></td>
                        </tr>
                    </tfoot>
                </table>

                <table class="remit-summary">
                    <thead>
                        <tr><th colspan="3">SUMMARY OF SALES</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="remit-left"></td>
                            <td colspan="2">REGULAR</td>
                        </tr>
                        <tr>
                            <td class="remit-left">CONTENT</td>
                            <td>QTY</td>
                            <td>AMOUNT</td>
                        </tr>
                        <?php foreach ($remitSizes as $size): ?>
                            <tr>
                                <td class="remit-left"><?= e($size) ?> KG</td>
                                <td><?= (int)$reportSizeQty[$size] ?></td>
                                <td class="remit-num"><?= e(format_currency($reportSizeAmount[$size])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="remit-summary-total">
                            <td class="remit-left">TOTAL</td>
                            <td><?= (int)$reportSizeTotalQty ?></td>
                            <td class="remit-num"><?= e(format_currency($reportSizeTotalAmount)) ?></td>
                        </tr>
                    </tbody>
                </table>

                <div class="remit-prepared">PREPARED BY: ____________________</div>
            </div>
            <?php endif; ?>

            <!-- Interactive generator: on-screen only; the print snapshot uses the
                 remittance form above instead. -->
            <div class="sales-screen-only">
            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-header bg-white py-3 border-bottom d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div>
                        <h5 class="card-title mb-0 fw-bold d-flex align-items-center gap-2">
                            <i class="bi bi-graph-up-arrow text-success"></i>Sales Report Generator
                        </h5>
                        <small class="text-muted">
                            <?= e($reportLabels[$reportType]) ?> report &middot;
                            <?= e($remitDateLabel) ?> &middot;
                            delivered orders only
                        </small>
                    </div>
                    <div class="d-flex gap-2 flex-wrap no-print">
                        <a href="<?= e(url('pages/admin/dashboard.php?' . $reportXlsxQuery)) ?>" class="btn btn-outline-success btn-sm fw-semibold" id="salesXlsxLink">
                            <i class="bi bi-file-earmark-excel me-1"></i>Export Excel
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
                        <input type="hidden" name="date_from" id="salesDateFrom" value="<?= e($reportFrom) ?>">
                        <input type="hidden" name="date_to" id="salesDateTo" value="<?= e($reportTo) ?>">
                        <div class="col-6 col-md-3 col-xl-2">
                            <?php if ($reportType === 'daily'): ?>
                                <label for="salesReportDate" class="form-label fw-semibold small text-muted mb-1">Date</label>
                                <input type="date" class="form-control" name="report_date" id="salesReportDate" value="<?= e($reportDate) ?>" max="<?= e($reportToday) ?>">
                            <?php elseif ($reportType === 'monthly'): ?>
                                <label for="salesReportMonth" class="form-label fw-semibold small text-muted mb-1">Month</label>
                                <input type="month" class="form-control" name="report_month" id="salesReportMonth" value="<?= e($reportMonth) ?>" max="<?= e(date('Y-m')) ?>">
                            <?php else: ?>
                                <label for="salesReportYear" class="form-label fw-semibold small text-muted mb-1">Year</label>
                                <input type="number" class="form-control" name="report_year" id="salesReportYear" value="<?= e((string)$reportYear) ?>" min="2000" max="<?= e(date('Y')) ?>" step="1">
                            <?php endif; ?>
                        </div>
                        <div class="col-12 col-md-2 col-xl-2">
                            <button type="submit" class="btn btn-primary fw-semibold w-100">
                                <i class="bi bi-search me-1"></i>Generate
                            </button>
                        </div>
                        <div class="col-12 col-xl-3">
                            <div class="d-flex flex-wrap gap-1 justify-content-xl-end">
                                <?php if ($reportType === 'daily'): ?>
                                    <button type="button" class="btn btn-outline-secondary btn-sm sales-report-preset" data-preset="today">Today</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm sales-report-preset" data-preset="yesterday">Yesterday</button>
                                <?php elseif ($reportType === 'monthly'): ?>
                                    <button type="button" class="btn btn-outline-secondary btn-sm sales-report-preset" data-preset="thisMonth">This Month</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm sales-report-preset" data-preset="lastMonth">Last Month</button>
                                <?php else: ?>
                                    <button type="button" class="btn btn-outline-secondary btn-sm sales-report-preset" data-preset="thisYear">This Year</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm sales-report-preset" data-preset="lastYear">Last Year</button>
                                <?php endif; ?>
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
                                    <div class="table-responsive sales-report-table-scroll">
                                        <table class="table table-hover align-middle mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th class="ps-4">Period</th>
                                                    <th class="text-end">Orders</th>
                                                    <th class="text-end">Units</th>
                                                    <th class="text-end">GROSS SALES (VAT-INCLUSIVE)</th>
                                                    <th class="text-end">OUTPUT VAT</th>
                                                    <th class="text-end pe-4">NET SALES (VAT-EXCLUSIVE)</th>
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
                                <div class="table-responsive sales-report-table-scroll sales-report-tx-scroll">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th class="ps-4">INVOICE NO.</th>
                                                <th>ORDER NO.</th>
                                                <th>DELIVERED</th>
                                                <th>CUSTOMER</th>
                                                <th>PRODUCT</th>
                                                <th class="text-end">QTY</th>
                                                <th class="text-end">UNIT PRICE</th>
                                                <th class="text-end">GROSS SALES (VAT-INCLUSIVE)</th>
                                                <th class="text-end">OUTPUT VAT</th>
                                                <th class="text-end">NET SALES (VAT-EXCLUSIVE)</th>
                                                <th class="text-end pe-4">PAYMENT</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($reportRows as $tx): ?>
                                                <?php $txSplit = sales_vat_split((float)$tx['total_amount']); ?>
                                                <tr class="app-clickable-row" data-href="<?= url('pages/admin/order-detail.php?id=' . (int)$tx['id']) ?>">
                                                    <td class="ps-4 fw-bold text-primary"><?= e(order_receipt_number((int)$tx['id'])) ?></td>
                                                    <td class="text-muted">#<?= (int)$tx['id'] ?></td>
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
            </div><!-- /.sales-screen-only -->
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
                        <div class="table-responsive sales-report-table-scroll sales-report-tx-scroll">
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
