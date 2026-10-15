<?php
/**
 * Admin Portal Automated Integration Test Suite
 * LPG Delivery System v2
 *
 * Tests:
 * 1. Syntax & Linting Integrity
 * 2. Admin Role Authorization & Access Route Guards
 * 3. Dashboard Workflows, Operational Metrics & Aggregations
 * 4. Order Management, Approval, Concurrency-Safe Rider Dispatch & Cancellation
 * 5. Inventory Catalog Management, Stock/Price Adjustments & Status Toggles
 * 6. User Account Management, Status Toggles, Self-Lockout Guard & Secure ID Proxy
 * 7. Security Checks (CSRF Protection & XSS Escaping)
 */

// Enable test mode before components load
$GLOBALS['TEST_MODE'] = true;

// Initialize session in CLI before any output is sent
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// Load core dependencies
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Product.php';
require_once __DIR__ . '/../classes/Order.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/middleware.php';

$testCount = 0;
$passCount = 0;
$failCount = 0;

function it(string $description, callable $fn): void {
    global $testCount, $passCount, $failCount;
    $testCount++;
    try {
        $result = $fn();
        if ($result !== false) {
            $passCount++;
            echo "  ✓ {$description}\n";
        } else {
            $failCount++;
            echo "  ✗ {$description} (returned false)\n";
        }
    } catch (Throwable $e) {
        $failCount++;
        echo "  ✗ {$description} (Exception: {$e->getMessage()} at {$e->getFile()}:{$e->getLine()})\n";
    }
}

function assert_equals($expected, $actual, string $message = ''): bool {
    if ($expected !== $actual) {
        throw new Exception(
            ($message ? $message . ': ' : '') .
            'Expected ' . var_export($expected, true) . ' but got ' . var_export($actual, true)
        );
    }
    return true;
}

function assert_true($actual, string $message = ''): bool {
    if ($actual !== true) {
        throw new Exception(($message ? $message . ': ' : '') . 'Expected true but got ' . var_export($actual, true));
    }
    return true;
}

function assert_false($actual, string $message = ''): bool {
    if ($actual !== false) {
        throw new Exception(($message ? $message . ': ' : '') . 'Expected false but got ' . var_export($actual, true));
    }
    return true;
}

function assert_not_empty($actual, string $message = ''): bool {
    if (empty($actual)) {
        throw new Exception(($message ? $message . ': ' : '') . 'Expected non-empty value but got ' . var_export($actual, true));
    }
    return true;
}

function assert_contains(string $needle, string $haystack, string $message = ''): bool {
    if (strpos($haystack, $needle) === false) {
        throw new Exception(
            ($message ? $message . ': ' : '') .
            'Expected string to contain "' . substr($needle, 0, 100) . '" but not found.'
        );
    }
    return true;
}

function assert_not_contains(string $needle, string $haystack, string $message = ''): bool {
    if (strpos($haystack, $needle) !== false) {
        throw new Exception(
            ($message ? $message . ': ' : '') .
            'Expected string to NOT contain "' . substr($needle, 0, 100) . '" but was found.'
        );
    }
    return true;
}

// Reset request environment between tests
function reset_admin_env(): void {
    $_SESSION = [];
    $_POST = [];
    $_GET = [];
    $_FILES = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    unset(
        $_SERVER['HTTP_X_REQUESTED_WITH'],
        $_SERVER['HTTP_X_CSRF_TOKEN'],
        $_SERVER['HTTP_CSRF_TOKEN'],
        $_SERVER['HTTP_ACCEPT'],
        $_SERVER['CONTENT_TYPE']
    );
    unset($GLOBALS['LAST_HTTP_CODE'], $GLOBALS['LAST_REDIRECT'], $GLOBALS['LAST_RESPONSE'], $GLOBALS['LAST_SERVED_FILE']);
}

// Render page buffer in test mode
function render_admin_page(string $pagePath): string {
    ob_start();
    try {
        include $pagePath;
    } catch (AuthException $e) {
        // Expected during test mode redirects
    }
    return ob_get_clean();
}

echo "====================================================\n";
echo " LPG Delivery System v2 - Admin Portal Test Suite\n";
echo "====================================================\n\n";

$db = Database::connect();
$userModel = new User($db);
$productModel = new Product($db);
$orderModel = new Order($db);

