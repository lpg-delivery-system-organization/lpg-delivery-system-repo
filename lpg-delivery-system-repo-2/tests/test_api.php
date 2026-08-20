<?php
/**
 * Automated AJAX API Layer Test Suite
 * LPG Delivery System v2
 *
 * Tests:
 * 1. Syntax Linting & Configuration Integrity (orders.php, products.php, users.php, .htaccess)
 * 2. Unauthenticated Request Interception (HTTP 401)
 * 3. CSRF Security Enforcement (HTTP 403 on missing/invalid token, headers, POST parameters)
 * 4. Role Authorization & Access Control (HTTP 403 on insufficient permissions)
 * 5. Orders API Workflows (update_status, assign_rider, claim, cancel, get_order)
 * 6. Products API Workflows (update_stock, update_price, toggle_status, get_product)
 * 7. Users API Workflows (update_status, self-lockout guard, get_user with password redaction)
 * 8. Error Handling, Boundary Validations & Concurrency Guards (HTTP 400, 404, 409)
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

// Reset request environment between API calls
function reset_api_env(): void {
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
    unset($GLOBALS['LAST_HTTP_CODE'], $GLOBALS['LAST_REDIRECT'], $GLOBALS['LAST_RESPONSE'], $GLOBALS['LAST_EXCEPTION']);
}

// Helper to execute an API endpoint
function call_endpoint(string $endpointFile, array $post = [], array $get = [], array $headers = []): array {
    $_POST = $post;
    $_GET = $get;
    $_SERVER['REQUEST_METHOD'] = !empty($post) ? 'POST' : 'GET';
    $_SERVER['HTTP_ACCEPT'] = 'application/json';

    foreach ($headers as $k => $v) {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $k));
        $_SERVER[$serverKey] = $v;
    }

    unset($GLOBALS['LAST_HTTP_CODE'], $GLOBALS['LAST_RESPONSE']);

    ob_start();
    try {
        include $endpointFile;
    } catch (AuthException $e) {
        $GLOBALS['LAST_HTTP_CODE'] = $e->getStatusCode();
        $GLOBALS['LAST_RESPONSE'] = $e->getJsonResponse();
    } catch (Throwable $e) {
        $GLOBALS['LAST_EXCEPTION'] = $e;
    }
    $raw = ob_get_clean();

    $code = $GLOBALS['LAST_HTTP_CODE'] ?? 200;
    $response = $GLOBALS['LAST_RESPONSE'] ?? (json_decode($raw, true) ?: []);

    return [
        'code' => $code,
        'data' => $response,
        'raw'  => $raw
    ];
}

echo "====================================================\n";
echo " LPG Delivery System v2 - AJAX API Test Suite\n";
echo "====================================================\n\n";

$db = Database::connect();
$userModel = new User($db);
$productModel = new Product($db);
$orderModel = new Order($db);

// Retrieve or seed standard test accounts
$testAdmin = $userModel->findByEmail('admin@lpg.com');
if (!$testAdmin) {
    $adminPass = password_hash('Admin@2026!', PASSWORD_BCRYPT, ['cost' => 12]);
    $stmt = $db->prepare("
        INSERT INTO users (full_name, email, password, role, phone, address, status, created_at, updated_at)
        VALUES ('Maria Santos', 'admin@lpg.com', ?, 'admin', '09289876543', 'Admin HQ, Quezon City', 'active', NOW(), NOW())
    ");
    $stmt->execute([$adminPass]);
    $testAdmin = $userModel->findByEmail('admin@lpg.com');
}

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

// Second rider for rider permission checks
$testRider2 = $userModel->findByEmail('rider2@lpg.com');
if (!$testRider2) {
    $riderPass = password_hash('Rider2@2026!', PASSWORD_BCRYPT, ['cost' => 12]);
    $stmt = $db->prepare("
        INSERT INTO users (full_name, email, password, role, phone, address, status, created_at, updated_at)
        VALUES ('Juan Rider Two', 'rider2@lpg.com', ?, 'rider', '09353334444', '789 Rizal St, Pasay City', 'active', NOW(), NOW())
    ");
    $stmt->execute([$riderPass]);
    $testRider2 = $userModel->findByEmail('rider2@lpg.com');
}

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

// Second customer for order privacy checks
$testCustomer2 = $userModel->findByEmail('customer2@lpg.com');
if (!$testCustomer2) {
    $custPass = password_hash('Customer2@2026', PASSWORD_BCRYPT, ['cost' => 12]);
    $stmt = $db->prepare("
        INSERT INTO users (full_name, email, password, role, phone, address, status, created_at, updated_at)
        VALUES ('Maria Customer Two', 'customer2@lpg.com', ?, 'customer', '09177778888', '555 Taft Ave, Manila', 'active', NOW(), NOW())
    ");
    $stmt->execute([$custPass]);
    $testCustomer2 = $userModel->findByEmail('customer2@lpg.com');
}

$ordersApi = __DIR__ . '/../api/orders.php';
$productsApi = __DIR__ . '/../api/products.php';
$usersApi = __DIR__ . '/../api/users.php';
$htaccessApi = __DIR__ . '/../api/.htaccess';

// =========================================================================
// Group 1: Syntax & File Integrity
// =========================================================================
echo "Group 1: Syntax & File Integrity\n";

it('api/orders.php passes syntax linting', function () use ($ordersApi) {
    exec('php -l ' . escapeshellarg($ordersApi) . ' 2>&1', $output, $code);
    assert_equals(0, $code, 'orders.php syntax error: ' . implode("\n", $output));
});

it('api/products.php passes syntax linting', function () use ($productsApi) {
    exec('php -l ' . escapeshellarg($productsApi) . ' 2>&1', $output, $code);
    assert_equals(0, $code, 'products.php syntax error: ' . implode("\n", $output));
});

it('api/users.php passes syntax linting', function () use ($usersApi) {
    exec('php -l ' . escapeshellarg($usersApi) . ' 2>&1', $output, $code);
    assert_equals(0, $code, 'users.php syntax error: ' . implode("\n", $output));
});

it('api/.htaccess exists and contains Options -Indexes and security headers', function () use ($htaccessApi) {
    assert_true(file_exists($htaccessApi), 'api/.htaccess must exist');
    $content = file_get_contents($htaccessApi);
    assert_contains('Options -Indexes', $content);
    assert_contains('X-Content-Type-Options', $content);
    assert_contains('X-Frame-Options', $content);
    assert_contains('X-XSS-Protection', $content);
});

// =========================================================================
// Group 2: Unauthenticated Interception (HTTP 401)
// =========================================================================
echo "\nGroup 2: Unauthenticated Interception (HTTP 401)\n";

it('api/orders.php blocks unauthenticated requests with HTTP 401 JSON', function () use ($ordersApi) {
    reset_api_env();
    $res = call_endpoint($ordersApi, ['action' => 'update_status', 'order_id' => 1, 'status' => 'approved']);
    assert_equals(401, $res['code']);
    assert_false($res['data']['success'] ?? true);
    assert_contains('Authentication required', $res['data']['message'] ?? '');
});

it('api/products.php blocks unauthenticated requests with HTTP 401 JSON', function () use ($productsApi) {
    reset_api_env();
    $res = call_endpoint($productsApi, ['action' => 'update_stock', 'product_id' => 1, 'stock' => 10]);
    assert_equals(401, $res['code']);
    assert_false($res['data']['success'] ?? true);
    assert_contains('Authentication required', $res['data']['message'] ?? '');
});

it('api/users.php blocks unauthenticated requests with HTTP 401 JSON', function () use ($usersApi) {
    reset_api_env();
    $res = call_endpoint($usersApi, ['action' => 'update_status', 'user_id' => 1, 'status' => 'active']);
    assert_equals(401, $res['code']);
    assert_false($res['data']['success'] ?? true);
    assert_contains('Authentication required', $res['data']['message'] ?? '');
});

// =========================================================================
// Group 3: CSRF Protection (HTTP 403)
// =========================================================================
echo "\nGroup 3: CSRF Protection (HTTP 403)\n";

it('api/orders.php rejects mutations without CSRF token with HTTP 403', function () use ($ordersApi, $testAdmin) {
    reset_api_env();
    login_user($testAdmin);

    $res = call_endpoint($ordersApi, [
        'action'   => 'update_status',
        'order_id' => 1,
        'status'   => 'approved'
    ]);

    assert_equals(403, $res['code']);
    assert_false($res['data']['success'] ?? true);
    assert_contains('CSRF', $res['data']['message'] ?? '');
});

it('api/orders.php rejects mutations with invalid CSRF token with HTTP 403', function () use ($ordersApi, $testAdmin) {
    reset_api_env();
    login_user($testAdmin);

    $res = call_endpoint($ordersApi, [
        'action'     => 'update_status',
        'order_id'   => 1,
        'status'     => 'approved',
        'csrf_token' => 'invalid_csrf_token_value_here'
    ]);

    assert_equals(403, $res['code']);
    assert_false($res['data']['success'] ?? true);
    assert_contains('CSRF', $res['data']['message'] ?? '');
});

it('api/products.php rejects mutations with invalid CSRF token with HTTP 403', function () use ($productsApi, $testAdmin) {
    reset_api_env();
    login_user($testAdmin);

    $res = call_endpoint($productsApi, [
        'action'     => 'update_stock',
        'product_id' => 1,
        'stock'      => 50,
        'csrf_token' => 'bad_token'
    ]);

    assert_equals(403, $res['code']);
    assert_false($res['data']['success'] ?? true);
});

it('api/users.php rejects mutations with invalid CSRF token with HTTP 403', function () use ($usersApi, $testAdmin) {
    reset_api_env();
    login_user($testAdmin);

    $res = call_endpoint($usersApi, [
        'action'     => 'update_status',
        'user_id'    => 2,
        'status'     => 'active',
        'csrf_token' => 'bad_token'
    ]);

    assert_equals(403, $res['code']);
    assert_false($res['data']['success'] ?? true);
});

it('API endpoints accept valid CSRF token from X-CSRF-Token header', function () use ($productsApi, $testAdmin, $productModel) {
    reset_api_env();
    login_user($testAdmin);
    $token = csrf_token();

    $products = $productModel->getAll();
    $prodId = (int)$products[0]['id'];

    $res = call_endpoint(
        $productsApi,
        ['action' => 'update_stock', 'product_id' => $prodId, 'stock' => 88],
        [],
        ['X-CSRF-Token' => $token]
    );

    assert_equals(200, $res['code']);
    assert_true($res['data']['success'] ?? false);
    assert_equals(88, (int)$res['data']['data']['stock']);
});

// =========================================================================
// Group 4: Role Authorization & Route Guards
// =========================================================================
echo "\nGroup 4: Role Authorization & Route Guards\n";

it('Customer cannot access api/products.php (HTTP 403)', function () use ($productsApi, $testCustomer) {
    reset_api_env();
    login_user($testCustomer);
    $token = csrf_token();

    $res = call_endpoint($productsApi, [
        'action'     => 'update_stock',
        'product_id' => 1,
        'stock'      => 10,
        'csrf_token' => $token
    ]);

    assert_equals(403, $res['code']);
    assert_false($res['data']['success'] ?? true);
    assert_contains('Access denied', $res['data']['message'] ?? '');
});

it('Rider cannot access api/products.php (HTTP 403)', function () use ($productsApi, $testRider) {
    reset_api_env();
    login_user($testRider);
    $token = csrf_token();

    $res = call_endpoint($productsApi, [
        'action'     => 'update_stock',
        'product_id' => 1,
        'stock'      => 10,
        'csrf_token' => $token
    ]);

    assert_equals(403, $res['code']);
    assert_false($res['data']['success'] ?? true);
});

it('Customer cannot access api/users.php (HTTP 403)', function () use ($usersApi, $testCustomer) {
    reset_api_env();
    login_user($testCustomer);
    $token = csrf_token();

    $res = call_endpoint($usersApi, [
        'action'     => 'update_status',
        'user_id'    => 1,
        'status'     => 'active',
        'csrf_token' => $token
    ]);

    assert_equals(403, $res['code']);
    assert_false($res['data']['success'] ?? true);
});

it('Rider cannot access api/users.php (HTTP 403)', function () use ($usersApi, $testRider) {
    reset_api_env();
    login_user($testRider);
    $token = csrf_token();

    $res = call_endpoint($usersApi, [
        'action'     => 'update_status',
        'user_id'    => 1,
        'status'     => 'active',
        'csrf_token' => $token
    ]);

    assert_equals(403, $res['code']);
    assert_false($res['data']['success'] ?? true);
});

it('Customer cannot assign riders via api/orders.php (HTTP 403)', function () use ($ordersApi, $testCustomer) {
    reset_api_env();
    login_user($testCustomer);
    $token = csrf_token();

    $res = call_endpoint($ordersApi, [
        'action'     => 'assign_rider',
        'order_id'   => 1,
        'rider_id'   => 2,
        'csrf_token' => $token
    ]);

    assert_equals(403, $res['code']);
    assert_false($res['data']['success'] ?? true);
});

it('Customer cannot claim orders via api/orders.php (HTTP 403)', function () use ($ordersApi, $testCustomer) {
    reset_api_env();
    login_user($testCustomer);
    $token = csrf_token();

    $res = call_endpoint($ordersApi, [
        'action'     => 'claim',
        'order_id'   => 1,
        'csrf_token' => $token
    ]);

    assert_equals(403, $res['code']);
    assert_false($res['data']['success'] ?? true);
});

it('Rider cannot assign riders via api/orders.php (HTTP 403)', function () use ($ordersApi, $testRider) {
    reset_api_env();
    login_user($testRider);
    $token = csrf_token();

    $res = call_endpoint($ordersApi, [
        'action'     => 'assign_rider',
        'order_id'   => 1,
        'rider_id'   => 2,
        'csrf_token' => $token
    ]);

    assert_equals(403, $res['code']);
    assert_false($res['data']['success'] ?? true);
});

it('Rider cannot update status on an order assigned to another rider (HTTP 403)', function () use ($ordersApi, $testRider, $testRider2, $orderModel, $productModel, $testCustomer) {
    reset_api_env();
    $products = $productModel->getActive();
    $orderId = $orderModel->place([
        'customer_id'      => $testCustomer['id'],
        'product_id'       => $products[0]['id'],
        'quantity'         => 1,
        'payment_method'   => 'cod',
        'delivery_address' => 'Rider Isolation Test',
        'contact_phone'    => '09171112233',
        'status'           => 'approved'
    ]);

    // Assign to Rider 2
    $orderModel->assignRider($orderId, (int)$testRider2['id'], 'picked_up');

    // Login as Rider 1 and attempt to advance Rider 2's order
    login_user($testRider);
    $token = csrf_token();

    $res = call_endpoint($ordersApi, [
        'action'     => 'update_status',
        'order_id'   => $orderId,
        'status'     => 'out_for_delivery',
        'csrf_token' => $token
    ]);

    assert_equals(403, $res['code']);
    assert_false($res['data']['success'] ?? true);
    assert_contains('assigned to you', $res['data']['message'] ?? '');
});

it('Customer cannot cancel another customer order (HTTP 403)', function () use ($ordersApi, $testCustomer, $testCustomer2, $orderModel, $productModel) {
    reset_api_env();
    $products = $productModel->getActive();
    $orderId = $orderModel->place([
        'customer_id'      => $testCustomer['id'], // Belongs to Customer 1
        'product_id'       => $products[0]['id'],
        'quantity'         => 1,
        'payment_method'   => 'cod',
        'delivery_address' => 'Customer Privacy Test',
        'contact_phone'    => '09171112233',
        'status'           => 'pending'
    ]);

    // Login as Customer 2 and attempt to cancel Customer 1's order
    login_user($testCustomer2);
    $token = csrf_token();

    $res = call_endpoint($ordersApi, [
        'action'     => 'cancel',
        'order_id'   => $orderId,
        'csrf_token' => $token
    ]);

    assert_equals(403, $res['code']);
    assert_false($res['data']['success'] ?? true);
    assert_contains('own orders', $res['data']['message'] ?? '');
});

// =========================================================================
// Group 5: Orders API Workflows (api/orders.php)
// =========================================================================
echo "\nGroup 5: Orders API Workflows (api/orders.php)\n";

it('Admin updates order status (pending -> approved)', function () use ($ordersApi, $testAdmin, $orderModel, $productModel, $testCustomer) {
    reset_api_env();
    $products = $productModel->getActive();
    $orderId = $orderModel->place([
        'customer_id'      => $testCustomer['id'],
        'product_id'       => $products[0]['id'],
        'quantity'         => 1,
        'payment_method'   => 'cod',
        'delivery_address' => 'Admin Status Test',
        'contact_phone'    => '09171112233',
        'status'           => 'pending'
    ]);

    login_user($testAdmin);
    $token = csrf_token();

    $res = call_endpoint($ordersApi, [
        'action'     => 'update_status',
        'order_id'   => $orderId,
        'status'     => 'approved',
        'csrf_token' => $token
    ]);

    assert_equals(200, $res['code']);
    assert_true($res['data']['success'] ?? false);
    assert_equals('approved', $res['data']['data']['status'] ?? '');
});

it('Admin assigns rider to approved order (assign_rider)', function () use ($ordersApi, $testAdmin, $testRider, $orderModel, $productModel, $testCustomer) {
    reset_api_env();
    $products = $productModel->getActive();
    $orderId = $orderModel->place([
        'customer_id'      => $testCustomer['id'],
        'product_id'       => $products[0]['id'],
        'quantity'         => 1,
        'payment_method'   => 'cod',
        'delivery_address' => 'Admin Assign Test',
        'contact_phone'    => '09171112233',
        'status'           => 'approved'
    ]);

    login_user($testAdmin);
    $token = csrf_token();

    $res = call_endpoint($ordersApi, [
        'action'     => 'assign_rider',
        'order_id'   => $orderId,
        'rider_id'   => $testRider['id'],
        'status'     => 'picked_up',
        'csrf_token' => $token
    ]);

    assert_equals(200, $res['code']);
    assert_true($res['data']['success'] ?? false);
    assert_equals((int)$testRider['id'], (int)$res['data']['data']['rider_id']);
    assert_equals('picked_up', $res['data']['data']['status']);
});

it('Rider claims an available approved order (claim)', function () use ($ordersApi, $testRider, $orderModel, $productModel, $testCustomer) {
    reset_api_env();
    $products = $productModel->getActive();
    $orderId = $orderModel->place([
        'customer_id'      => $testCustomer['id'],
        'product_id'       => $products[0]['id'],
        'quantity'         => 1,
        'payment_method'   => 'cod',
        'delivery_address' => 'Rider Claim Test',
        'contact_phone'    => '09171112233',
        'status'           => 'approved'
    ]);

    login_user($testRider);
    $token = csrf_token();

    $res = call_endpoint($ordersApi, [
        'action'     => 'claim',
        'order_id'   => $orderId,
        'csrf_token' => $token
    ]);

    assert_equals(200, $res['code']);
    assert_true($res['data']['success'] ?? false);
    assert_equals((int)$testRider['id'], (int)$res['data']['data']['rider_id']);
    assert_equals('picked_up', $res['data']['data']['status']);
});

it('Rider updates status of assigned order (picked_up -> out_for_delivery -> delivered)', function () use ($ordersApi, $testRider, $orderModel, $productModel, $testCustomer) {
    reset_api_env();
    $products = $productModel->getActive();
    $orderId = $orderModel->place([
        'customer_id'      => $testCustomer['id'],
        'product_id'       => $products[0]['id'],
        'quantity'         => 1,
        'payment_method'   => 'cod',
        'delivery_address' => 'Rider Advance Test',
        'contact_phone'    => '09171112233',
        'status'           => 'approved'
    ]);

    $orderModel->assignRider($orderId, (int)$testRider['id'], 'picked_up');

    login_user($testRider);
    $token = csrf_token();

    // 1. Move to out_for_delivery
    $res1 = call_endpoint($ordersApi, [
        'action'     => 'update_status',
        'order_id'   => $orderId,
        'status'     => 'out_for_delivery',
        'csrf_token' => $token
    ]);
    assert_equals(200, $res1['code']);
    assert_equals('out_for_delivery', $res1['data']['data']['status']);

    // 2. Move to delivered
    $res2 = call_endpoint($ordersApi, [
        'action'     => 'update_status',
        'order_id'   => $orderId,
        'status'     => 'delivered',
        'csrf_token' => $token
    ]);
    assert_equals(200, $res2['code']);
    assert_equals('delivered', $res2['data']['data']['status']);
    assert_not_empty($res2['data']['data']['delivered_at']);
});

it('Customer cancels their pending order and restores stock', function () use ($ordersApi, $testCustomer, $orderModel, $productModel) {
    reset_api_env();
    $products = $productModel->getActive();
    $prod = $products[0];
    $initialStock = (int)$prod['stock'];

    $orderId = $orderModel->place([
        'customer_id'      => $testCustomer['id'],
        'product_id'       => $prod['id'],
        'quantity'         => 3,
        'payment_method'   => 'cod',
        'delivery_address' => 'Customer Cancel Test',
        'contact_phone'    => '09171112233',
        'status'           => 'pending'
    ]);

    login_user($testCustomer);
    $token = csrf_token();

    $res = call_endpoint($ordersApi, [
        'action'     => 'cancel',
        'order_id'   => $orderId,
        'reason'     => 'Changed delivery time preference',
        'csrf_token' => $token
    ]);

    assert_equals(200, $res['code']);
    assert_true($res['data']['success'] ?? false);
    assert_equals('cancelled', $res['data']['data']['status']);

    // Verify stock restored
    $freshProd = $productModel->findById($prod['id']);
    assert_equals($initialStock, (int)$freshProd['stock']);
});

it('Admin retrieves order details (get_order)', function () use ($ordersApi, $testAdmin, $orderModel, $productModel, $testCustomer) {
    reset_api_env();
    $products = $productModel->getActive();
    $orderId = $orderModel->place([
        'customer_id'      => $testCustomer['id'],
        'product_id'       => $products[0]['id'],
        'quantity'         => 1,
        'payment_method'   => 'cod',
        'delivery_address' => 'Admin Get Test',
        'contact_phone'    => '09171112233',
        'status'           => 'pending'
    ]);

    login_user($testAdmin);

    $res = call_endpoint($ordersApi, [], ['action' => 'get_order', 'order_id' => $orderId]);
    assert_equals(200, $res['code']);
    assert_true($res['data']['success'] ?? false);
    assert_equals($orderId, (int)$res['data']['data']['id']);
    assert_equals('Janister Singson', $res['data']['data']['customer_name']);
});

// =========================================================================
// Group 6: Products API Workflows (api/products.php)
// =========================================================================
echo "\nGroup 6: Products API Workflows (api/products.php)\n";

it('Admin updates product stock level (update_stock)', function () use ($productsApi, $testAdmin, $productModel) {
    reset_api_env();
    login_user($testAdmin);
    $token = csrf_token();

    $products = $productModel->getAll();
    $prodId = (int)$products[0]['id'];

    $res = call_endpoint($productsApi, [
        'action'     => 'update_stock',
        'product_id' => $prodId,
        'stock'      => 65,
        'csrf_token' => $token
    ]);

    assert_equals(200, $res['code']);
    assert_true($res['data']['success'] ?? false);
    assert_equals(65, (int)$res['data']['data']['stock']);
});

it('Admin updates product unit price (update_price)', function () use ($productsApi, $testAdmin, $productModel) {
    reset_api_env();
    login_user($testAdmin);
    $token = csrf_token();

    $products = $productModel->getAll();
    $prodId = (int)$products[0]['id'];

    $res = call_endpoint($productsApi, [
        'action'     => 'update_price',
        'product_id' => $prodId,
        'price'      => 975.50,
        'csrf_token' => $token
    ]);

    assert_equals(200, $res['code']);
    assert_true($res['data']['success'] ?? false);
    assert_equals(975.50, (float)$res['data']['data']['price']);
});

it('Admin toggles product status active/inactive (toggle_status)', function () use ($productsApi, $testAdmin, $productModel) {
    reset_api_env();
    login_user($testAdmin);
    $token = csrf_token();

    $products = $productModel->getAll();
    $prod = $products[0];
    $prodId = (int)$prod['id'];
    $initialStatus = $prod['status'];

    $res1 = call_endpoint($productsApi, [
        'action'     => 'toggle_status',
        'product_id' => $prodId,
        'csrf_token' => $token
    ]);

    assert_equals(200, $res1['code']);
    $expected1 = ($initialStatus === 'active') ? 'inactive' : 'active';
    assert_equals($expected1, $res1['data']['data']['status']);

    // Toggle back
    $res2 = call_endpoint($productsApi, [
        'action'     => 'toggle_status',
        'product_id' => $prodId,
        'csrf_token' => $token
    ]);

    assert_equals(200, $res2['code']);
    assert_equals($initialStatus, $res2['data']['data']['status']);
});

it('Admin retrieves product details (get_product)', function () use ($productsApi, $testAdmin, $productModel) {
    reset_api_env();
    login_user($testAdmin);

    $products = $productModel->getAll();
    $prodId = (int)$products[0]['id'];

    $res = call_endpoint($productsApi, [], ['action' => 'get_product', 'product_id' => $prodId]);
    assert_equals(200, $res['code']);
    assert_true($res['data']['success'] ?? false);
    assert_equals($prodId, (int)$res['data']['data']['id']);
    assert_not_empty($res['data']['data']['name']);
});

// =========================================================================
// Group 7: Users API Workflows (api/users.php)
// =========================================================================
echo "\nGroup 7: Users API Workflows (api/users.php)\n";

it('Admin updates user status (update_status: active -> suspended -> active)', function () use ($usersApi, $testAdmin, $testCustomer) {
    reset_api_env();
    login_user($testAdmin);
    $token = csrf_token();

    $targetUserId = (int)$testCustomer['id'];

    // 1. Suspend customer
    $res1 = call_endpoint($usersApi, [
        'action'     => 'update_status',
        'user_id'    => $targetUserId,
        'status'     => 'suspended',
        'csrf_token' => $token
    ]);

    assert_equals(200, $res1['code']);
    assert_true($res1['data']['success'] ?? false);
    assert_equals('suspended', $res1['data']['data']['status']);
    assert_false(isset($res1['data']['data']['password']), 'Password must NOT be returned in JSON response');

    // 2. Reactivate customer
    $res2 = call_endpoint($usersApi, [
        'action'     => 'update_status',
        'user_id'    => $targetUserId,
        'status'     => 'active',
        'csrf_token' => $token
    ]);

    assert_equals(200, $res2['code']);
    assert_equals('active', $res2['data']['data']['status']);
    assert_false(isset($res2['data']['data']['password']));
});

it('Admin self-lockout protection prevents admin from suspending own account (HTTP 400)', function () use ($usersApi, $testAdmin) {
    reset_api_env();
    login_user($testAdmin);
    $token = csrf_token();

    $adminId = (int)$testAdmin['id'];

    $res = call_endpoint($usersApi, [
        'action'     => 'update_status',
        'user_id'    => $adminId,
        'status'     => 'suspended',
        'csrf_token' => $token
    ]);

    assert_equals(400, $res['code']);
    assert_false($res['data']['success'] ?? true);
    assert_contains('cannot suspend or deactivate your own', $res['data']['message'] ?? '');
});

it('Admin retrieves user profile and password hash is redacted (get_user)', function () use ($usersApi, $testAdmin, $testCustomer) {
    reset_api_env();
    login_user($testAdmin);

    $targetUserId = (int)$testCustomer['id'];

    $res = call_endpoint($usersApi, [], ['action' => 'get_user', 'user_id' => $targetUserId]);
    assert_equals(200, $res['code']);
    assert_true($res['data']['success'] ?? false);
    assert_equals($targetUserId, (int)$res['data']['data']['id']);
    assert_equals('customer@lpg.com', $res['data']['data']['email']);
    assert_false(isset($res['data']['data']['password']), 'Password hash MUST NEVER be exposed in JSON response');
});

// =========================================================================
// Group 8: Error Boundaries & Concurrency Conflict Validations
// =========================================================================
echo "\nGroup 8: Error Boundaries & Concurrency Conflict Validations\n";

it('Non-existent order returns HTTP 404', function () use ($ordersApi, $testAdmin) {
    reset_api_env();
    login_user($testAdmin);
    $token = csrf_token();

    $res = call_endpoint($ordersApi, [
        'action'     => 'update_status',
        'order_id'   => 9999999,
        'status'     => 'approved',
        'csrf_token' => $token
    ]);

    assert_equals(404, $res['code']);
    assert_false($res['data']['success'] ?? true);
    assert_contains('not found', $res['data']['message'] ?? '');
});

it('Non-existent product returns HTTP 404', function () use ($productsApi, $testAdmin) {
    reset_api_env();
    login_user($testAdmin);
    $token = csrf_token();

    $res = call_endpoint($productsApi, [
        'action'     => 'update_stock',
        'product_id' => 9999999,
        'stock'      => 10,
        'csrf_token' => $token
    ]);

    assert_equals(404, $res['code']);
    assert_false($res['data']['success'] ?? true);
});

it('Non-existent user returns HTTP 404', function () use ($usersApi, $testAdmin) {
    reset_api_env();
    login_user($testAdmin);
    $token = csrf_token();

    $res = call_endpoint($usersApi, [
        'action'     => 'update_status',
        'user_id'    => 9999999,
        'status'     => 'active',
        'csrf_token' => $token
    ]);

    assert_equals(404, $res['code']);
    assert_false($res['data']['success'] ?? true);
});

it('Invalid status transition returns HTTP 400', function () use ($ordersApi, $testAdmin, $orderModel, $productModel, $testCustomer) {
    reset_api_env();
    $products = $productModel->getActive();
    $orderId = $orderModel->place([
        'customer_id'      => $testCustomer['id'],
        'product_id'       => $products[0]['id'],
        'quantity'         => 1,
        'payment_method'   => 'cod',
        'delivery_address' => 'Invalid Transition Test',
        'contact_phone'    => '09171112233',
        'status'           => 'pending'
    ]);

    login_user($testAdmin);
    $token = csrf_token();

    // pending -> delivered is forbidden
    $res = call_endpoint($ordersApi, [
        'action'     => 'update_status',
        'order_id'   => $orderId,
        'status'     => 'delivered',
        'csrf_token' => $token
    ]);

    assert_equals(400, $res['code']);
    assert_false($res['data']['success'] ?? true);
    assert_contains('Invalid status transition', $res['data']['message'] ?? '');
});

it('Claiming an already assigned/claimed order returns HTTP 409 Conflict', function () use ($ordersApi, $testRider, $testRider2, $orderModel, $productModel, $testCustomer) {
    reset_api_env();
    $products = $productModel->getActive();
    $orderId = $orderModel->place([
        'customer_id'      => $testCustomer['id'],
        'product_id'       => $products[0]['id'],
        'quantity'         => 1,
        'payment_method'   => 'cod',
        'delivery_address' => 'Claim Conflict Test',
        'contact_phone'    => '09171112233',
        'status'           => 'approved'
    ]);

    // Rider 1 claims first
    $orderModel->assignRider($orderId, (int)$testRider['id'], 'picked_up');

    // Rider 2 attempts to claim the same order
    login_user($testRider2);
    $token = csrf_token();

    $res = call_endpoint($ordersApi, [
        'action'     => 'claim',
        'order_id'   => $orderId,
        'csrf_token' => $token
    ]);

    assert_equals(409, $res['code']);
    assert_false($res['data']['success'] ?? true);
    assert_contains('no longer available', $res['data']['message'] ?? '');
});

it('Customer cancelling delivered order returns HTTP 400', function () use ($ordersApi, $testCustomer, $orderModel, $productModel, $testRider) {
    reset_api_env();
    $products = $productModel->getActive();
    $orderId = $orderModel->place([
        'customer_id'      => $testCustomer['id'],
        'product_id'       => $products[0]['id'],
        'quantity'         => 1,
        'payment_method'   => 'cod',
        'delivery_address' => 'Cancel Delivered Test',
        'contact_phone'    => '09171112233',
        'status'           => 'approved'
    ]);

    $orderModel->assignRider($orderId, (int)$testRider['id'], 'picked_up');
    $orderModel->updateStatus($orderId, 'out_for_delivery');
    $orderModel->updateStatus($orderId, 'delivered');

    login_user($testCustomer);
    $token = csrf_token();

    $res = call_endpoint($ordersApi, [
        'action'     => 'cancel',
        'order_id'   => $orderId,
        'csrf_token' => $token
    ]);

    assert_equals(400, $res['code']);
    assert_false($res['data']['success'] ?? true);
});

it('Negative stock adjustment returns HTTP 400', function () use ($productsApi, $testAdmin) {
    reset_api_env();
    login_user($testAdmin);
    $token = csrf_token();

    $res = call_endpoint($productsApi, [
        'action'     => 'update_stock',
        'product_id' => 1,
        'stock'      => -5,
        'csrf_token' => $token
    ]);

    assert_equals(400, $res['code']);
    assert_false($res['data']['success'] ?? true);
});

it('Invalid price adjustment returns HTTP 400', function () use ($productsApi, $testAdmin) {
    reset_api_env();
    login_user($testAdmin);
    $token = csrf_token();

    $res = call_endpoint($productsApi, [
        'action'     => 'update_price',
        'product_id' => 1,
        'price'      => -100.00,
        'csrf_token' => $token
    ]);

    assert_equals(400, $res['code']);
    assert_false($res['data']['success'] ?? true);
});

it('Invalid user status returns HTTP 400', function () use ($usersApi, $testAdmin, $testCustomer) {
    reset_api_env();
    login_user($testAdmin);
    $token = csrf_token();

    $res = call_endpoint($usersApi, [
        'action'     => 'update_status',
        'user_id'    => (int)$testCustomer['id'],
        'status'     => 'banned_permanently_invalid',
        'csrf_token' => $token
    ]);

    assert_equals(400, $res['code']);
    assert_false($res['data']['success'] ?? true);
});

it('Missing or unsupported action returns HTTP 400', function () use ($ordersApi, $testAdmin) {
    reset_api_env();
    login_user($testAdmin);
    $token = csrf_token();

    $res = call_endpoint($ordersApi, [
        'action'     => 'non_existent_action',
        'csrf_token' => $token
    ]);

    assert_equals(400, $res['code']);
    assert_false($res['data']['success'] ?? true);
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
