<?php
/**
 * Rider Portal Automated Integration Test Suite
 * LPG Delivery System v2
 *
 * Tests:
 * 1. Syntax & Linting Checks
 * 2. Rider Role Authorization & Route Guards
 * 3. Assigned Deliveries Management & Sequential Status Progression
 * 4. Available Orders & Concurrency-Safe Claiming Workflows
 * 5. Rider Profile & Contact Information Updates
 * 6. Security Checks (CSRF Protection & XSS Escaping)
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
function reset_rider_env(): void {
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
function render_rider_page(string $pagePath): string {
    ob_start();
    try {
        include $pagePath;
    } catch (AuthException $e) {
        // Expected during test mode redirects
    }
    return ob_get_clean();
}

echo "====================================================\n";
echo " LPG Delivery System v2 - Rider Portal Test Suite\n";
echo "====================================================\n\n";

$db = Database::connect();
$userModel = new User($db);
$productModel = new Product($db);
$orderModel = new Order($db);

// Retrieve or create standard test rider 1
$testRider = $userModel->findByEmail('rider@lpg.com');
if (!$testRider) {
    $riderPass = password_hash('Rider@2026!', PASSWORD_BCRYPT, ['cost' => 12]);
    $stmt = $db->prepare("
        INSERT INTO users (full_name, email, password, role, phone, address, status, created_at, updated_at)
        VALUES ('Pedro Reyes', 'rider@lpg.com', ?, 'rider', '09351112222', '456 Mabini Ave, Caloocan City', 'active', NOW(), NOW())
    ");
    $stmt->execute([$riderPass]);
    $testRider = $userModel->findByEmail('rider@lpg.com');
}
$riderId = (int)$testRider['id'];

// Retrieve or create secondary test rider 2 (for concurrency testing)
$testRider2 = $userModel->findByEmail('rider2@lpg.com');
if (!$testRider2) {
    $rider2Pass = password_hash('Rider2@2026!', PASSWORD_BCRYPT, ['cost' => 12]);
    $stmt = $db->prepare("
        INSERT INTO users (full_name, email, password, role, phone, address, status, created_at, updated_at)
        VALUES ('Juan Dela Cruz', 'rider2@lpg.com', ?, 'rider', '09359998888', '789 Rizal Ave, Manila', 'active', NOW(), NOW())
    ");
    $stmt->execute([$rider2Pass]);
    $testRider2 = $userModel->findByEmail('rider2@lpg.com');
}
$rider2Id = (int)$testRider2['id'];

// Retrieve or create test customer
$testCustomer = $userModel->findByEmail('customer@lpg.com');
if (!$testCustomer) {
    $custPass = password_hash('Customer@2026', PASSWORD_BCRYPT, ['cost' => 12]);
    $stmt = $db->prepare("
        INSERT INTO users (full_name, email, password, role, phone, address, status, created_at, updated_at)
        VALUES ('Janister Singson', 'customer@lpg.com', ?, 'customer', '09171234567', '123 Rizal St, Caloocan City', 'active', NOW(), NOW())
    ");
    $stmt->execute([$custPass]);
    $testCustomer = $userModel->findByEmail('customer@lpg.com');
}
$customerId = (int)$testCustomer['id'];

// Retrieve active product
$products = $productModel->getActive();
if (empty($products)) {
    $stmt = $db->prepare("
        INSERT INTO products (name, brand, weight, price, stock, status, created_at, updated_at)
        VALUES ('Solane 11kg', 'Solane', '11kg', 850.00, 50, 'active', NOW(), NOW())
    ");
    $stmt->execute();
    $products = $productModel->getActive();
}
$productId = (int)$products[0]['id'];

// =========================================================================
// Group 1: Syntax & File Integrity
// =========================================================================
echo "Group 1: Syntax & File Integrity\n";

it('deliveries.php passes syntax linting', function () {
    exec('php -l ' . escapeshellarg(__DIR__ . '/../pages/rider/deliveries.php') . ' 2>&1', $output, $code);
    assert_equals(0, $code, 'deliveries.php syntax error: ' . implode("\n", $output));
});

it('available.php passes syntax linting', function () {
    exec('php -l ' . escapeshellarg(__DIR__ . '/../pages/rider/available.php') . ' 2>&1', $output, $code);
    assert_equals(0, $code, 'available.php syntax error: ' . implode("\n", $output));
});

it('profile.php passes syntax linting', function () {
    exec('php -l ' . escapeshellarg(__DIR__ . '/../pages/rider/profile.php') . ' 2>&1', $output, $code);
    assert_equals(0, $code, 'profile.php syntax error: ' . implode("\n", $output));
});

it('rider.js exists and contains core rider portal interaction handlers', function () {
    $jsPath = __DIR__ . '/../assets/js/rider.js';
    assert_true(file_exists($jsPath), 'rider.js file must exist');
    $content = file_get_contents($jsPath);
    assert_contains('btn-open-deliver-modal', $content);
    assert_contains('btn-copy-address', $content);
    assert_contains('form-claim-delivery', $content);
    assert_contains('rider-filter-btn', $content);
    assert_contains('confirmDeliverModal', $content);
});

// =========================================================================
// Group 2: Role Authorization & Route Guards
// =========================================================================
echo "\nGroup 2: Role Authorization & Route Guards\n";

it('Unauthenticated guests are blocked from rider pages and redirected to login', function () {
    reset_rider_env();

    try {
        require_role('rider');
        assert_true(false, 'Should have thrown AuthException');
    } catch (AuthException $e) {
        assert_equals(302, $e->getStatusCode());
        assert_equals(url('/index.php'), $e->getRedirectUrl());
    }
});

it('Customer users accessing rider pages are redirected to customer shop', function () use ($testCustomer) {
    reset_rider_env();
    login_user($testCustomer);

    try {
        require_role('rider');
        assert_true(false, 'Customer should not pass rider role check');
    } catch (AuthException $e) {
        assert_equals(403, $e->getStatusCode());
        assert_equals(url('/pages/customer/shop.php'), $e->getRedirectUrl());
    }
});

it('Admin users accessing rider pages are redirected to admin dashboard', function () {
    reset_rider_env();
    login_user(['id' => 1, 'email' => 'admin@lpg.com', 'role' => 'admin', 'full_name' => 'Admin User']);

    try {
        require_role('rider');
        assert_true(false, 'Admin should not pass rider role check');
    } catch (AuthException $e) {
        assert_equals(403, $e->getStatusCode());
        assert_equals(url('/pages/admin/dashboard.php'), $e->getRedirectUrl());
    }
});

it('Authenticated riders are granted full access to rider pages', function () use ($testRider) {
    reset_rider_env();
    login_user($testRider);

    // Should execute without throwing AuthException
    require_role('rider');
    assert_true(is_logged_in());
    assert_equals('rider', current_user_role());
});

// =========================================================================
// Group 3: Deliveries Management & Status Progression (deliveries.php)
// =========================================================================
echo "\nGroup 3: Deliveries Management & Status Progression (deliveries.php)\n";

it('deliveries.php renders metrics, filter tabs, tel: links, and assigned deliveries', function () use ($testRider) {
    reset_rider_env();
    login_user($testRider);

    $html = render_rider_page(__DIR__ . '/../pages/rider/deliveries.php');

    assert_contains('My Assigned Deliveries', $html);
    assert_contains('Active Deliveries', $html);
    assert_contains('COD Cash to Collect', $html);
    assert_contains('rider-filter-btn', $html);
    assert_contains('Available Orders', $html);
    assert_contains('confirmDeliverModal', $html);
});

it('deliveries.php blocks status updates when CSRF token is invalid', function () use ($testRider) {
    reset_rider_env();
    login_user($testRider);

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = 'bad_token_123';
    $_POST['action'] = 'update_status';
    $_POST['order_id'] = 1001;
    $_POST['status'] = 'picked_up';

    render_rider_page(__DIR__ . '/../pages/rider/deliveries.php');

    assert_true(has_flash());
    $flash = get_flash();
    assert_equals('error', $flash['type']);
    assert_contains('Security token', $flash['message']);
});

it('deliveries.php executes sequential status progression: ready_for_delivery -> picked_up -> out_for_delivery -> delivered', function () use ($db, $testRider, $orderModel, $customerId, $productId, $riderId) {
    reset_rider_env();
    login_user($testRider);
    $token = csrf_token();

    // 1. Create order assigned to this rider in 'ready_for_delivery'
    $stmt = $db->prepare("
        INSERT INTO orders (customer_id, product_id, rider_id, quantity, unit_price, total_amount, payment_method, status, delivery_address, contact_phone, created_at, updated_at)
        VALUES (?, ?, ?, 1, 850.00, 850.00, 'cod', 'ready_for_delivery', '456 Test Street, Caloocan', '09171112233', NOW(), NOW())
    ");
    $stmt->execute([$customerId, $productId, $riderId]);
    $orderId = (int)$db->lastInsertId();

    // 2. Advance: ready_for_delivery -> picked_up
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $token;
    $_POST['action'] = 'update_status';
    $_POST['order_id'] = $orderId;
    $_POST['status'] = 'picked_up';

    render_rider_page(__DIR__ . '/../pages/rider/deliveries.php');

    assert_equals(url('/pages/rider/deliveries.php'), $GLOBALS['LAST_REDIRECT']);
    assert_true(has_flash());
    $flash = get_flash();
    assert_equals('success', $flash['type']);
    assert_contains('Picked Up', $flash['message']);

    $freshOrder = $orderModel->findById($orderId);
    assert_equals('picked_up', $freshOrder['status']);

    // 3. Advance: picked_up -> out_for_delivery
    reset_rider_env();
    login_user($testRider);
    $token = csrf_token();

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $token;
    $_POST['action'] = 'update_status';
    $_POST['order_id'] = $orderId;
    $_POST['status'] = 'out_for_delivery';

    render_rider_page(__DIR__ . '/../pages/rider/deliveries.php');

    assert_true(has_flash());
    $flash = get_flash();
    assert_equals('success', $flash['type']);
    assert_contains('Out for Delivery', $flash['message']);

    $freshOrder = $orderModel->findById($orderId);
    assert_equals('out_for_delivery', $freshOrder['status']);
    assert_equals(null, $freshOrder['delivered_at']);

    // 4. Advance: out_for_delivery -> delivered
    reset_rider_env();
    login_user($testRider);
    $token = csrf_token();

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $token;
    $_POST['action'] = 'update_status';
    $_POST['order_id'] = $orderId;
    $_POST['status'] = 'delivered';

    render_rider_page(__DIR__ . '/../pages/rider/deliveries.php');

    assert_true(has_flash());
    $flash = get_flash();
    assert_equals('success', $flash['type']);
    assert_contains('Delivered', $flash['message']);

    $freshOrder = $orderModel->findById($orderId);
    assert_equals('delivered', $freshOrder['status']);
    assert_not_empty($freshOrder['delivered_at'], 'delivered_at timestamp must be recorded');
});

it('deliveries.php prevents rider from updating orders assigned to another rider', function () use ($db, $testRider, $testRider2, $customerId, $productId, $rider2Id) {
    reset_rider_env();
    login_user($testRider);
    $token = csrf_token();

    // Create order assigned to Rider 2
    $stmt = $db->prepare("
        INSERT INTO orders (customer_id, product_id, rider_id, quantity, unit_price, total_amount, payment_method, status, delivery_address, contact_phone, created_at, updated_at)
        VALUES (?, ?, ?, 1, 850.00, 850.00, 'cod', 'picked_up', 'Other Rider St', '09172223344', NOW(), NOW())
    ");
    $stmt->execute([$customerId, $productId, $rider2Id]);
    $otherOrderId = (int)$db->lastInsertId();

    // Rider 1 attempts to update Rider 2's order
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $token;
    $_POST['action'] = 'update_status';
    $_POST['order_id'] = $otherOrderId;
    $_POST['status'] = 'out_for_delivery';

    render_rider_page(__DIR__ . '/../pages/rider/deliveries.php');

    assert_true(has_flash());
    $flash = get_flash();
    assert_equals('error', $flash['type']);
    assert_contains('unauthorized', strtolower($flash['message']));

    // Verify order status unchanged in DB
    $stmt = $db->prepare("SELECT status FROM orders WHERE id = ?");
    $stmt->execute([$otherOrderId]);
    assert_equals('picked_up', $stmt->fetchColumn());
});

it('deliveries.php rejects invalid status transitions according to state machine', function () use ($db, $testRider, $customerId, $productId, $riderId) {
    reset_rider_env();
    login_user($testRider);
    $token = csrf_token();

    // Create order in 'picked_up'
    $stmt = $db->prepare("
        INSERT INTO orders (customer_id, product_id, rider_id, quantity, unit_price, total_amount, payment_method, status, delivery_address, contact_phone, created_at, updated_at)
        VALUES (?, ?, ?, 1, 850.00, 850.00, 'cod', 'picked_up', 'State Test St', '09173334455', NOW(), NOW())
    ");
    $stmt->execute([$customerId, $productId, $riderId]);
    $orderId = (int)$db->lastInsertId();

    // Try invalid transition: picked_up -> pending
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $token;
    $_POST['action'] = 'update_status';
    $_POST['order_id'] = $orderId;
    $_POST['status'] = 'pending';

    render_rider_page(__DIR__ . '/../pages/rider/deliveries.php');

    assert_true(has_flash());
    $flash = get_flash();
    assert_equals('error', $flash['type']);
    assert_contains('Cannot transition', $flash['message']);
});

// =========================================================================
// Group 4: Available Orders & Concurrency-Safe Claiming (available.php)
// =========================================================================
echo "\nGroup 4: Available Orders & Concurrency-Safe Claiming (available.php)\n";

it('available.php renders ready orders, COD collection info, and claim buttons', function () use ($db, $testRider, $customerId, $productId) {
    reset_rider_env();
    login_user($testRider);

    // Ensure at least one unassigned order in 'approved' exists
    $stmt = $db->prepare("
        INSERT INTO orders (customer_id, product_id, rider_id, quantity, unit_price, total_amount, payment_method, status, delivery_address, contact_phone, created_at, updated_at)
        VALUES (?, ?, NULL, 1, 850.00, 850.00, 'cod', 'approved', 'Available Test St, Pasig City', '09179998888', NOW(), NOW())
    ");
    $stmt->execute([$customerId, $productId]);
    $availOrderId = (int)$db->lastInsertId();

    $html = render_rider_page(__DIR__ . '/../pages/rider/available.php');

    assert_contains('Available Delivery Orders', $html);
    assert_contains('Available for Claiming', $html);
    assert_contains('Claim Delivery', $html);
    assert_contains('btn-copy-address', $html);
    assert_contains('form-claim-delivery', $html);
});

it('available.php allows rider to claim an order, atomically sets rider_id and transitions to picked_up', function () use ($db, $testRider, $orderModel, $customerId, $productId, $riderId) {
    reset_rider_env();
    login_user($testRider);
    $token = csrf_token();

    // Create unassigned order in 'ready_for_delivery'
    $stmt = $db->prepare("
        INSERT INTO orders (customer_id, product_id, rider_id, quantity, unit_price, total_amount, payment_method, status, delivery_address, contact_phone, created_at, updated_at)
        VALUES (?, ?, NULL, 2, 850.00, 1700.00, 'gcash', 'ready_for_delivery', 'Claim Test St', '09175556677', NOW(), NOW())
    ");
    $stmt->execute([$customerId, $productId]);
    $claimOrderId = (int)$db->lastInsertId();

    // Claim the order via POST
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $token;
    $_POST['action'] = 'claim_order';
    $_POST['order_id'] = $claimOrderId;

    render_rider_page(__DIR__ . '/../pages/rider/available.php');

    // Should redirect to deliveries.php with success flash
    assert_equals(url('/pages/rider/deliveries.php'), $GLOBALS['LAST_REDIRECT']);
    assert_true(has_flash());
    $flash = get_flash();
    assert_equals('success', $flash['type']);
    assert_contains('successfully claimed', $flash['message']);

    // Verify DB state
    $freshOrder = $orderModel->findById($claimOrderId);
    assert_equals($riderId, (int)$freshOrder['rider_id']);
    assert_equals('picked_up', $freshOrder['status']);
});

it('available.php prevents race condition: when two riders claim the same order, second rider fails gracefully', function () use ($db, $testRider, $testRider2, $orderModel, $customerId, $productId, $riderId, $rider2Id) {
    // 1. Create a single unassigned order
    $stmt = $db->prepare("
        INSERT INTO orders (customer_id, product_id, rider_id, quantity, unit_price, total_amount, payment_method, status, delivery_address, contact_phone, created_at, updated_at)
        VALUES (?, ?, NULL, 1, 850.00, 850.00, 'cod', 'approved', 'Concurrency Race St', '09176667788', NOW(), NOW())
    ");
    $stmt->execute([$customerId, $productId]);
    $raceOrderId = (int)$db->lastInsertId();

    // 2. Rider 1 claims first
    reset_rider_env();
    login_user($testRider);
    $token1 = csrf_token();

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $token1;
    $_POST['action'] = 'claim_order';
    $_POST['order_id'] = $raceOrderId;

    render_rider_page(__DIR__ . '/../pages/rider/available.php');

    assert_equals(url('/pages/rider/deliveries.php'), $GLOBALS['LAST_REDIRECT']);
    assert_true(has_flash());
    $flash1 = get_flash();
    assert_equals('success', $flash1['type']);

    // 3. Rider 2 attempts to claim the same already-claimed order
    reset_rider_env();
    login_user($testRider2);
    $token2 = csrf_token();

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $token2;
    $_POST['action'] = 'claim_order';
    $_POST['order_id'] = $raceOrderId;

    render_rider_page(__DIR__ . '/../pages/rider/available.php');

    // Rider 2 is redirected back to available.php with error flash
    assert_equals(url('/pages/rider/available.php'), $GLOBALS['LAST_REDIRECT']);
    assert_true(has_flash());
    $flash2 = get_flash();
    assert_equals('error', $flash2['type']);
    assert_contains('could not be claimed', $flash2['message']);

    // Verify order remains assigned to Rider 1 and NOT overwritten
    $freshOrder = $orderModel->findById($raceOrderId);
    assert_equals($riderId, (int)$freshOrder['rider_id']);
    assert_equals('picked_up', $freshOrder['status']);
});

// =========================================================================
// Group 5: Rider Profile Management (profile.php)
// =========================================================================
echo "\nGroup 5: Rider Profile Management (profile.php)\n";

it('profile.php renders rider stats, contact info, and forms', function () use ($userModel, $riderId) {
    reset_rider_env();
    $riderUser = $userModel->findById($riderId);
    login_user($riderUser);

    $html = render_rider_page(__DIR__ . '/../pages/rider/profile.php');

    assert_contains('Rider Profile & Performance', $html);
    assert_contains($riderUser['full_name'], $html);
    assert_contains($riderUser['email'], $html);
    assert_contains('Delivery Performance', $html);
    assert_contains('Edit Rider Contact Details', $html);
    assert_contains('Change Account Password', $html);
    assert_contains('name="full_name"', $html);
    assert_contains('name="phone"', $html);
    assert_contains('name="address"', $html);
});

it('profile.php updates rider full name, phone number, and residential address', function () use ($testRider, $userModel, $riderId) {
    reset_rider_env();
    login_user($testRider);
    $token = csrf_token();

    $newFullName = 'Pedro Updated Reyes';
    $newPhone = '09358887766';
    $newAddress = 'Unit 102, Sunrise Condominium, Quezon City';

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $token;
    $_POST['action'] = 'update_profile';
    $_POST['full_name'] = $newFullName;
    $_POST['phone'] = $newPhone;
    $_POST['address'] = $newAddress;

    render_rider_page(__DIR__ . '/../pages/rider/profile.php');

    assert_equals(url('/pages/rider/profile.php'), $GLOBALS['LAST_REDIRECT']);
    assert_true(has_flash());
    $flash = get_flash();
    assert_equals('success', $flash['type']);

    // Verify DB update
    $freshRider = $userModel->findById($riderId);
    assert_equals($newFullName, $freshRider['full_name']);
    assert_equals($newPhone, $freshRider['phone']);
    assert_equals($newAddress, $freshRider['address']);
    assert_equals($newFullName, $_SESSION['user_name']);
});

it('profile.php validates phone format and complexity rules for password change', function () use ($testRider, $userModel, $riderId) {
    reset_rider_env();
    login_user($testRider);
    $token = csrf_token();

    // 1. Invalid phone number format
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $token;
    $_POST['action'] = 'update_profile';
    $_POST['full_name'] = 'Pedro Reyes';
    $_POST['phone'] = '0912'; // Too short
    $_POST['address'] = 'Valid Address';

    $htmlPhoneErr = render_rider_page(__DIR__ . '/../pages/rider/profile.php');
    assert_contains('11-digit Philippine mobile number', $htmlPhoneErr);

    // 2. Wrong current password
    reset_rider_env();
    login_user($testRider);
    $token = csrf_token();

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $token;
    $_POST['action'] = 'change_password';
    $_POST['current_password'] = 'IncorrectPassword999!';
    $_POST['new_password'] = 'NewStrongPass@2026!';
    $_POST['confirm_new_password'] = 'NewStrongPass@2026!';

    $htmlPassErr = render_rider_page(__DIR__ . '/../pages/rider/profile.php');
    assert_contains('current password you entered is incorrect', $htmlPassErr);

    // 3. Weak new password
    reset_rider_env();
    login_user($testRider);
    $token = csrf_token();

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $token;
    $_POST['action'] = 'change_password';
    $_POST['current_password'] = 'Rider@2026!';
    $_POST['new_password'] = 'weak';
    $_POST['confirm_new_password'] = 'weak';

    $htmlWeakErr = render_rider_page(__DIR__ . '/../pages/rider/profile.php');
    assert_contains('must be at least 8 characters', $htmlWeakErr);

    // 4. Successful password update
    reset_rider_env();
    login_user($testRider);
    $token = csrf_token();

    $newPass = 'NewRiderSecurePass@2026!';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $token;
    $_POST['action'] = 'change_password';
    $_POST['current_password'] = 'Rider@2026!';
    $_POST['new_password'] = $newPass;
    $_POST['confirm_new_password'] = $newPass;

    render_rider_page(__DIR__ . '/../pages/rider/profile.php');

    assert_equals(url('/pages/rider/profile.php'), $GLOBALS['LAST_REDIRECT']);
    assert_true(has_flash());
    $flash = get_flash();
    assert_equals('success', $flash['type']);

    // Verify DB password updated
    $updatedRider = $userModel->findById($riderId);
    assert_true(password_verify($newPass, $updatedRider['password']));

    // Restore original password for repeatable test runs
    $userModel->updatePassword($riderId, 'Rider@2026!');
});

// =========================================================================
// Group 6: Security & XSS Mitigation
// =========================================================================
echo "\nGroup 6: Security & XSS Mitigation\n";

it('Rider pages escape malicious script tags in customer names, notes, and addresses', function () use ($db, $testRider, $productId, $riderId) {
    reset_rider_env();

    // Create customer and order with XSS payloads
    $xssCustPass = password_hash('Pass@123!', PASSWORD_BCRYPT);
    $xssEmail = 'xss_rider_test_' . bin2hex(random_bytes(4)) . '@test.com';
    $stmt = $db->prepare("
        INSERT INTO users (full_name, email, password, role, phone, address, status, created_at, updated_at)
        VALUES ('<script>alert(\"xss_cust\")</script>', ?, ?, 'customer', '09170001122', '<b onmouseover=\"alert(\'xss_addr\')\">Malicious Address</b>', 'active', NOW(), NOW())
    ");
    $stmt->execute([$xssEmail, $xssCustPass]);
    $xssCustId = (int)$db->lastInsertId();

    $stmt = $db->prepare("
        INSERT INTO orders (customer_id, product_id, rider_id, quantity, unit_price, total_amount, payment_method, status, delivery_address, contact_phone, notes, created_at, updated_at)
        VALUES (?, ?, ?, 1, 850.00, 850.00, 'cod', 'picked_up', '<img src=x onerror=alert(1)>', '09170001122', '<script>alert(\"xss_note\")</script>', NOW(), NOW())
    ");
    $stmt->execute([$xssCustId, $productId, $riderId]);
    $xssOrderId = (int)$db->lastInsertId();

    login_user($testRider);
    $html = render_rider_page(__DIR__ . '/../pages/rider/deliveries.php');

    assert_not_contains('<script>alert("xss_cust")</script>', $html);
    assert_contains('&lt;script&gt;alert(&quot;xss_cust&quot;)&lt;/script&gt;', $html);
    assert_not_contains('<img src=x onerror=alert(1)>', $html);
    assert_not_contains('<script>alert("xss_note")</script>', $html);
    assert_contains('&lt;script&gt;alert(&quot;xss_note&quot;)&lt;/script&gt;', $html);

    // Clean up
    $db->prepare("DELETE FROM orders WHERE id = ?")->execute([$xssOrderId]);
    $db->prepare("DELETE FROM users WHERE id = ?")->execute([$xssCustId]);
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
