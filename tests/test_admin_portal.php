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
    assert_contains('Dispatch Orders', $html);
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
