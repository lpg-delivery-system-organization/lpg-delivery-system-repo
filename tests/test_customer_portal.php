<?php
/**
 * Customer Portal Automated Integration Test Suite
 * LPG Delivery System v2
 *
 * Tests:
 * 1. Syntax & Linting Checks
 * 2. Customer Role Authorization & Route Guards
 * 3. Dashboard Workflows, Aggregations & Recent Orders
 * 4. Shop Catalog & Concurrency-Safe Order Placement
 * 5. Order History Listing, Tracking & Order Cancellation
 * 6. Profile Management, Phone Validation & Password Updates
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
require_once __DIR__ . '/../classes/Cart.php';
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
function reset_customer_env(): void {
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
    unset($GLOBALS['LAST_HTTP_CODE'], $GLOBALS['LAST_REDIRECT'], $GLOBALS['LAST_RESPONSE']);
}

// Render page buffer in test mode
function render_page(string $pagePath): string {
    ob_start();
    try {
        include $pagePath;
    } catch (AuthException $e) {
        // Expected during test mode redirects
    }
    return ob_get_clean();
}

echo "====================================================\n";
echo " LPG Delivery System v2 - Customer Portal Test Suite\n";
echo "====================================================\n\n";

$db = Database::connect();
$userModel = new User($db);
$productModel = new Product($db);
$orderModel = new Order($db);

// Retrieve or create standard test customer
$testCustomer = $userModel->findByEmail('customer@lpg.com');
if (!$testCustomer) {
    $custPass = password_hash('password', PASSWORD_BCRYPT, ['cost' => 12]);
    $stmt = $db->prepare("
        INSERT INTO users (full_name, email, password, role, phone, address, status, created_at, updated_at)
        VALUES ('Janister Singson', 'customer@lpg.com', ?, 'customer', '09171234567', 'Block 5 Lot 12, Mahogany St, Quezon City', 'active', NOW(), NOW())
    ");
    $stmt->execute([$custPass]);
    $testCustomer = $userModel->findByEmail('customer@lpg.com');
}

$customerId = (int)$testCustomer['id'];

// Guarantee a clean cart: the DB cart takes precedence over a POSTed
// product_id, so leftover rows from another run would corrupt the shop tests.
(new Cart($db))->clear($customerId);

// =========================================================================
// Group 1: Syntax & File Integrity
// =========================================================================
echo "Group 1: Syntax & File Integrity\n";

it('dashboard.php passes syntax linting', function () {
    exec('php -l ' . escapeshellarg(__DIR__ . '/../pages/customer/dashboard.php') . ' 2>&1', $output, $code);
    assert_equals(0, $code, 'dashboard.php syntax error: ' . implode("\n", $output));
});

it('shop.php passes syntax linting', function () {
    exec('php -l ' . escapeshellarg(__DIR__ . '/../pages/customer/shop.php') . ' 2>&1', $output, $code);
    assert_equals(0, $code, 'shop.php syntax error: ' . implode("\n", $output));
});

it('orders.php passes syntax linting', function () {
    exec('php -l ' . escapeshellarg(__DIR__ . '/../pages/customer/orders.php') . ' 2>&1', $output, $code);
    assert_equals(0, $code, 'orders.php syntax error: ' . implode("\n", $output));
});

it('profile.php passes syntax linting', function () {
    exec('php -l ' . escapeshellarg(__DIR__ . '/../pages/customer/profile.php') . ' 2>&1', $output, $code);
    assert_equals(0, $code, 'profile.php syntax error: ' . implode("\n", $output));
});

it('customer.js exists and contains core client interaction handlers', function () {
    $jsPath = __DIR__ . '/../assets/js/customer.js';
    assert_true(file_exists($jsPath), 'customer.js file must exist');
    $content = file_get_contents($jsPath);
    assert_contains('btn-add-to-cart', $content);
    assert_contains('data-cart-action', $content);
    assert_contains('initShopCart', $content);
    assert_contains('cartDrawer', $content);
    assert_contains('order-filter-btn', $content);
    assert_contains('cancelOrderModal', $content);
});

// =========================================================================
// Group 2: Role Authorization & Route Guards
// =========================================================================
echo "\nGroup 2: Role Authorization & Route Guards\n";

it('Unauthenticated guests are blocked from customer pages and redirected to login', function () {
    reset_customer_env();
    
    // Test require_role('customer') as guest
    try {
        require_role('customer');
        assert_true(false, 'Should have thrown AuthException');
    } catch (AuthException $e) {
        assert_equals(302, $e->getStatusCode());
        assert_equals(url('/index.php'), $e->getRedirectUrl());
    }
});

it('Admin users accessing customer pages are redirected to admin dashboard', function () {
    reset_customer_env();
    login_user(['id' => 1, 'email' => 'admin@lpg.com', 'role' => 'admin', 'full_name' => 'Admin User']);

    try {
        require_role('customer');
        assert_true(false, 'Admin should not pass customer role check');
    } catch (AuthException $e) {
        assert_equals(403, $e->getStatusCode());
        assert_equals(url('/pages/admin/dashboard.php'), $e->getRedirectUrl());
    }
});

it('Rider users accessing customer pages are redirected to rider deliveries', function () {
    reset_customer_env();
    login_user(['id' => 2, 'email' => 'rider@lpg.com', 'role' => 'rider', 'full_name' => 'Rider Ken']);

    try {
        require_role('customer');
        assert_true(false, 'Rider should not pass customer role check');
    } catch (AuthException $e) {
        assert_equals(403, $e->getStatusCode());
        assert_equals(url('/pages/rider/deliveries.php'), $e->getRedirectUrl());
    }
});

it('Authenticated customers are granted full access to customer pages', function () use ($testCustomer) {
    reset_customer_env();
    login_user($testCustomer);

    // Should execute without throwing AuthException
    require_role('customer');
    assert_true(is_logged_in());
    assert_equals('customer', current_user_role());
});

// =========================================================================
// Group 3: Customer Dashboard (dashboard.php)
// =========================================================================
echo "\nGroup 3: Customer Dashboard (dashboard.php)\n";

it('dashboard.php renders welcome greeting, metric cards, and quick shop CTA', function () use ($userModel, $customerId) {
    reset_customer_env();
    $cust = $userModel->findById($customerId);
    login_user($cust);

    $html = render_page(__DIR__ . '/../pages/customer/dashboard.php');

    assert_contains('Customer Dashboard', $html);
    assert_contains('welcome-greeting', $html);
    assert_contains($cust['full_name'], $html);
    assert_contains('Active Orders', $html);
    assert_contains('Delivered', $html);
    assert_contains('Total Spent', $html);
    assert_contains('Shop LPG Now', $html);
    assert_contains('Recent Orders', $html);
    assert_contains('Delivery Hours', $html);
});

// =========================================================================
// Group 4: Shop Catalog & Order Placement (shop.php)
// =========================================================================
echo "\nGroup 4: Shop Catalog & Order Placement (shop.php)\n";

it('shop.php renders active product catalog with prices, stock badges, and checkout form', function () use ($testCustomer) {
    reset_customer_env();
    login_user($testCustomer);

    $html = render_page(__DIR__ . '/../pages/customer/shop.php');

    assert_contains('LPG Cylinder Catalog', $html);
    assert_contains('Available Products', $html);
    assert_contains('Order & Delivery Details', $html);
    assert_contains('name="csrf_token"', $html);
    assert_contains('name="quantity"', $html);
    assert_contains('name="payment_method"', $html);
    assert_contains('name="delivery_address"', $html);
    assert_contains('name="contact_phone"', $html);
    assert_contains('btnPlaceOrder', $html);
});

it('shop.php renders the delivery-coverage notice and client-side geofence config', function () use ($testCustomer) {
    reset_customer_env();
    login_user($testCustomer);

    $html = render_page(__DIR__ . '/../pages/customer/shop.php');

    assert_contains('id="deliveryCoverageNotice"', $html);
    assert_contains('window.DeliveryCoverage', $html);
    assert_contains('name="delivery_latitude"', $html);
    assert_contains('name="delivery_longitude"', $html);
    assert_contains('addressPreviewMap', $html);
    assert_contains('radiusMeters', $html, 'Radius must be exposed to the map script');
    assert_contains((string)(int)round(\DeliveryCoverage::radiusMeters()), $html, 'Configured radius must be emitted');
});

it('shop.php rejects an out-of-coverage address when the geofence is enforced', function () use ($db, $testCustomer, $productModel, $customerId) {
    reset_customer_env();
    login_user($testCustomer);
    $token = csrf_token();

    $products = $productModel->getActive();
    assert_not_empty($products);
    $prod = $products[0];

    $GLOBALS['ENFORCE_DELIVERY_COVERAGE'] = true;
    try {
        $before = (int)$db->query("SELECT COUNT(*) FROM orders WHERE customer_id = {$customerId}")->fetchColumn();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token'] = $token;
        $_POST['product_id'] = $prod['id'];
        $_POST['quantity'] = 1;
        $_POST['payment_method'] = 'cod';
        $_POST['delivery_address'] = 'Far away test address';
        $_POST['contact_phone'] = '09171234567';
        $_POST['delivery_latitude'] = '14.5600';
        $_POST['delivery_longitude'] = '121.0800';

        $html = render_page(__DIR__ . '/../pages/customer/shop.php');

        assert_contains('outside our 3 km delivery coverage', $html);

        $after = (int)$db->query("SELECT COUNT(*) FROM orders WHERE customer_id = {$customerId}")->fetchColumn();
        assert_equals($before, $after, 'No order may be created when the location is out of coverage');
    } finally {
        unset($GLOBALS['ENFORCE_DELIVERY_COVERAGE']);
    }
});

it('shop.php blocks order placement when CSRF token is invalid', function () use ($testCustomer, $productModel) {
    reset_customer_env();
    login_user($testCustomer);

    $products = $productModel->getActive();
    assert_not_empty($products);
    $prod = $products[0];

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = 'bad_token_123';
    $_POST['product_id'] = $prod['id'];
    $_POST['quantity'] = 1;
    $_POST['payment_method'] = 'cod';
    $_POST['delivery_address'] = 'Test Delivery Address';
    $_POST['contact_phone'] = '09171234567';

    $html = render_page(__DIR__ . '/../pages/customer/shop.php');
    assert_contains('security token is invalid or expired', $html);
});

it('shop.php validates required fields, phone number format, and payment method', function () use ($testCustomer) {
    reset_customer_env();
    login_user($testCustomer);
    $token = csrf_token();

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $token;
    $_POST['product_id'] = 0; // Invalid
    $_POST['quantity'] = 0;   // Invalid
    $_POST['payment_method'] = 'invalid_payment';
    $_POST['delivery_address'] = '';
    $_POST['contact_phone'] = '12345'; // Bad format

    $html = render_page(__DIR__ . '/../pages/customer/shop.php');
    assert_contains('Your cart is empty', $html);
    assert_contains('Quantity must be at least 1 unit', $html);
    assert_contains('Please enter your complete delivery address', $html);
    assert_contains('11-digit Philippine mobile number', $html);
    assert_contains('Please select a valid payment method', $html);
});

it('shop.php successfully places order (COD), decrements stock atomically, and redirects to order detail', function () use ($db, $testCustomer, $productModel, $orderModel, $customerId) {
    reset_customer_env();
    login_user($testCustomer);
    $token = csrf_token();

    // Get an active product and record initial stock
    $products = $productModel->getActive();
    assert_not_empty($products);
    $targetProduct = $products[0];
    $productId = (int)$targetProduct['id'];
    $initialStock = (int)$targetProduct['stock'];

    if ($initialStock < 5) {
        $productModel->updateStock($productId, 20);
        $initialStock = 20;
    }

    $orderQty = 2;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $token;
    $_POST['product_id'] = $productId;
    $_POST['quantity'] = $orderQty;
    $_POST['payment_method'] = 'cod';
    $_POST['delivery_address'] = '123 Test Ave, Pasig City';
    $_POST['contact_phone'] = '09181234567';
    $_POST['notes'] = 'Handle with care';

    render_page(__DIR__ . '/../pages/customer/shop.php');

    // Verify redirect to order detail (COD flow)
    assert_true(isset($GLOBALS['LAST_REDIRECT']), 'expected a redirect');
    assert_contains('/pages/customer/order-detail.php?id=', $GLOBALS['LAST_REDIRECT']);
    assert_true(has_flash());
    $flash = get_flash();
    assert_equals('success', $flash['type']);
    assert_contains('successfully placed', $flash['message']);

    // Verify product stock decremented
    $freshProduct = $productModel->findById($productId);
    assert_equals($initialStock - $orderQty, (int)$freshProduct['stock']);

    // Verify order record in DB
    $customerOrders = $orderModel->getByCustomer($customerId);
    assert_not_empty($customerOrders);
    $latestOrder = $customerOrders[0];
    assert_equals($productId, (int)$latestOrder['product_id']);
    assert_equals($orderQty, (int)$latestOrder['quantity']);
    assert_equals('cod', $latestOrder['payment_method']);
    assert_equals('pending', $latestOrder['status']);
    assert_equals('123 Test Ave, Pasig City', $latestOrder['delivery_address']);
    assert_equals('09181234567', $latestOrder['contact_phone']);
    assert_equals((float)$targetProduct['price'], (float)$latestOrder['unit_price']);
    assert_equals(round((float)$targetProduct['price'] * $orderQty, 2), (float)$latestOrder['total_amount']);
});

it('Order::place with status pending_payment does NOT decrement stock, and confirmPayment reserves it atomically', function () use ($db, $testCustomer, $productModel, $orderModel, $customerId) {
    reset_customer_env();

    // Get an active product, ensure enough stock, record initial
    $products = $productModel->getActive();
    assert_not_empty($products);
    $targetProduct = $products[0];
    $productId = (int)$targetProduct['id'];
    if ((int)$targetProduct['stock'] < 3) {
        $productModel->updateStock($productId, 10);
    }
    $initialStock = (int)$productModel->findById($productId)['stock'];
    $orderQty = 2;

    // Place an online order awaiting payment: stock must NOT be touched yet.
    $newOrderId = $orderModel->place([
        'customer_id'      => $customerId,
        'product_id'       => $productId,
        'quantity'         => $orderQty,
        'payment_method'   => 'gcash',
        'delivery_address' => '123 Test Ave, Pasig City',
        'contact_phone'    => '09181234567',
        'delivery_latitude'  => '14.5600',
        'delivery_longitude' => '121.0800',
        'notes'            => '',
        'status'           => 'pending_payment'
    ]);
    assert_true($newOrderId > 0, 'order should be created');

    // Stock unchanged while awaiting payment.
    assert_equals($initialStock, (int)$productModel->findById($productId)['stock']);
    $placed = $orderModel->findById($newOrderId);
    assert_equals('pending_payment', $placed['status']);
    assert_equals('unpaid', $placed['payment_status']);

    // Simulate the PayMongo webhook confirming payment.
    $confirmed = $orderModel->confirmPayment($newOrderId);
    assert_true($confirmed, 'confirmPayment should succeed');

    // Stock now reserved and order moved into the normal pending queue as paid.
    assert_equals($initialStock - $orderQty, (int)$productModel->findById($productId)['stock']);
    $paid = $orderModel->findById($newOrderId);
    assert_equals('pending', $paid['status']);
    assert_equals('paid', $paid['payment_status']);
    assert_true(!empty($paid['paid_at']), 'paid_at should be recorded');
});

it('shop.php rejects order when requested quantity exceeds available stock', function () use ($db, $testCustomer, $productModel) {
    reset_customer_env();
    login_user($testCustomer);
    $token = csrf_token();

    $products = $productModel->getActive();
    $prod = $products[0];
    $stock = (int)$prod['stock'];

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $token;
    $_POST['product_id'] = $prod['id'];
    $_POST['quantity'] = $stock + 10; // Exceeds stock
    $_POST['payment_method'] = 'cod';
    $_POST['delivery_address'] = '123 Test St';
    $_POST['contact_phone'] = '09171234567';

    $html = render_page(__DIR__ . '/../pages/customer/shop.php');
    assert_contains('exceeds available stock', $html);
});

it('shop.php renders every DB cart line into the drawer and checkout summary', function () use ($db, $testCustomer, $productModel, $customerId) {
    reset_customer_env();
    login_user($testCustomer);

    $cartModel = new Cart($db);
    $cartModel->clear($customerId);

    $products = $productModel->getActive();
    assert_true(count($products) >= 2, 'need at least two active products');
    $a = $products[0];
    $b = $products[1];

    $cartModel->addItem($customerId, (int)$a['id'], 2);
    $cartModel->addItem($customerId, (int)$b['id'], 1);

    $html = render_page(__DIR__ . '/../pages/customer/shop.php');

    assert_contains('window.LPG_CART', $html);
    assert_contains('id="cartDrawer"', $html);
    assert_contains('id="checkoutCartList"', $html);
    assert_contains(htmlspecialchars($a['name'], ENT_QUOTES), $html);
    assert_contains(htmlspecialchars($b['name'], ENT_QUOTES), $html);

    // The rendered cart JSON must carry both lines and 3 units.
    assert_contains('"units":3', preg_replace('/\s+/', '', $html));

    $cartModel->clear($customerId);
});

it('shop.php places a single multi-item order from the DB cart, then empties the cart', function () use ($db, $testCustomer, $productModel, $orderModel, $customerId) {
    reset_customer_env();
    login_user($testCustomer);
    $token = csrf_token();

    $cartModel = new Cart($db);
    $cartModel->clear($customerId);

    $products = $productModel->getActive();
    assert_true(count($products) >= 2, 'need at least two active products');
    $a = $products[0];
    $b = $products[1];

    foreach ([$a, $b] as $p) {
        if ((int)$p['stock'] < 6) {
            $productModel->updateStock((int)$p['id'], 20);
        }
    }
    $stockA = (int)$productModel->findById((int)$a['id'])['stock'];
    $stockB = (int)$productModel->findById((int)$b['id'])['stock'];

    $cartModel->addItem($customerId, (int)$a['id'], 2);
    $cartModel->addItem($customerId, (int)$b['id'], 3);

    // The form posts no product_id: the server must read the DB cart.
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $token;
    $_POST['product_id'] = 0;
    $_POST['quantity'] = 1;
    $_POST['payment_method'] = 'cod';
    $_POST['delivery_address'] = '77 Cart Ave, Pasig City';
    $_POST['contact_phone'] = '09171234567';
    $_POST['notes'] = 'Multi-item cart order';

    render_page(__DIR__ . '/../pages/customer/shop.php');

    assert_true(isset($GLOBALS['LAST_REDIRECT']), 'expected a redirect');
    assert_contains('/pages/customer/order-detail.php?id=', $GLOBALS['LAST_REDIRECT']);

    // Cart is emptied after a successful order.
    assert_equals(0, $cartModel->countUnits($customerId), 'cart must be empty after checkout');

    // Find the newest order and verify the summary + line items.
    $orders = $orderModel->getByCustomer($customerId);
    assert_not_empty($orders);
    $latest = $orders[0];
    $orderId = (int)$latest['id'];

    // Summary reflects the sum of units and total of both lines.
    assert_equals(5, (int)$latest['quantity'], 'summary quantity is total units');
    $expectedTotal = round($a['price'] * 2 + $b['price'] * 3, 2);
    assert_equals($expectedTotal, (float)$latest['total_amount']);

    $items = $orderModel->getItems($orderId);
    assert_equals(2, count($items), 'two order_items rows expected');
    $qtyByProduct = [];
    foreach ($items as $it) {
        $qtyByProduct[(int)$it['product_id']] = (int)$it['quantity'];
    }
    assert_equals(2, $qtyByProduct[(int)$a['id']] ?? 0);
    assert_equals(3, $qtyByProduct[(int)$b['id']] ?? 0);

    // Stock was reserved for both products.
    assert_equals($stockA - 2, (int)$productModel->findById((int)$a['id'])['stock']);
    assert_equals($stockB - 3, (int)$productModel->findById((int)$b['id'])['stock']);
});

// =========================================================================
// Group 5: Order History Listing & Cancellation (orders.php)
// =========================================================================
echo "\nGroup 5: Order History Listing & Cancellation (orders.php)\n";

it('orders.php renders order cards with badges, delivery info, and filter tabs', function () use ($testCustomer) {
    reset_customer_env();
    login_user($testCustomer);

    $html = render_page(__DIR__ . '/../pages/customer/orders.php');

    assert_contains('My Order History', $html);
    assert_contains('order-filter-btn', $html);
    assert_contains('All Orders', $html);
    assert_contains('Pending', $html);
    assert_contains('In Transit', $html);
    assert_contains('Delivered', $html);
    assert_contains('cancelOrderModal', $html);
});

it('orders.php allows customer to cancel a pending order and restores stock', function () use ($db, $testCustomer, $productModel, $orderModel, $customerId) {
    reset_customer_env();
    login_user($testCustomer);
    $token = csrf_token();

    // Create a pending order for testing cancellation
    $products = $productModel->getActive();
    $prod = $products[0];
    $initialStock = (int)$prod['stock'];

    $orderId = $orderModel->place([
        'customer_id'      => $customerId,
        'product_id'       => $prod['id'],
        'quantity'         => 3,
        'payment_method'   => 'cod',
        'delivery_address' => 'Cancel Test St',
        'contact_phone'    => '09171112233',
        'status'           => 'pending'
    ]);

    // Stock should be decremented by 3
    $afterPlace = $productModel->findById($prod['id']);
    assert_equals($initialStock - 3, (int)$afterPlace['stock']);

    // Cancel order via POST
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $token;
    $_POST['action'] = 'cancel_order';
    $_POST['order_id'] = $orderId;

    render_page(__DIR__ . '/../pages/customer/orders.php');

    // Verify redirect and flash success
    assert_equals(url('/pages/customer/orders.php'), $GLOBALS['LAST_REDIRECT']);
    assert_true(has_flash());
    $flash = get_flash();
    assert_equals('success', $flash['type']);
    assert_contains('cancelled', $flash['message']);

    // Verify order status is cancelled in DB
    $cancelledOrder = $orderModel->findById($orderId);
    assert_equals('cancelled', $cancelledOrder['status']);

    // Verify stock is restored
    $restoredProd = $productModel->findById($prod['id']);
    assert_equals($initialStock, (int)$restoredProd['stock']);
});

it('orders.php prevents customer from cancelling orders that are already non-pending or owned by others', function () use ($db, $testCustomer, $orderModel, $productModel, $userModel, $customerId) {
    reset_customer_env();
    login_user($testCustomer);
    $token = csrf_token();

    // 1. Cannot cancel non-pending order (e.g. out_for_delivery)
    $prod = $productModel->getActive()[0];
    $orderId = $orderModel->place([
        'customer_id'      => $customerId,
        'product_id'       => $prod['id'],
        'quantity'         => 1,
        'payment_method'   => 'cod',
        'delivery_address' => 'Test Address',
        'contact_phone'    => '09171112233',
        'status'           => 'pending'
    ]);
    // Move to out_for_delivery
    $orderModel->updateStatus($orderId, 'approved');
    $orderModel->updateStatus($orderId, 'ready_for_delivery');
    $orderModel->updateStatus($orderId, 'picked_up');
    $orderModel->updateStatus($orderId, 'out_for_delivery');

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $token;
    $_POST['action'] = 'cancel_order';
    $_POST['order_id'] = $orderId;

    render_page(__DIR__ . '/../pages/customer/orders.php');

    assert_true(has_flash());
    $flash = get_flash();
    assert_equals('error', $flash['type']);
    assert_contains('cannot be cancelled', $flash['message']);

    // 2. Cannot cancel order belonging to another customer
    reset_customer_env();
    login_user($testCustomer);
    $token = csrf_token();

    // Create a valid second user and place an order
    $otherCustEmail = 'other_cust_' . bin2hex(random_bytes(4)) . '@test.com';
    $otherCustId = $userModel->create([
        'full_name' => 'Other Customer',
        'email' => $otherCustEmail,
        'password' => 'Password@123!',
        'role' => 'customer',
        'phone' => '09170000000',
        'address' => 'Other Address',
        'status' => 'active'
    ]);

    $stmt = $db->prepare("
        INSERT INTO orders (customer_id, product_id, quantity, unit_price, total_amount, payment_method, status, delivery_address, contact_phone, created_at, updated_at)
        VALUES (?, ?, 1, 900.00, 900.00, 'cod', 'pending', 'Other Address', '09170000000', NOW(), NOW())
    ");
    $stmt->execute([$otherCustId, $prod['id']]);
    $otherOrderId = (int)$db->lastInsertId();

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $token;
    $_POST['action'] = 'cancel_order';
    $_POST['order_id'] = $otherOrderId;

    render_page(__DIR__ . '/../pages/customer/orders.php');

    assert_true(has_flash());
    $flash = get_flash();
    assert_equals('error', $flash['type']);
    assert_contains('unauthorized access', $flash['message']);

    // Clean up other order & user
    $db->prepare("DELETE FROM orders WHERE id = ?")->execute([$otherOrderId]);
    $db->prepare("DELETE FROM users WHERE id = ?")->execute([$otherCustId]);
});

// =========================================================================
// Group 6: Profile Management (profile.php)
// =========================================================================
echo "\nGroup 6: Profile Management (profile.php)\n";

it('profile.php renders user details, valid ID status card, and update forms', function () use ($userModel, $customerId) {
    reset_customer_env();
    $cust = $userModel->findById($customerId);
    login_user($cust);

    $html = render_page(__DIR__ . '/../pages/customer/profile.php');

    assert_contains('Customer Profile', $html);
    assert_contains($cust['full_name'], $html);
    assert_contains($cust['email'], $html);
    assert_contains('Edit Profile Information', $html);
    assert_contains('Change Account Password', $html);
    assert_contains('Valid ID Verification', $html);
    assert_contains('name="full_name"', $html);
    assert_contains('name="phone"', $html);
    assert_contains('name="address"', $html);
    assert_contains('name="current_password"', $html);
    assert_contains('name="new_password"', $html);
});

it('profile.php shows the Government ID Verified badge for a verified ID', function () use ($db, $userModel, $customerId) {
    // Capture original verification state so this test is repeatable.
    $original    = $userModel->findById($customerId);
    $origValidId = $original['valid_id_path'] ?? null;
    $origStatus  = (string)($original['id_verification_status'] ?? 'approved');

    try {
        $db->prepare("UPDATE users SET valid_id_path = ?, id_verification_status = 'approved' WHERE id = ?")
           ->execute(['uploads/ids/test_verified_badge.png', $customerId]);

        reset_customer_env();
        login_user($userModel->findById($customerId));
        $html = render_page(__DIR__ . '/../pages/customer/profile.php');

        assert_contains('Government ID Verified', $html);
        assert_not_contains('No Valid ID Uploaded', $html);
    } finally {
        $db->prepare("UPDATE users SET valid_id_path = ?, id_verification_status = ? WHERE id = ?")
           ->execute([$origValidId, $origStatus, $customerId]);
    }

    return true;
});

it('profile.php updates full name, phone number, and delivery address', function () use ($testCustomer, $userModel, $customerId) {
    reset_customer_env();
    login_user($testCustomer);
    $token = csrf_token();

    $newFullName = 'Janister Updated Singson';
    $newPhone = '09228889900';
    $newAddress = 'Unit 201, Emerald Tower, Ortigas Center, Pasig City';

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $token;
    $_POST['action'] = 'update_profile';
    $_POST['full_name'] = $newFullName;
    $_POST['phone'] = $newPhone;
    $_POST['address'] = $newAddress;

    render_page(__DIR__ . '/../pages/customer/profile.php');

    assert_equals(url('/pages/customer/profile.php'), $GLOBALS['LAST_REDIRECT']);
    assert_true(has_flash());
    $flash = get_flash();
    assert_equals('success', $flash['type']);

    // Verify updated DB record
    $freshUser = $userModel->findById($customerId);
    assert_equals($newFullName, $freshUser['full_name']);
    assert_equals($newPhone, $freshUser['phone']);
    assert_equals($newAddress, $freshUser['address']);
    assert_equals($newFullName, $_SESSION['user_name']);
});

it('profile.php rejects invalid phone number format during profile update', function () use ($testCustomer) {
    reset_customer_env();
    login_user($testCustomer);
    $token = csrf_token();

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $token;
    $_POST['action'] = 'update_profile';
    $_POST['full_name'] = 'Janister Singson';
    $_POST['phone'] = '123456'; // Invalid
    $_POST['address'] = 'Some valid address';

    $html = render_page(__DIR__ . '/../pages/customer/profile.php');
    assert_contains('11-digit Philippine mobile number', $html);
});

it('profile.php handles password updates with current password verification and complexity rules', function () use ($testCustomer, $userModel, $customerId) {
    reset_customer_env();
    login_user($testCustomer);
    $token = csrf_token();

    // 1. Wrong current password
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $token;
    $_POST['action'] = 'change_password';
    $_POST['current_password'] = 'WrongPassword999!';
    $_POST['new_password'] = 'NewStrongPass@2026';
    $_POST['confirm_new_password'] = 'NewStrongPass@2026';

    $html = render_page(__DIR__ . '/../pages/customer/profile.php');
    assert_contains('current password you entered is incorrect', $html);

    // 2. Weak new password
    reset_customer_env();
    login_user($testCustomer);
    $token = csrf_token();

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $token;
    $_POST['action'] = 'change_password';
    $_POST['current_password'] = 'password';
    $_POST['new_password'] = 'weak';
    $_POST['confirm_new_password'] = 'weak';

    $htmlWeak = render_page(__DIR__ . '/../pages/customer/profile.php');
    assert_contains('must be at least 8 characters', $htmlWeak);

    // 3. Successful password change
    reset_customer_env();
    login_user($testCustomer);
    $token = csrf_token();

    $newPass = 'NewCustomerSecure@2026!';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $token;
    $_POST['action'] = 'change_password';
    $_POST['current_password'] = 'password';
    $_POST['new_password'] = $newPass;
    $_POST['confirm_new_password'] = $newPass;

    render_page(__DIR__ . '/../pages/customer/profile.php');

    assert_equals(url('/pages/customer/profile.php'), $GLOBALS['LAST_REDIRECT']);
    assert_true(has_flash());
    $flash = get_flash();
    assert_equals('success', $flash['type']);

    // Verify DB password hash was updated
    $updatedUser = $userModel->findById($customerId);
    assert_true(password_verify($newPass, $updatedUser['password']));

    // Restore original password for ongoing test repeatability
    $userModel->updatePassword($customerId, 'password');
});

// =========================================================================
// Group 7: Security & XSS Mitigation
// =========================================================================
echo "\nGroup 7: Security & XSS Mitigation\n";

it('Customer pages escape malicious script tags in customer names and product details', function () use ($db, $userModel) {
    reset_customer_env();

    // Create customer with XSS payload in name & address
    $xssEmail = 'xss_customer_' . bin2hex(random_bytes(4)) . '@test.com';
    $xssUserId = $userModel->create([
        'full_name' => '<script>alert("xss_name")</script>',
        'email'     => $xssEmail,
        'password'  => 'Password@123!',
        'role'      => 'customer',
        'phone'     => '09179998877',
        'address'   => '<b onmouseover="alert(\'xss_addr\')">Malicious Address</b>',
        'status'    => 'active'
    ]);

    $xssUser = $userModel->findById($xssUserId);
    login_user($xssUser);

    $dashHtml = render_page(__DIR__ . '/../pages/customer/dashboard.php');
    assert_not_contains('<script>alert("xss_name")</script>', $dashHtml);
    assert_contains('&lt;script&gt;alert(&quot;xss_name&quot;)&lt;/script&gt;', $dashHtml);
    assert_not_contains('<b onmouseover="alert(\'xss_addr\')">', $dashHtml);

    $profileHtml = render_page(__DIR__ . '/../pages/customer/profile.php');
    assert_not_contains('<script>alert("xss_name")</script>', $profileHtml);
    assert_contains('&lt;script&gt;alert(&quot;xss_name&quot;)&lt;/script&gt;', $profileHtml);

    // Clean up
    $db->prepare("DELETE FROM users WHERE id = ?")->execute([$xssUserId]);
});

// =========================================================================
// Group 8: Rejected ID Deletion-Warning Banner (verification.php)
// =========================================================================
echo "\nGroup 8: Rejected ID Deletion-Warning Banner\n";

it('verification.php shows the 24h deletion deadline to a rejected applicant', function () use ($db, $userModel, $customerId) {
    // Capture original verification state so this test is repeatable.
    $original = $userModel->findById($customerId);
    $origStatus      = (string)($original['id_verification_status'] ?? 'approved');
    $origReviewed    = $original['id_reviewed_at'] ?? null;
    $origReason      = $original['id_rejection_reason'] ?? null;
    $origPurgeWarned = $original['purge_warned_at'] ?? null;

    try {
        // Rejected 2 hours ago: most of the 24h window still remains.
        $db->prepare("UPDATE users SET id_verification_status = 'rejected', id_reviewed_at = DATE_SUB(NOW(), INTERVAL 2 HOUR), id_rejection_reason = 'Blurred photo', purge_warned_at = NULL WHERE id = ?")
           ->execute([$customerId]);

        reset_customer_env();
        login_user($userModel->findById($customerId));
        $html = render_page(__DIR__ . '/../pages/customer/verification.php');
        assert_contains('scheduled for deletion', $html);
        assert_contains('permanently deleted', $html);
        assert_contains('hour', $html);

        // Past the deadline: stronger, urgent wording.
        $db->prepare("UPDATE users SET id_reviewed_at = DATE_SUB(NOW(), INTERVAL 30 HOUR) WHERE id = ?")
           ->execute([$customerId]);

        reset_customer_env();
        login_user($userModel->findById($customerId));
        $htmlPast = render_page(__DIR__ . '/../pages/customer/verification.php');
        assert_contains('past its deletion deadline', $htmlPast);
    } finally {
        $db->prepare("UPDATE users SET id_verification_status = ?, id_reviewed_at = ?, id_rejection_reason = ?, purge_warned_at = ? WHERE id = ?")
           ->execute([$origStatus, $origReviewed, $origReason, $origPurgeWarned, $customerId]);
    }

    return true;
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