// Retrieve or seed standard test admin
$testAdmin = $userModel->findByEmail('admin@lpg.com');
if (!$testAdmin) {
    $adminPass = password_hash('password', PASSWORD_BCRYPT, ['cost' => 12]);
    $stmt = $db->prepare("
        INSERT INTO users (full_name, email, password, role, phone, address, status, created_at, updated_at)
        VALUES ('Maria Santos', 'admin@lpg.com', ?, 'admin', '09289876543', 'Admin HQ, Quezon City', 'active', NOW(), NOW())
    ");
    $stmt->execute([$adminPass]);
    $testAdmin = $userModel->findByEmail('admin@lpg.com');
}

// Retrieve or seed test rider
$testRider = $userModel->findByEmail('rider@lpg.com');
if (!$testRider) {
    $riderPass = password_hash('password', PASSWORD_BCRYPT, ['cost' => 12]);
    $stmt = $db->prepare("
        INSERT INTO users (full_name, email, password, role, phone, address, status, created_at, updated_at)
        VALUES ('Pedro Reyes', 'rider@lpg.com', ?, 'rider', '09351112222', '456 Mabini Ave, Caloocan City', 'active', NOW(), NOW())
    ");
    $stmt->execute([$riderPass]);
    $testRider = $userModel->findByEmail('rider@lpg.com');
}

// Retrieve or seed test customer
$testCustomer = $userModel->findByEmail('customer@lpg.com');
if (!$testCustomer) {
    $custPass = password_hash('password', PASSWORD_BCRYPT, ['cost' => 12]);
    $stmt = $db->prepare("
        INSERT INTO users (full_name, email, password, role, phone, address, status, created_at, updated_at)
        VALUES ('Janister Singson', 'customer@lpg.com', ?, 'customer', '09171234567', '123 Rizal St, Caloocan City', 'active', NOW(), NOW())
    ");
    $stmt->execute([$custPass]);
    $testCustomer = $userModel->findByEmail('customer@lpg.com');
}

$adminId = (int)$testAdmin['id'];
$riderId = (int)$testRider['id'];
$customerId = (int)$testCustomer['id'];

// =========================================================================
// Group 1: Syntax & File Integrity
// =========================================================================
echo "Group 1: Syntax & File Integrity\n";

it('dashboard.php passes syntax linting', function () {
    exec('php -l ' . escapeshellarg(__DIR__ . '/../pages/admin/dashboard.php') . ' 2>&1', $output, $code);
    assert_equals(0, $code, 'dashboard.php syntax error: ' . implode("\n", $output));
});

it('orders.php passes syntax linting', function () {
    exec('php -l ' . escapeshellarg(__DIR__ . '/../pages/admin/orders.php') . ' 2>&1', $output, $code);
    assert_equals(0, $code, 'orders.php syntax error: ' . implode("\n", $output));
});

it('inventory.php passes syntax linting', function () {
    exec('php -l ' . escapeshellarg(__DIR__ . '/../pages/admin/inventory.php') . ' 2>&1', $output, $code);
    assert_equals(0, $code, 'inventory.php syntax error: ' . implode("\n", $output));
});

it('users.php passes syntax linting', function () {
    exec('php -l ' . escapeshellarg(__DIR__ . '/../pages/admin/users.php') . ' 2>&1', $output, $code);
    assert_equals(0, $code, 'users.php syntax error: ' . implode("\n", $output));
});

it('view_id.php passes syntax linting', function () {
    exec('php -l ' . escapeshellarg(__DIR__ . '/../pages/admin/view_id.php') . ' 2>&1', $output, $code);
    assert_equals(0, $code, 'view_id.php syntax error: ' . implode("\n", $output));
});

it('admin.js exists and contains core admin event handlers', function () {
    $jsPath = __DIR__ . '/../assets/js/admin.js';
    assert_true(file_exists($jsPath), 'admin.js file must exist');
    $content = file_get_contents($jsPath);
    assert_contains('admin-order-filter-btn', $content);
    assert_contains('btn-open-assign-rider', $content);
    assert_contains('btn-open-cancel-order', $content);
    assert_contains('btn-view-order-details', $content);
    assert_contains('btn-open-stock-modal', $content);
    assert_contains('btn-open-edit-modal', $content);
    assert_contains('admin-user-filter-btn', $content);
});

// =========================================================================
// Group 2: Role Authorization & Route Guards
// =========================================================================
echo "\nGroup 2: Role Authorization & Route Guards\n";

it('Unauthenticated guests are blocked from admin pages and redirected to login', function () {
    reset_admin_env();

    try {
        require_role('admin');
        assert_true(false, 'Should have thrown AuthException');
    } catch (AuthException $e) {
        assert_equals(302, $e->getStatusCode());
        assert_equals(url('/index.php'), $e->getRedirectUrl());
    }
});

it('Customer accounts accessing admin pages are blocked with 403 and redirected to customer portal', function () use ($testCustomer) {
    reset_admin_env();
    login_user($testCustomer);

    try {
        require_role('admin');
        assert_true(false, 'Customer should not pass admin role check');
    } catch (AuthException $e) {
        assert_equals(403, $e->getStatusCode());
        assert_equals(url('/pages/customer/shop.php'), $e->getRedirectUrl());
    }
});

it('Rider accounts accessing admin pages are blocked with 403 and redirected to rider portal', function () use ($testRider) {
    reset_admin_env();
    login_user($testRider);

    try {
        require_role('admin');
        assert_true(false, 'Rider should not pass admin role check');
    } catch (AuthException $e) {
        assert_equals(403, $e->getStatusCode());
        assert_equals(url('/pages/rider/deliveries.php'), $e->getRedirectUrl());
    }
});

it('Authenticated administrators are granted full access to admin pages', function () use ($testAdmin) {
    reset_admin_env();
    login_user($testAdmin);

    require_role('admin');
    assert_true(is_logged_in());
    assert_equals('admin', current_user_role());
});

// =========================================================================
// Group 3: Admin Dashboard (dashboard.php)
// =========================================================================
echo "\nGroup 3: Admin Dashboard (dashboard.php)\n";

it('Order::getDashboardStats calculates operational metrics accurately', function () use ($orderModel) {
    $stats = $orderModel->getDashboardStats();

    assert_true(isset($stats['total_orders']));
    assert_true(isset($stats['pending_orders']));
    assert_true(isset($stats['in_transit_orders']));
    assert_true(isset($stats['delivered_orders']));
    assert_true(isset($stats['cancelled_orders']));
    assert_true(isset($stats['total_revenue']));
    assert_true(is_array($stats['recent_orders']));

    assert_true($stats['total_orders'] >= 0);
    assert_true($stats['total_revenue'] >= 0.0);
    assert_true(count($stats['recent_orders']) <= 10);
});

it('dashboard.php renders stat cards, recent orders table, low stock alerts, and quick actions', function () use ($testAdmin) {
    reset_admin_env();
    login_user($testAdmin);

    $html = render_admin_page(__DIR__ . '/../pages/admin/dashboard.php');

    assert_contains('Admin Dashboard', $html);
    assert_contains('System Operations Control Center', $html);
    assert_contains('Total Revenue', $html);
    assert_contains('Pending Orders', $html);
    assert_contains('In-Transit', $html);
    assert_contains('Delivered', $html);
    assert_contains('Total Orders', $html);
    assert_contains('Recent Orders', $html);
    assert_contains('Inventory Stock Alerts', $html);
    assert_contains('Management Hub', $html);
});

it('dashboard.php renders the sales report generator, filters, chart data, and export controls', function () use ($testAdmin) {
    reset_admin_env();
    login_user($testAdmin);

    $html = render_admin_page(__DIR__ . '/../pages/admin/dashboard.php');

    assert_contains('id="salesReport"', $html);
    assert_contains('Sales Report Generator', $html);
    assert_contains('id="salesReportForm"', $html);
    assert_contains('name="report_type"', $html);
    assert_contains('name="date_from"', $html);
    assert_contains('name="date_to"', $html);
    assert_contains('sales-report-type-btn', $html);
    assert_contains('sales-report-preset', $html);
    assert_contains('id="salesTrendChart"', $html);
    assert_contains('id="salesChartData"', $html);
    assert_contains('id="salesXlsxLink"', $html);
    assert_contains('export=xlsx', $html);
    assert_contains('id="salesPrintBtn"', $html);
    assert_contains('Breakdown by Period', $html);
    assert_contains('Transaction Detail', $html);
    assert_contains('INVOICE NO.', $html);
    assert_contains('ORDER NO.', $html);
    assert_contains('GROSS SALES (VAT-INCLUSIVE)', $html);
    assert_contains('OUTPUT VAT', $html);
    assert_contains('NET SALES (VAT-EXCLUSIVE)', $html);
    assert_contains('Total Revenue', $html);
    assert_contains('Avg Order Value', $html);
});

it('dashboard.php rejects invalid report type and date filters, falling back to defaults', function () use ($testAdmin) {
    reset_admin_env();
    login_user($testAdmin);

    $_GET = [
        'report_type' => 'nonsense',
        'date_from'   => '2026-99-99',
        'date_to'     => 'not-a-date',
    ];

    $html = render_admin_page(__DIR__ . '/../pages/admin/dashboard.php');

    // Unknown type falls back to Daily; unparseable dates fall back to the
    // daily default window (last 30 days, ending today).
    assert_contains('Daily report', $html);
    assert_contains('value="' . date('Y-m-d') . '"', $html);
    assert_not_contains('not-a-date', $html);
});

it('Order sales report aggregates delivered orders only, inside the selected range', function () use ($testAdmin, $orderModel, $productModel, $db, $customerId) {
    reset_admin_env();
    login_user($testAdmin);

    $from = '2026-06-01';
    $to   = '2026-06-30';

    $before = $orderModel->getSalesReportSummary($from, $to);

    $products = $productModel->getActive();
    $prod = $products[0];
    $qty = 2;

    $orderId = $orderModel->place([
        'customer_id'      => $customerId,
        'product_id'       => $prod['id'],
        'quantity'         => $qty,
        'payment_method'   => 'cod',
        'delivery_address' => 'Sales Report Test Address',
        'contact_phone'    => '09171234567',
        'status'           => 'pending'
    ]);

    // Fulfil directly: the report reads status + delivered_at.
    $db->prepare("UPDATE orders SET status = 'delivered', delivered_at = '2026-06-15 10:00:00' WHERE id = ?")
       ->execute([$orderId]);
    $order = $orderModel->findById($orderId);
    $amount = round((float)$order['total_amount'], 2);

    $after = $orderModel->getSalesReportSummary($from, $to);
    assert_equals($before['orders'] + 1, $after['orders'], 'Delivered order must be counted in range');
    assert_equals($before['units'] + $qty, $after['units'], 'Units must include the new delivery');
    assert_equals(round($before['revenue'] + $amount, 2), round($after['revenue'], 2), 'Revenue must include the new delivery');
    assert_equals(round($after['avg_order'], 2), round($after['revenue'] / max(1, $after['orders']), 2), 'Average order value must equal revenue / orders');

    // Rows: present inside the window, absent outside it.
    $foundInRange = false;
    foreach ($orderModel->getSalesReportRows($from, $to) as $row) {
        if ((int)$row['id'] === $orderId) {
            $foundInRange = true;
            assert_equals($prod['name'], $row['product_name']);
            assert_equals($qty, (int)$row['quantity']);
        }
    }
    assert_true($foundInRange, 'Delivered order must appear in report rows for its range');
    foreach ($orderModel->getSalesReportRows('2026-07-01', '2026-07-31') as $row) {
        assert_true((int)$row['id'] !== $orderId, 'Order delivered in June must not appear in a July range');
    }

    // Series: monthly bucket for June carries the sale.
    $series = $orderModel->getSalesReportSeries($from, $to, 'monthly');
    assert_equals(1, count($series), 'June 2026 range must produce exactly one monthly bucket');
    assert_equals('2026-06', $series[0]['key']);
    assert_equals('Jun 2026', $series[0]['label']);
    assert_true($series[0]['orders'] >= 1, 'June bucket must contain at least the new delivery');
    assert_true($series[0]['units'] >= $qty, 'June bucket must contain at least the new units');
    assert_true($series[0]['revenue'] >= $amount);

    // Daily series zero-fills gaps so the chart axis stays continuous.
    $daily = $orderModel->getSalesReportSeries('2026-06-14', '2026-06-16', 'daily');
    assert_equals(3, count($daily), 'A three-day window must yield three daily buckets');
    assert_equals('2026-06-15', $daily[1]['key']);
    assert_equals('Jun 15, 2026', $daily[1]['label']);

    // Non-delivered orders never count as sales.
    $pendingId = $orderModel->place([
        'customer_id'      => $customerId,
        'product_id'       => $prod['id'],
        'quantity'         => 1,
        'payment_method'   => 'cod',
        'delivery_address' => 'Sales Report Pending Address',
        'contact_phone'    => '09171234567',
        'status'           => 'pending'
    ]);
    $summaryWithPending = $orderModel->getSalesReportSummary($from, $to);
    assert_equals($after['orders'], $summaryWithPending['orders'], 'Pending orders must not be counted as sales');
    assert_equals(round($after['revenue'], 2), round($summaryWithPending['revenue'], 2), 'Pending orders must not add revenue');

    // Clean up
    $db->prepare("DELETE FROM orders WHERE id IN (?, ?)")->execute([$orderId, $pendingId]);
});

it('dashboard.php streams the Daily sales report as an XLSX remittance form when export=xlsx', function () use ($testAdmin) {
    reset_admin_env();
    unset($GLOBALS['LAST_XLSX']);
    login_user($testAdmin);

    $_GET = [
        'report_type' => 'daily',
        'date_from'   => '2026-06-15',
        'date_to'     => '2026-06-15',
        'export'      => 'xlsx',
    ];

    $html = render_admin_page(__DIR__ . '/../pages/admin/dashboard.php');

    assert_equals('', $html, 'XLSX export must not render the dashboard HTML');
    assert_true(isset($GLOBALS['LAST_XLSX']), 'XLSX payload must be recorded in test mode');
    assert_equals('sales-report_daily_2026-06-15_2026-06-15.xlsx', $GLOBALS['LAST_XLSX']['filename']);
    assert_true($GLOBALS['LAST_XLSX']['size'] > 0);

    // Unpack the workbook and read back the worksheet + content type parts.
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx_test');
    file_put_contents($tmp, $GLOBALS['LAST_XLSX']['xlsx']);
    $zip = new ZipArchive();
    assert_equals(true, $zip->open($tmp) === true, 'XLSX payload must be a valid zip archive');

    $contentTypes = (string)$zip->getFromName('[Content_Types].xml');
    $sheet        = (string)$zip->getFromName('xl/worksheets/sheet1.xml');
    $strings      = (string)$zip->getFromName('xl/sharedStrings.xml');
    $styles       = (string)$zip->getFromName('xl/styles.xml');
    $zip->close();
    @unlink($tmp);

    assert_contains('spreadsheetml.sheet.main+xml', $contentTypes);

    // Static form labels live in the shared string table; injected values are
    // written inline into the worksheet, so inspect both parts together.
    $form = $sheet . $strings;

    assert_contains('DAILY SALES AND REMITTANCE REPORT', $sheet);
    assert_contains('BRANCH: CALOOCAN', $sheet);
    assert_contains('DATE: ', $sheet);
    assert_contains('Jun 15, 2026', $sheet);
    assert_contains('INVOICE NO.', $form);
    assert_contains('ORDER NO.', $form);
    assert_contains('CUSTOMER', $form);
    assert_contains('CYLINDER SIZES', $form);
    assert_contains('2.7 KG', $form);
    assert_contains('50 KG', $form);
    assert_contains('GROSS SALES (VAT-INCLUSIVE)', $sheet);
    assert_contains('OUTPUT VAT', $sheet);
    assert_contains('NET SALES (VAT-EXCLUSIVE)', $sheet);
    assert_not_contains('GROSS AMOUNT', $sheet);
    assert_not_contains('VAT AMOUNT', $sheet);
    assert_not_contains('NET AMOUNT', $sheet);
    assert_contains('TOTAL CYLINDER', $form);
    assert_contains('TOTAL KG', $form);
    assert_contains('TOTAL QTY:', $sheet);
    assert_contains('TOTAL GROSS SALES:', $sheet);
    assert_contains('TOTAL OUTPUT VAT:', $sheet);
    assert_contains('TOTAL NET SALES:', $sheet);
    assert_true(strpos($form, 'DAILY AVE') === false, 'The retired DAILY AVE row must be gone');
    assert_contains('SUMMARY OF SALES', $form);
    assert_contains('REGULAR', $form);
    assert_contains('CONTENT', $form);
    assert_contains('PREPARED BY:', $form);
    assert_contains('<mergeCell ref="B6:P6"/>', $sheet);
    assert_contains('<mergeCell ref="M7:N8"/>', $sheet);

    // Money cells carry a real numeric peso number format.
    assert_contains('numFmtId="164"', $styles);
    assert_contains('#,##0.00', $styles);
    assert_contains('applyNumberFormat="1"', $styles);
    assert_equals('₱0.00', format_currency(0));
});

it('dashboard.php streams the Monthly report as a per-day XLSX breakdown', function () use ($testAdmin) {
    reset_admin_env();
    unset($GLOBALS['LAST_XLSX']);
    login_user($testAdmin);

    $_GET = [
        'report_type'  => 'monthly',
        'report_month' => '2026-06',
        'export'       => 'xlsx',
    ];

    $html = render_admin_page(__DIR__ . '/../pages/admin/dashboard.php');
    assert_equals('', $html, 'XLSX export must not render the dashboard HTML');
    assert_equals('sales-report_monthly_2026-06-01_2026-06-30.xlsx', $GLOBALS['LAST_XLSX']['filename']);

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx_test');
    file_put_contents($tmp, $GLOBALS['LAST_XLSX']['xlsx']);
    $zip = new ZipArchive();
    assert_equals(true, $zip->open($tmp) === true, 'Monthly XLSX must be a valid zip archive');
    $contentTypes = (string)$zip->getFromName('[Content_Types].xml');
    $sheet        = (string)$zip->getFromName('xl/worksheets/sheet1.xml');
    $styles       = (string)$zip->getFromName('xl/styles.xml');
    $zip->close();
    @unlink($tmp);

    assert_contains('spreadsheetml.sheet.main+xml', $contentTypes);
    assert_contains('MONTHLY SALES AND REMITTANCE REPORT', $sheet);
    assert_contains('BRANCH: CALOOCAN', $sheet);
    assert_contains('PERIOD: June 2026', $sheet);
    assert_contains('DATE', $sheet);
    assert_contains('ORDERS', $sheet);
    assert_contains('QTY', $sheet);
    assert_contains('GROSS SALES (VAT-INCLUSIVE)', $sheet);
    assert_contains('OUTPUT VAT', $sheet);
    assert_contains('NET SALES (VAT-EXCLUSIVE)', $sheet);
    assert_contains('Jun 1, 2026', $sheet);
    assert_contains('Jun 30, 2026', $sheet);
    assert_contains('TOTAL', $sheet);
    assert_contains('SUMMARY OF SALES', $sheet);
    assert_contains('2.7 KG', $sheet);
    assert_contains('50 KG', $sheet);
    assert_contains('<mergeCell ref="A1:F1"/>', $sheet);
    // Money cells use the currency number format.
    assert_contains('numFmtId="164"', $styles);
});

it('dashboard.php streams the Yearly report as a per-month XLSX breakdown (Jan-Dec)', function () use ($testAdmin) {
    reset_admin_env();
    unset($GLOBALS['LAST_XLSX']);
    login_user($testAdmin);

    $_GET = [
        'report_type' => 'yearly',
        'report_year' => '2026',
        'export'      => 'xlsx',
    ];

    $html = render_admin_page(__DIR__ . '/../pages/admin/dashboard.php');
    assert_equals('', $html, 'XLSX export must not render the dashboard HTML');
    assert_equals('sales-report_yearly_2026-01-01_2026-12-31.xlsx', $GLOBALS['LAST_XLSX']['filename']);

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx_test');
    file_put_contents($tmp, $GLOBALS['LAST_XLSX']['xlsx']);
    $zip = new ZipArchive();
    assert_equals(true, $zip->open($tmp) === true, 'Yearly XLSX must be a valid zip archive');
    $sheet = (string)$zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    @unlink($tmp);

    assert_contains('YEARLY SALES AND REMITTANCE REPORT', $sheet);
    assert_contains('PERIOD: 2026', $sheet);
    assert_contains('MONTH', $sheet);
    foreach (['January', 'February', 'March', 'April', 'May', 'June', 'July',
              'August', 'September', 'October', 'November', 'December'] as $month) {
        assert_contains($month, $sheet, 'Yearly breakdown must list ' . $month);
    }
    assert_contains('TOTAL', $sheet);
    assert_contains('SUMMARY OF SALES', $sheet);
});

it('sales report XLSX export is restricted to administrators', function () use ($testCustomer) {
    reset_admin_env();
    unset($GLOBALS['LAST_XLSX']);
    login_user($testCustomer);

    $_GET = ['export' => 'xlsx'];

    render_admin_page(__DIR__ . '/../pages/admin/dashboard.php');

    assert_true(!isset($GLOBALS['LAST_XLSX']), 'Customers must never receive the sales workbook');
});

it('dashboard.php print view renders the Daily Sales and Remittance form (matches the Excel export)', function () use ($testAdmin, $orderModel, $productModel, $db, $customerId) {
    reset_admin_env();
    login_user($testAdmin);

    // Seed one delivered order on a unique day so the row-level assertions are
    // deterministic and it always lands inside the 20-row remittance window.
    $products = $productModel->getActive();
    $prod = $products[0];
    $orderId = $orderModel->place([
        'customer_id'      => $customerId,
        'product_id'       => $prod['id'],
        'quantity'         => 2,
        'payment_method'   => 'cod',
        'delivery_address' => 'Print View Test Address',
        'contact_phone'    => '09171234567',
        'status'           => 'pending'
    ]);
    $db->prepare("UPDATE orders SET status = 'delivered', delivered_at = '2026-06-15 10:00:00' WHERE id = ?")
       ->execute([$orderId]);
    $order = $orderModel->findById($orderId);
    $txSplit = sales_vat_split((float)$order['total_amount']);

    $_GET = [
        'report_type' => 'daily',
        'date_from'   => '2026-06-15',
        'date_to'     => '2026-06-15',
    ];
    $html = render_admin_page(__DIR__ . '/../pages/admin/dashboard.php');

    // Print-only remittance form, mirroring the Excel export template.
    assert_contains('sales-print-only', $html);
    assert_contains('sales-remittance', $html);
    assert_contains('DAILY SALES AND REMITTANCE REPORT', $html);
    assert_contains('BRANCH: CALOOCAN', $html);
    assert_contains('DATE: ', $html);
    assert_contains('INVOICE NO.', $html);
    assert_contains('ORDER NO.', $html);
    assert_contains('CUSTOMER', $html);
    assert_contains('CYLINDER SIZES', $html);
    assert_contains('2.7 KG', $html);
    assert_contains('5 KG', $html);
    assert_contains('11 KG', $html);
    assert_contains('22 KG', $html);
    assert_contains('50 KG', $html);
    assert_contains('GROSS SALES (VAT-INCLUSIVE)', $html);
    assert_contains('OUTPUT VAT', $html);
    assert_contains('NET SALES (VAT-EXCLUSIVE)', $html);
    assert_contains('TOTAL CYLINDER', $html);
    assert_contains('TOTAL KG', $html);
    assert_contains('SUMMARY OF SALES', $html);
    assert_contains('CONTENT', $html);
    assert_contains('PREPARED BY:', $html);

    // The interactive generator (filters, cards, chart, tables) never prints.
    assert_contains('sales-screen-only', $html);

    // The seeded order renders with its ORD- receipt number and its VAT amount.
    assert_contains(order_receipt_number($orderId), $html);
    assert_contains(format_currency($txSplit['vat']), $html);
    assert_contains(format_currency($txSplit['net']), $html);

    // The on-screen tables separate the invoice from the raw order number and
    // use the BIR VAT column names.
    $screen = substr($html, strpos($html, 'sales-screen-only'));
    assert_contains('INVOICE NO.', $screen);
    assert_contains('ORDER NO.', $screen);
    assert_contains('#' . $orderId, $screen);
    assert_contains('GROSS SALES (VAT-INCLUSIVE)', $screen);
    assert_contains('OUTPUT VAT', $screen);
    assert_contains('NET SALES (VAT-EXCLUSIVE)', $screen);

    // Long-bond paper + the Excel-style collapsed grid.
    $css = file_get_contents(__DIR__ . '/../assets/css/app.css');
    assert_contains('size: 215.9mm 330.2mm', $css);
    assert_contains('.sales-print-only { display: none !important; }', $css);
    assert_contains('border-collapse: collapse !important;', $css);
    assert_contains('.sales-screen-only {', $css);

    $db->prepare("DELETE FROM orders WHERE id = ?")->execute([$orderId]);
});

it('dashboard.php heads the remittance form and picker per report type (daily/monthly/yearly)', function () use ($testAdmin) {
    $cases = [
        'daily'   => ['title' => 'DAILY SALES AND REMITTANCE REPORT',   'label' => 'DATE:',   'picker' => 'name="report_date"'],
        'monthly' => ['title' => 'MONTHLY SALES AND REMITTANCE REPORT', 'label' => 'PERIOD:', 'picker' => 'name="report_month"'],
        'yearly'  => ['title' => 'YEARLY SALES AND REMITTANCE REPORT',  'label' => 'PERIOD:', 'picker' => 'name="report_year"'],
    ];

    foreach ($cases as $type => $expected) {
        reset_admin_env();
        login_user($testAdmin);
        $_GET = ['report_type' => $type];

        $html = render_admin_page(__DIR__ . '/../pages/admin/dashboard.php');

        assert_contains($expected['title'], $html, $type . ' report title');
        assert_contains($expected['label'], $html, $type . ' period label');
        assert_contains($expected['picker'], $html, $type . ' picker');
    }

    $_GET = [];
});

it('dashboard.php prints a monthly/yearly period breakdown with cylinder summary', function () use ($testAdmin) {
    // Monthly: per-day DATE column, TOTAL row, and the SUMMARY OF SALES block.
    reset_admin_env();
    login_user($testAdmin);
    $_GET = ['report_type' => 'monthly', 'report_month' => '2026-06'];
    $monthly = render_admin_page(__DIR__ . '/../pages/admin/dashboard.php');

    assert_contains('sales-print-only', $monthly);
    assert_contains('MONTHLY SALES AND REMITTANCE REPORT', $monthly);
    assert_contains('PERIOD: June 2026', $monthly);
    assert_contains('>DATE<', $monthly);
    assert_contains('SUMMARY OF SALES', $monthly);
    assert_contains('PREPARED BY:', $monthly);
    // The daily cylinder-column grid must not leak into the monthly form.
    assert_true(strpos($monthly, 'CYLINDER SIZES') === false, 'Monthly form must not use the daily column grid');
    assert_true(strpos($monthly, 'TOTAL CYLINDER') === false, 'Monthly form must not use the daily cylinder footer');

    // Yearly: per-month MONTH column and full month names Jan-Dec.
    reset_admin_env();
    login_user($testAdmin);
    $_GET = ['report_type' => 'yearly', 'report_year' => '2026'];
    $yearly = render_admin_page(__DIR__ . '/../pages/admin/dashboard.php');

    assert_contains('YEARLY SALES AND REMITTANCE REPORT', $yearly);
    assert_contains('PERIOD: 2026', $yearly);
    assert_contains('>MONTH<', $yearly);
    assert_contains('January', $yearly);
    assert_contains('December', $yearly);
    assert_contains('SUMMARY OF SALES', $yearly);

    $_GET = [];
});

it('sales_vat_split() extracts 12% VAT from VAT-inclusive gross amounts', function () {
    $split = sales_vat_split(1120.0);
    assert_equals(1120.0, $split['gross']);
    assert_equals(120.0, $split['vat']);
    assert_equals(1000.0, $split['net']);

    $split = sales_vat_split(6412.5);
    assert_equals(6412.5, $split['gross']);
    assert_equals(687.05, $split['vat']);
    assert_equals(5725.45, $split['net']);

    // VAT + net must always recombine to the original gross after rounding.
    $split = sales_vat_split(999.99);
    assert_equals(999.99, round($split['vat'] + $split['net'], 2));
});

// =========================================================================
// Group 4: Order Management & Dispatch (orders.php)
// =========================================================================
echo "\nGroup 4: Order Management & Dispatch (orders.php)\n";

it('orders.php renders order table, status filter tabs, active riders, and dispatch modals', function () use ($testAdmin) {
    reset_admin_env();
    login_user($testAdmin);

    $html = render_admin_page(__DIR__ . '/../pages/admin/orders.php');

    assert_contains('Order Management & Dispatch', $html);
    assert_contains('admin-order-filter-btn', $html);
    assert_contains('All Orders', $html);
    assert_contains('Pending', $html);
    assert_contains('Approved', $html);
    assert_contains('Ready', $html);
    assert_contains('Picked Up', $html);
    assert_contains('Out for Delivery', $html);
    assert_contains('Delivered', $html);
    assert_contains('Cancelled', $html);
    assert_contains('assignRiderModal', $html);
    assert_contains('adminCancelOrderModal', $html);
    assert_contains('orderDetailsModal', $html);
});

it('orders.php blocks order mutations when CSRF token is missing or invalid', function () use ($testAdmin) {
    reset_admin_env();
    login_user($testAdmin);

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = 'invalid_admin_csrf_token';
    $_POST['action'] = 'approve_order';
    $_POST['order_id'] = 1001;

    render_admin_page(__DIR__ . '/../pages/admin/orders.php');

    assert_true(has_flash());
    $flash = get_flash();
    assert_equals('error', $flash['type']);
    assert_contains('invalid or expired', $flash['message']);
});

it('orders.php approves a pending order (pending -> approved)', function () use ($db, $testAdmin, $orderModel, $productModel, $customerId) {
    reset_admin_env();
    login_user($testAdmin);
    $token = csrf_token();

    // Create a pending test order
    $products = $productModel->getActive();
    $prod = $products[0];

    $orderId = $orderModel->place([
        'customer_id'      => $customerId,
        'product_id'       => $prod['id'],
        'quantity'         => 1,
        'payment_method'   => 'cod',
        'delivery_address' => 'Test Approve Address',
        'contact_phone'    => '09171234567',
        'status'           => 'pending'
    ]);

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $token;
    $_POST['action'] = 'approve_order';
    $_POST['order_id'] = $orderId;

    render_admin_page(__DIR__ . '/../pages/admin/orders.php');

    assert_equals(url('/pages/admin/orders.php'), $GLOBALS['LAST_REDIRECT']);
    assert_true(has_flash());
    $flash = get_flash();
    assert_equals('success', $flash['type']);
    assert_contains('approved', $flash['message']);

    $updatedOrder = $orderModel->findById($orderId);
    assert_equals('approved', $updatedOrder['status']);
});

it('orders.php assigns active rider and transitions status (approved -> picked_up)', function () use ($db, $testAdmin, $orderModel, $riderId) {
    reset_admin_env();
    login_user($testAdmin);
    $token = csrf_token();

    // Find or create an approved order
    $allOrders = $orderModel->getAll('approved');
    assert_not_empty($allOrders);
    $targetOrder = $allOrders[0];
    $orderId = (int)$targetOrder['id'];

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $token;
    $_POST['action'] = 'assign_rider';
    $_POST['order_id'] = $orderId;
    $_POST['rider_id'] = $riderId;
    $_POST['status'] = 'picked_up';

    render_admin_page(__DIR__ . '/../pages/admin/orders.php');

    assert_equals(url('/pages/admin/orders.php'), $GLOBALS['LAST_REDIRECT']);
    assert_true(has_flash());
    $flash = get_flash();
    assert_equals('success', $flash['type']);
    assert_contains('assigned', $flash['message']);

    $updatedOrder = $orderModel->findById($orderId);
    assert_equals($riderId, (int)$updatedOrder['rider_id']);
    assert_equals('picked_up', $updatedOrder['status']);
});

it('orders.php updates status through valid state transitions and delivers order', function () use ($db, $testAdmin, $orderModel) {
    reset_admin_env();
    login_user($testAdmin);
    $token = csrf_token();

    // Find order in picked_up
    $pickedOrders = $orderModel->getAll('picked_up');
    assert_not_empty($pickedOrders);
    $orderId = (int)$pickedOrders[0]['id'];

    // 1. Move picked_up -> out_for_delivery
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $token;
    $_POST['action'] = 'update_status';
    $_POST['order_id'] = $orderId;
    $_POST['status'] = 'out_for_delivery';

    render_admin_page(__DIR__ . '/../pages/admin/orders.php');
    $orderOut = $orderModel->findById($orderId);
    assert_equals('out_for_delivery', $orderOut['status']);

    // 2. Move out_for_delivery -> delivered
    reset_admin_env();
    login_user($testAdmin);
    $token = csrf_token();

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $token;
    $_POST['action'] = 'update_status';
    $_POST['order_id'] = $orderId;
    $_POST['status'] = 'delivered';

    render_admin_page(__DIR__ . '/../pages/admin/orders.php');
    $orderDelivered = $orderModel->findById($orderId);
    assert_equals('delivered', $orderDelivered['status']);
    assert_not_empty($orderDelivered['delivered_at']);
});

it('orders.php cancels order, restocks inventory, and appends cancellation reason', function () use ($db, $testAdmin, $orderModel, $productModel, $customerId) {
    reset_admin_env();
    login_user($testAdmin);
    $token = csrf_token();

    $products = $productModel->getActive();
    $prod = $products[0];
    $initialStock = (int)$prod['stock'];

    $orderId = $orderModel->place([
        'customer_id'      => $customerId,
        'product_id'       => $prod['id'],
        'quantity'         => 4,
        'payment_method'   => 'cod',
        'delivery_address' => 'Cancel Order St',
        'contact_phone'    => '09171234567',
        'status'           => 'pending'
    ]);

    // Stock decremented by 4
    $afterPlace = $productModel->findById($prod['id']);
    assert_equals($initialStock - 4, (int)$afterPlace['stock']);

    // Cancel order
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $token;
    $_POST['action'] = 'cancel_order';
    $_POST['order_id'] = $orderId;
    $_POST['reason'] = 'Admin test cancellation';

    render_admin_page(__DIR__ . '/../pages/admin/orders.php');

    assert_true(has_flash());
    $flash = get_flash();
    assert_equals('success', $flash['type']);

    $cancelledOrder = $orderModel->findById($orderId);
    assert_equals('cancelled', $cancelledOrder['status']);
    assert_contains('Admin test cancellation', $cancelledOrder['notes']);

    // Verify stock restored
    $restoredProd = $productModel->findById($prod['id']);
    assert_equals($initialStock, (int)$restoredProd['stock']);
});

// =========================================================================
// Group 5: Inventory Management (inventory.php)
// =========================================================================
echo "\nGroup 5: Inventory Management (inventory.php)\n";

it('inventory.php renders product list, stock indicators, and management modals', function () use ($testAdmin) {
    reset_admin_env();
    login_user($testAdmin);

    $html = render_admin_page(__DIR__ . '/../pages/admin/inventory.php');

    assert_contains('Product Catalog & Inventory', $html);
    assert_contains('Total Catalog', $html);
    assert_contains('Total Units', $html);
    assert_contains('Low Stock', $html);
    assert_contains('addProductModal', $html);
    assert_contains('editStockPriceModal', $html);
    assert_contains('editProductModal', $html);
});

it('inventory.php creates a new LPG product successfully', function () use ($db, $testAdmin, $productModel) {
    reset_admin_env();
    login_user($testAdmin);
    $token = csrf_token();

    $uniqueName = 'Phoenix Super LPG ' . bin2hex(random_bytes(3));
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $token;
    $_POST['action'] = 'create_product';
    $_POST['name'] = $uniqueName;
    $_POST['brand'] = 'Phoenix';
    $_POST['weight'] = '11kg';
    $_POST['price'] = 860.00;
    $_POST['stock'] = 30;
    $_POST['image_url'] = 'assets/img/products/phoenix-11kg.png';
    $_POST['status'] = 'active';

    render_admin_page(__DIR__ . '/../pages/admin/inventory.php');

    assert_equals(url('/pages/admin/inventory.php'), $GLOBALS['LAST_REDIRECT']);
    assert_true(has_flash());
    $flash = get_flash();
    assert_equals('success', $flash['type']);

    // Verify product exists in database
    $all = $productModel->getAll();
    $created = null;
    foreach ($all as $p) {
        if ($p['name'] === $uniqueName) {
            $created = $p;
            break;
        }
    }
    assert_not_empty($created);
    assert_equals('Phoenix', $created['brand']);
    assert_equals(860.00, (float)$created['price']);
    assert_equals(30, (int)$created['stock']);
    assert_equals('active', $created['status']);
});

it('inventory.php stores an uploaded product image on update and removes the replaced file', function () use ($db, $testAdmin, $productModel) {
    reset_admin_env();
    login_user($testAdmin);
    $token = csrf_token();

    // Create a dedicated product with an existing uploaded image to replace.
    $uniqueName = 'Upload Test ' . bin2hex(random_bytes(3));
    $oldRel = 'uploads/products/product_' . bin2hex(random_bytes(8)) . '.png';
    $oldAbs = dirname(__DIR__) . '/' . $oldRel;
    @mkdir(dirname($oldAbs), 0755, true);
    file_put_contents($oldAbs, 'old');

    $pId = $productModel->create([
        'name' => $uniqueName,
        'brand' => 'Brand',
        'weight' => '11kg',
        'price' => 500.00,
        'stock' => 10,
        'image_url' => $oldRel,
        'status' => 'active'
    ]);

    // Simulate a new PNG upload.
    $pngData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    $tmpFile = tempnam(sys_get_temp_dir(), 'lpg_prod_');
    file_put_contents($tmpFile, $pngData);

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $token;
    $_POST['action'] = 'update_product';
    $_POST['product_id'] = $pId;
    $_POST['name'] = $uniqueName;
    $_POST['brand'] = 'Brand';
    $_POST['weight'] = '11kg';
    $_POST['price'] = 500.00;
    $_POST['stock'] = 10;
    $_POST['status'] = 'active';
    $_POST['image_url'] = $oldRel;
    $_FILES['image_file'] = [
        'name' => 'product.png',
        'type' => 'image/png',
        'tmp_name' => $tmpFile,
        'error' => UPLOAD_ERR_OK,
        'size' => strlen($pngData)
    ];

    render_admin_page(__DIR__ . '/../pages/admin/inventory.php');

    assert_true(has_flash());
    $flash = get_flash();
    assert_equals('success', $flash['type']);

    $updated = $productModel->findById($pId);
    assert_true(str_starts_with((string)$updated['image_url'], 'uploads/products/'), 'image_url must point to uploads/products/');
    assert_true($updated['image_url'] !== $oldRel, 'image_url must be replaced');
    assert_true(is_file(dirname(__DIR__) . '/' . $updated['image_url']), 'new uploaded file must exist on disk');
    assert_true(!is_file($oldAbs), 'previous uploaded file must be deleted');

    // Cleanup
    @unlink(dirname(__DIR__) . '/' . $updated['image_url']);
    @unlink($oldAbs);
    @unlink($tmpFile);
    $db->prepare("DELETE FROM products WHERE id = ?")->execute([$pId]);
});

it('inventory.php rejects a non-image upload for product images', function () use ($db, $testAdmin, $productModel) {
    reset_admin_env();
    login_user($testAdmin);
    $token = csrf_token();

    $txtFile = tempnam(sys_get_temp_dir(), 'lpg_txt_');
    file_put_contents($txtFile, 'this is definitely not an image');

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $token;
    $_POST['action'] = 'create_product';
    $_POST['name'] = 'Bad Image ' . bin2hex(random_bytes(3));
    $_POST['brand'] = 'Brand';
    $_POST['weight'] = '11kg';
    $_POST['price'] = 500.00;
    $_POST['stock'] = 10;
    $_POST['status'] = 'active';
    $_FILES['image_file'] = [
        'name' => 'malicious.txt',
        'type' => 'text/plain',
        'tmp_name' => $txtFile,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($txtFile)
    ];

    render_admin_page(__DIR__ . '/../pages/admin/inventory.php');

    assert_true(has_flash());
    $flash = get_flash();
    assert_equals('error', $flash['type']);

    // No product should have been created.
    $found = null;
    foreach ($productModel->getAll() as $p) {
        if ($p['name'] === $_POST['name']) {
            $found = $p;
            break;
        }
    }
    assert_true($found === null, 'product must not be created when the image is invalid');

    @unlink($txtFile);
});

it('inventory.php adjusts stock count and unit price via quick modal', function () use ($db, $testAdmin, $productModel) {
    reset_admin_env();
    login_user($testAdmin);
    $token = csrf_token();

    $products = $productModel->getAll();
    $target = $products[0];
    $pId = (int)$target['id'];

    $newStock = 75;
    $newPrice = 920.50;

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $token;
    $_POST['action'] = 'update_stock_price';
    $_POST['product_id'] = $pId;
    $_POST['stock'] = $newStock;
    $_POST['price'] = $newPrice;

    render_admin_page(__DIR__ . '/../pages/admin/inventory.php');

    assert_true(has_flash());
    $flash = get_flash();
    assert_equals('success', $flash['type']);

    $updated = $productModel->findById($pId);
    assert_equals($newStock, (int)$updated['stock']);
    assert_equals($newPrice, (float)$updated['price']);
});

it('inventory.php toggles product status between active and inactive', function () use ($db, $testAdmin, $productModel) {
    reset_admin_env();
    login_user($testAdmin);
    $token = csrf_token();

    $products = $productModel->getAll();
    $target = $products[0];
    $pId = (int)$target['id'];
    $initialStatus = $target['status'];

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $token;
    $_POST['action'] = 'toggle_status';
    $_POST['product_id'] = $pId;

    render_admin_page(__DIR__ . '/../pages/admin/inventory.php');

    $toggled = $productModel->findById($pId);
    $expectedStatus = ($initialStatus === 'active') ? 'inactive' : 'active';
    assert_equals($expectedStatus, $toggled['status']);

    // Toggle back
    reset_admin_env();
    login_user($testAdmin);
    $token = csrf_token();

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $token;
    $_POST['action'] = 'toggle_status';
    $_POST['product_id'] = $pId;

    render_admin_page(__DIR__ . '/../pages/admin/inventory.php');
    $reverted = $productModel->findById($pId);
    assert_equals($initialStatus, $reverted['status']);
});

// =========================================================================
// Group 6: User Management & Secure ID Proxy (users.php & view_id.php)
// =========================================================================
echo "\nGroup 6: User Management & Secure ID Proxy (users.php & view_id.php)\n";

it('users.php renders user management table, role filters, and add user modal', function () use ($testAdmin) {
    reset_admin_env();
    login_user($testAdmin);

    $html = render_admin_page(__DIR__ . '/../pages/admin/users.php');

    assert_contains('User Account Management', $html);
    assert_contains('Total Accounts', $html);
    assert_contains('Customers', $html);
    assert_contains('Delivery Riders', $html);
    assert_contains('admin-user-filter-btn', $html);
    assert_contains('addUserModal', $html);
    assert_contains('editUserModal', $html);
    assert_contains('userVerificationFilterSelect', $html);
    assert_contains('ID Verification', $html);
});

it('users.php creates a new user account (admin, rider, customer)', function () use ($db, $testAdmin, $userModel) {
    reset_admin_env();
    login_user($testAdmin);
    $token = csrf_token();

    $newEmail = 'newrider_' . bin2hex(random_bytes(3)) . '@lpg.com';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $token;
    $_POST['action'] = 'create_user';
    $_POST['full_name'] = 'Rider Rodrigo';
    $_POST['email'] = $newEmail;
    $_POST['password'] = 'RiderPass@2026';
    $_POST['role'] = 'rider';
    $_POST['phone'] = '09291114455';
    $_POST['address'] = '789 Rizal Ave, Manila';
    $_POST['status'] = 'active';

    render_admin_page(__DIR__ . '/../pages/admin/users.php');

    assert_true(has_flash());
    $flash = get_flash();
    assert_equals('success', $flash['type']);

    $createdRider = $userModel->findByEmail($newEmail);
    assert_not_empty($createdRider);
    assert_equals('rider', $createdRider['role']);
    assert_equals('Rider Rodrigo', $createdRider['full_name']);
    assert_equals('09291114455', $createdRider['phone']);

    // Clean up
    $db->prepare("DELETE FROM users WHERE id = ?")->execute([$createdRider['id']]);
});

it('users.php updates user status (active -> suspended -> active)', function () use ($db, $testAdmin, $userModel, $customerId) {
    reset_admin_env();
    login_user($testAdmin);
    $token = csrf_token();

    // 1. Suspend customer
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $token;
    $_POST['action'] = 'update_status';
    $_POST['user_id'] = $customerId;
    $_POST['status'] = 'suspended';

    render_admin_page(__DIR__ . '/../pages/admin/users.php');

    assert_true(has_flash());
    $flash = get_flash();
    assert_equals('success', $flash['type']);

    $suspendedUser = $userModel->findById($customerId);
    assert_equals('suspended', $suspendedUser['status']);

    // 2. Reactivate customer
    reset_admin_env();
    login_user($testAdmin);
    $token = csrf_token();

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $token;
    $_POST['action'] = 'update_status';
    $_POST['user_id'] = $customerId;
    $_POST['status'] = 'active';

    render_admin_page(__DIR__ . '/../pages/admin/users.php');

    $activeUser = $userModel->findById($customerId);
    assert_equals('active', $activeUser['status']);
});

it('users.php enforces self-lockout guard preventing admin from suspending their own active account', function () use ($testAdmin, $adminId) {
    reset_admin_env();
    login_user($testAdmin);
    $token = csrf_token();

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $token;
    $_POST['action'] = 'update_status';
    $_POST['user_id'] = $adminId; // Admin's own ID
    $_POST['status'] = 'suspended';

    render_admin_page(__DIR__ . '/../pages/admin/users.php');

    assert_true(has_flash());
    $flash = get_flash();
    assert_equals('error', $flash['type']);
    assert_contains('cannot suspend or deactivate your own', $flash['message']);
});

it('users.php approves a pending customer government ID (approve_id)', function () use ($db, $testAdmin, $userModel) {
    reset_admin_env();
    login_user($testAdmin);

    $tempId = $userModel->create([
        'full_name' => 'Approve Candidate',
        'email' => 'approve_' . bin2hex(random_bytes(3)) . '@test.com',
        'password' => 'Password@123!',
        'role' => 'customer',
        'phone' => '09170006677',
        'address' => 'Pending address',
        'valid_id_path' => 'uploads/ids/approve_test.png',
        'status' => 'active',
        'id_verification_status' => 'pending'
    ]);

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = csrf_token();
    $_POST['action'] = 'approve_id';
    $_POST['user_id'] = $tempId;

    render_admin_page(__DIR__ . '/../pages/admin/users.php');

    assert_true(has_flash());
    $flash = get_flash();
    assert_equals('success', $flash['type']);

    $fresh = $userModel->findById($tempId);
    assert_equals('approved', $fresh['id_verification_status']);

    $db->prepare("DELETE FROM users WHERE id = ?")->execute([$tempId]);
});

it('users.php rejects a pending ID and requires a reason (reject_id)', function () use ($db, $testAdmin, $userModel) {
    reset_admin_env();
    login_user($testAdmin);

    $tempId = $userModel->create([
        'full_name' => 'Reject Candidate',
        'email' => 'reject_' . bin2hex(random_bytes(3)) . '@test.com',
        'password' => 'Password@123!',
        'role' => 'customer',
        'phone' => '09170007788',
        'address' => 'Pending address',
        'valid_id_path' => 'uploads/ids/reject_test.png',
        'status' => 'active',
        'id_verification_status' => 'pending'
    ]);

    // Missing reason -> error flash, status unchanged
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = csrf_token();
    $_POST['action'] = 'reject_id';
    $_POST['user_id'] = $tempId;
    $_POST['reason'] = '';

    render_admin_page(__DIR__ . '/../pages/admin/users.php');

    assert_true(has_flash());
    $flash = get_flash();
    assert_equals('error', $flash['type']);

    $stillPending = $userModel->findById($tempId);
    assert_equals('pending', $stillPending['id_verification_status']);

    // With reason -> rejected
    reset_admin_env();
    login_user($testAdmin);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = csrf_token();
    $_POST['action'] = 'reject_id';
    $_POST['user_id'] = $tempId;
    $_POST['reason'] = 'Blurred photo';

    render_admin_page(__DIR__ . '/../pages/admin/users.php');

    assert_true(has_flash());
    $flash = get_flash();
    assert_equals('success', $flash['type']);

    $fresh = $userModel->findById($tempId);
    assert_equals('rejected', $fresh['id_verification_status']);
    assert_equals('Blurred photo', $fresh['id_rejection_reason']);

    $db->prepare("DELETE FROM users WHERE id = ?")->execute([$tempId]);
});

it('users.php renders the pending ID verification alert when applicants await review', function () use ($db, $testAdmin, $userModel) {
    reset_admin_env();
    login_user($testAdmin);

    $tempId = $userModel->create([
        'full_name' => 'Alert Candidate',
        'email' => 'alert_' . bin2hex(random_bytes(3)) . '@test.com',
        'password' => 'Password@123!',
        'role' => 'customer',
        'phone' => '09170008899',
        'address' => 'Pending address',
        'valid_id_path' => 'uploads/ids/alert_test.png',
        'status' => 'active',
        'id_verification_status' => 'pending'
    ]);

    $html = render_admin_page(__DIR__ . '/../pages/admin/users.php');
    assert_contains('awaiting verification', $html);
    assert_contains('verification=pending', $html);

    $db->prepare("DELETE FROM users WHERE id = ?")->execute([$tempId]);
});

it('view_id.php securely proxies valid ID documents and prevents path traversal', function () use ($db, $testAdmin, $userModel) {
    reset_admin_env();
    login_user($testAdmin);

    // 1. Create a dummy upload file inside uploads/ids
    $uploadDir = dirname(__DIR__) . '/uploads/ids';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $dummyIdFile = 'id_test_' . bin2hex(random_bytes(6)) . '.png';
    $dummyFullPath = $uploadDir . '/' . $dummyIdFile;
    $pngData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    file_put_contents($dummyFullPath, $pngData);

    // Create temporary user with valid_id_path
    $tempEmail = 'iduser_' . bin2hex(random_bytes(3)) . '@test.com';
    $tempUserId = $userModel->create([
        'full_name'     => 'ID Verification User',
        'email'         => $tempEmail,
        'password'      => 'Password@123!',
        'role'          => 'customer',
        'phone'         => '09170001122',
        'address'       => 'Some test address',
        'valid_id_path' => 'uploads/ids/' . $dummyIdFile,
        'status'        => 'active'
    ]);

    // Access view_id.php
    $_GET['user_id'] = $tempUserId;
    render_admin_page(__DIR__ . '/../pages/admin/view_id.php');

    assert_equals(200, $GLOBALS['LAST_HTTP_CODE']);
    assert_not_empty($GLOBALS['LAST_SERVED_FILE']);
    assert_equals('image/png', $GLOBALS['LAST_SERVED_FILE']['mime_type']);

    // 2. Directory traversal attempt test
    reset_admin_env();
    login_user($testAdmin);

    // Set malicious traversal path in DB
    $db->prepare("UPDATE users SET valid_id_path = ? WHERE id = ?")->execute(['../../config/database.php', $tempUserId]);
    $_GET['user_id'] = $tempUserId;
    render_admin_page(__DIR__ . '/../pages/admin/view_id.php');

    // Should return 404 unauthorized path
    assert_equals(404, $GLOBALS['LAST_HTTP_CODE']);

    // Clean up
    @unlink($dummyFullPath);
    $db->prepare("DELETE FROM users WHERE id = ?")->execute([$tempUserId]);
});

// =========================================================================
// Group 7: Security & XSS Mitigation
// =========================================================================
echo "\nGroup 7: Security & XSS Mitigation\n";

it('Admin portal pages escape malicious XSS payloads across orders, inventory, and users', function () use ($db, $testAdmin, $userModel, $productModel, $orderModel) {
    reset_admin_env();
    login_user($testAdmin);

    // Create user with XSS
    $xssEmail = 'xssadmin_' . bin2hex(random_bytes(3)) . '@test.com';
    $xssUserId = $userModel->create([
        'full_name' => '<script>alert("admin_xss_name")</script>',
        'email'     => $xssEmail,
        'password'  => 'Password@123!',
        'role'      => 'customer',
        'phone'     => '09178887766',
        'address'   => '<img src=x onerror=alert("admin_xss_addr")>',
        'status'    => 'active'
    ]);

    // Create product with XSS
    $xssProdName = '<script>alert("xss_prod")</script>';
    $xssProdId = $productModel->create([
        'name'      => $xssProdName,
        'brand'     => '<b>XSS Brand</b>',
        'weight'    => '11kg',
        'price'     => 899.00,
        'stock'     => 10,
        'status'    => 'active'
    ]);

    // Create order with XSS notes
    $xssOrderId = $orderModel->place([
        'customer_id'      => $xssUserId,
        'product_id'       => $xssProdId,
        'quantity'         => 1,
        'payment_method'   => 'cod',
        'delivery_address' => '<img src=x onerror=alert("admin_xss_addr")>',
        'contact_phone'    => '09178887766',
        'notes'            => '<script>alert("xss_order_notes")</script>',
        'status'           => 'pending'
    ]);

    // 1. Check Dashboard
    $dashHtml = render_admin_page(__DIR__ . '/../pages/admin/dashboard.php');
    assert_not_contains('<script>alert("admin_xss_name")</script>', $dashHtml);
    assert_not_contains('<script>alert("xss_prod")</script>', $dashHtml);

    // 2. Check Orders
    $ordersHtml = render_admin_page(__DIR__ . '/../pages/admin/orders.php');
    assert_not_contains('<script>alert("admin_xss_name")</script>', $ordersHtml);
    assert_not_contains('<script>alert("xss_order_notes")</script>', $ordersHtml);

    // 3. Check Inventory
    $invHtml = render_admin_page(__DIR__ . '/../pages/admin/inventory.php');
    assert_not_contains('<script>alert("xss_prod")</script>', $invHtml);
    assert_not_contains('<b>XSS Brand</b>', $invHtml);

    // 4. Check Users
    $usersHtml = render_admin_page(__DIR__ . '/../pages/admin/users.php');
    assert_not_contains('<script>alert("admin_xss_name")</script>', $usersHtml);
    assert_not_contains('<img src=x onerror=alert("admin_xss_addr")>', $usersHtml);

    // Clean up
    $db->prepare("DELETE FROM orders WHERE id = ?")->execute([$xssOrderId]);
    $db->prepare("DELETE FROM products WHERE id = ?")->execute([$xssProdId]);
    $db->prepare("DELETE FROM users WHERE id = ?")->execute([$xssUserId]);
});

// =========================================================================
// Test Summary
// =========================================================================
echo "\n====================================================\n";
echo " Test Summary: {$passCount} Passed, {$failCount} Failed (Total: {$testCount})\n";
echo "====================================================\n";

if ($failCount > 0) {
    exit(1);
} else {
    exit(0);
}
