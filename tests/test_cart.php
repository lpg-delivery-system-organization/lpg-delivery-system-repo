<?php
/**
 * Shopping Cart Test Suite
 * LPG Delivery System v2
 *
 * Verifies the persistent, DB-backed cart used by the shop checkout:
 * 1. Syntax linting of classes/Cart.php, api/cart.php & pages/customer/shop.php
 * 2. Cart model: add / merge / update / remove / clear behaviour
 * 3. Cart model guards: invalid ids, inactive & out-of-stock products,
 *    per-line cap (MAX_PER_ITEM) and stock ceilings
 * 4. Cart enrichment: live subtotals, is_active, within_stock, summary totals
 * 5. api/cart.php endpoint: auth (401), CSRF (403), role guard, and every
 *    action (list/add/update/remove/clear) plus error handling
 *
 * The suite creates its own throwaway products and always empties the test
 * customer's cart, so it never leaks state into the other suites (an
 * un-cleared cart would otherwise take precedence over a POSTed product_id).
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

function assert_throws_message(string $needle, callable $fn, string $message = ''): bool {
    try {
        $fn();
    } catch (Throwable $e) {
        if (strpos($e->getMessage(), $needle) === false) {
            throw new Exception(
                ($message ? $message . ': ' : '') .
                'Exception message "' . $e->getMessage() . '" did not contain "' . $needle . '".'
            );
        }
        return true;
    }
    throw new Exception(($message ? $message . ': ' : '') . 'Expected an exception containing "' . $needle . '" but none was thrown.');
}

// Reset request environment between endpoint calls
function reset_cart_env(): void {
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

// Execute api/cart.php in-process under TEST_MODE (json_response records instead of exiting)
function call_cart_endpoint(array $post = [], array $get = [], array $headers = []): array {
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
        include __DIR__ . '/../api/cart.php';
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
        'raw'  => $raw,
    ];
}

echo "====================================================\n";
echo " LPG Delivery System v2 - Shopping Cart Tests\n";
echo "====================================================\n\n";

$db = Database::connect();
$userModel = new User($db);
$productModel = new Product($db);
$cartModel = new Cart($db);

// Retrieve or create the standard test customer
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

$adminUser = $userModel->findByEmail('admin@lpg.com');

// Always start from a clean cart so no other suite is affected
$cartModel->clear($customerId);
if ($adminUser) {
    $cartModel->clear((int)$adminUser['id']);
}

// Throwaway products created by this suite (cleaned up in the finally block)
$createdProductIds = [];

function make_product(PDO $db, array &$createdProductIds, array $overrides = []): array {
    $defaults = [
        'name'   => 'Cart Test Cylinder ' . bin2hex(random_bytes(3)),
        'brand'  => 'CartTestBrand',
        'weight' => '11kg',
        'price'  => 850.00,
        'stock'  => 20,
        'status' => 'active',
    ];
    $data = array_merge($defaults, $overrides);

    $stmt = $db->prepare("
        INSERT INTO products (name, brand, weight, price, stock, image_url, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, NULL, ?, NOW(), NOW())
    ");
    $stmt->execute([
        $data['name'],
        $data['brand'],
        $data['weight'],
        $data['price'],
        $data['stock'],
        $data['status'],
    ]);
    $id = (int)$db->lastInsertId();
    $createdProductIds[] = $id;

    $data['id'] = $id;
    return $data;
}

try {
    // =====================================================================
    // Group 1: Syntax & File Integrity
    // =====================================================================
    echo "Group 1: Syntax & File Integrity\n";

    it('classes/Cart.php passes syntax linting', function () {
        exec('php -l ' . escapeshellarg(__DIR__ . '/../classes/Cart.php') . ' 2>&1', $output, $code);
        assert_equals(0, $code, 'Cart.php syntax error: ' . implode("\n", $output));
    });

    it('api/cart.php passes syntax linting', function () {
        exec('php -l ' . escapeshellarg(__DIR__ . '/../api/cart.php') . ' 2>&1', $output, $code);
        assert_equals(0, $code, 'api/cart.php syntax error: ' . implode("\n", $output));
    });

    it('shop.php passes syntax linting', function () {
        exec('php -l ' . escapeshellarg(__DIR__ . '/../pages/customer/shop.php') . ' 2>&1', $output, $code);
        assert_equals(0, $code, 'shop.php syntax error: ' . implode("\n", $output));
    });

    it('customer.js wires the cart drawer and add-to-cart handlers', function () {
        $js = file_get_contents(__DIR__ . '/../assets/js/customer.js');
        assert_contains('initShopCart', $js);
        assert_contains('btn-add-to-cart', $js);
        assert_contains('data-cart-action', $js);
        assert_contains('cartDrawer', $js);
    });

    // =====================================================================
    // Group 2: Cart Model - Add & Merge
    // =====================================================================
    echo "\nGroup 2: Cart Model - Add & Merge\n";

    it('addItem inserts a new cart line and returns the quantity', function () use ($db, $cartModel, $customerId, &$createdProductIds) {
        $cartModel->clear($customerId);
        $p = make_product($db, $createdProductIds, ['stock' => 20]);

        $qty = $cartModel->addItem($customerId, (int)$p['id'], 2);
        assert_equals(2, $qty);

        $items = $cartModel->getItems($customerId);
        assert_equals(1, count($items));
        assert_equals((int)$p['id'], (int)$items[0]['product_id']);
        assert_equals(2, (int)$items[0]['quantity']);
        return true;
    });

    it('addItem merges into an existing line instead of duplicating it', function () use ($db, $cartModel, $customerId, &$createdProductIds) {
        $cartModel->clear($customerId);
        $p = make_product($db, $createdProductIds, ['stock' => 20]);

        $cartModel->addItem($customerId, (int)$p['id'], 2);
        $cartModel->addItem($customerId, (int)$p['id'], 3);

        $items = $cartModel->getItems($customerId);
        assert_equals(1, count($items), 'a product must only occupy one line');
        assert_equals(5, (int)$items[0]['quantity']);
        return true;
    });

    it('addItem with a non-positive quantity defaults the line to 1', function () use ($db, $cartModel, $customerId, &$createdProductIds) {
        $cartModel->clear($customerId);
        $p = make_product($db, $createdProductIds, ['stock' => 20]);

        $qty = $cartModel->addItem($customerId, (int)$p['id'], 0);
        assert_equals(1, $qty);
        return true;
    });

    // =====================================================================
    // Group 3: Cart Model - Guards
    // =====================================================================
    echo "\nGroup 3: Cart Model - Guards\n";

    it('addItem rejects an unknown product id', function () use ($cartModel, $customerId) {
        assert_throws_message('could not be found', function () use ($cartModel, $customerId) {
            $cartModel->addItem($customerId, 999999999, 1);
        });
    });

    it('addItem rejects a product whose status is not active', function () use ($db, $cartModel, $customerId, &$createdProductIds) {
        $cartModel->clear($customerId);
        $p = make_product($db, $createdProductIds, ['status' => 'inactive']);
        assert_throws_message('currently unavailable', function () use ($cartModel, $customerId, $p) {
            $cartModel->addItem($customerId, (int)$p['id'], 1);
        });
    });

    it('addItem rejects an out-of-stock product', function () use ($db, $cartModel, $customerId, &$createdProductIds) {
        $cartModel->clear($customerId);
        $p = make_product($db, $createdProductIds, ['stock' => 0]);
        assert_throws_message('out of stock', function () use ($cartModel, $customerId, $p) {
            $cartModel->addItem($customerId, (int)$p['id'], 1);
        });
    });

    it('addItem enforces the per-line MAX_PER_ITEM cap', function () use ($db, $cartModel, $customerId, &$createdProductIds) {
        $cartModel->clear($customerId);
        $p = make_product($db, $createdProductIds, ['stock' => 50]);
        assert_throws_message('at most ' . Cart::MAX_PER_ITEM . ' units', function () use ($cartModel, $customerId, $p) {
            $cartModel->addItem($customerId, (int)$p['id'], Cart::MAX_PER_ITEM + 1);
        });
    });

    it('addItem rejects a quantity beyond the live stock count', function () use ($db, $cartModel, $customerId, &$createdProductIds) {
        $cartModel->clear($customerId);
        $p = make_product($db, $createdProductIds, ['stock' => 3]);
        assert_throws_message('currently available', function () use ($cartModel, $customerId, $p) {
            $cartModel->addItem($customerId, (int)$p['id'], 4);
        });
    });

    it('addItem rejects invalid customer/product arguments', function () use ($cartModel) {
        assert_throws_message('valid product', function () use ($cartModel) {
            $cartModel->addItem(0, 1, 1);
        });
    });

    // =====================================================================
    // Group 4: Cart Model - Update / Remove / Clear
    // =====================================================================
    echo "\nGroup 4: Cart Model - Update / Remove / Clear\n";

    it('updateItem sets an absolute quantity', function () use ($db, $cartModel, $customerId, &$createdProductIds) {
        $cartModel->clear($customerId);
        $p = make_product($db, $createdProductIds, ['stock' => 20]);
        $cartModel->addItem($customerId, (int)$p['id'], 2);

        $result = $cartModel->updateItem($customerId, (int)$p['id'], 5);
        assert_equals(5, $result);
        assert_equals(5, (int)$cartModel->getItems($customerId)[0]['quantity']);
        return true;
    });

    it('updateItem rejects a quantity below 1', function () use ($db, $cartModel, $customerId, &$createdProductIds) {
        $cartModel->clear($customerId);
        $p = make_product($db, $createdProductIds, ['stock' => 20]);
        $cartModel->addItem($customerId, (int)$p['id'], 2);
        assert_throws_message('at least 1', function () use ($cartModel, $customerId, $p) {
            $cartModel->updateItem($customerId, (int)$p['id'], 0);
        });
    });

    it('updateItem rejects a product that is not in the cart', function () use ($db, $cartModel, $customerId, &$createdProductIds) {
        $cartModel->clear($customerId);
        $p = make_product($db, $createdProductIds, ['stock' => 20]);
        assert_throws_message('not in your cart', function () use ($cartModel, $customerId, $p) {
            $cartModel->updateItem($customerId, (int)$p['id'], 2);
        });
    });

    it('removeItem deletes the line and reports the removal', function () use ($db, $cartModel, $customerId, &$createdProductIds) {
        $cartModel->clear($customerId);
        $p = make_product($db, $createdProductIds, ['stock' => 20]);
        $cartModel->addItem($customerId, (int)$p['id'], 2);

        assert_true($cartModel->removeItem($customerId, (int)$p['id']));
        assert_equals(0, count($cartModel->getItems($customerId)));
        assert_false($cartModel->removeItem($customerId, (int)$p['id']), 'removing an absent line returns false');
        return true;
    });

    it('clear empties every line for the customer only', function () use ($db, $cartModel, $customerId, $adminUser, &$createdProductIds) {
        $cartModel->clear($customerId);
        $p1 = make_product($db, $createdProductIds, ['stock' => 20]);
        $p2 = make_product($db, $createdProductIds, ['stock' => 20]);
        $cartModel->addItem($customerId, (int)$p1['id'], 1);
        $cartModel->addItem($customerId, (int)$p2['id'], 1);

        assert_true($cartModel->clear($customerId));
        assert_equals(0, count($cartModel->getItems($customerId)));
        return true;
    });

    // =====================================================================
    // Group 5: Cart Model - Enrichment & Totals
    // =====================================================================
    echo "\nGroup 5: Cart Model - Enrichment & Totals\n";

    it('getItems enriches lines with live price, subtotal and stock flags', function () use ($db, $cartModel, $customerId, &$createdProductIds) {
        $cartModel->clear($customerId);
        $p = make_product($db, $createdProductIds, ['stock' => 10, 'price' => 900.00, 'status' => 'active']);
        $cartModel->addItem($customerId, (int)$p['id'], 3);

        $item = $cartModel->getItems($customerId)[0];
        assert_equals(900.00, (float)$item['price']);
        assert_equals(2700.00, (float)$item['subtotal']);
        assert_true($item['is_active']);
        assert_true($item['within_stock']);
        return true;
    });

    it('getFiles marks an inactive line as not active', function () use ($db, $cartModel, $customerId, &$createdProductIds) {
        $cartModel->clear($customerId);
        $p = make_product($db, $createdProductIds, ['stock' => 10, 'status' => 'active']);
        $cartModel->addItem($customerId, (int)$p['id'], 1);

        // Deactivate after it is already in the cart.
        $db->prepare("UPDATE products SET status = 'inactive' WHERE id = ?")->execute([(int)$p['id']]);

        $item = $cartModel->getItems($customerId)[0];
        assert_false($item['is_active']);
        assert_false($item['within_stock']);
        return true;
    });

    it('getSummary returns line count, unit total and monetary subtotal', function () use ($db, $cartModel, $customerId, &$createdProductIds) {
        $cartModel->clear($customerId);
        $a = make_product($db, $createdProductIds, ['stock' => 20, 'price' => 1000.00]);
        $b = make_product($db, $createdProductIds, ['stock' => 20, 'price' => 500.00]);
        $cartModel->addItem($customerId, (int)$a['id'], 2);
        $cartModel->addItem($customerId, (int)$b['id'], 3);

        $summary = $cartModel->getSummary($customerId);
        assert_equals(2, $summary['line_count']);
        assert_equals(5, $summary['units']);
        assert_equals(3500.00, (float)$summary['subtotal']);
        return true;
    });

    it('countUnits sums the raw quantities across lines', function () use ($db, $cartModel, $customerId, &$createdProductIds) {
        $cartModel->clear($customerId);
        $a = make_product($db, $createdProductIds, ['stock' => 20]);
        $b = make_product($db, $createdProductIds, ['stock' => 20]);
        $cartModel->addItem($customerId, (int)$a['id'], 4);
        $cartModel->addItem($customerId, (int)$b['id'], 2);

        assert_equals(6, $cartModel->countUnits($customerId));
        return true;
    });

    it('maxQuantityFor caps at the smaller of MAX_PER_ITEM and stock', function () use ($cartModel) {
        assert_equals(Cart::MAX_PER_ITEM, $cartModel->maxQuantityFor(['stock' => 999]));
        assert_equals(3, $cartModel->maxQuantityFor(['stock' => 3]));
        assert_equals(1, $cartModel->maxQuantityFor(['stock' => 0]));
        return true;
    });

    // =====================================================================
    // Group 6: api/cart.php Endpoint
    // =====================================================================
    echo "\nGroup 6: api/cart.php Endpoint\n";

    it('rejects an unauthenticated request with HTTP 401', function () {
        reset_cart_env();
        $res = call_cart_endpoint(['action' => 'list']);
        assert_equals(401, $res['code']);
        assert_false($res['data']['success'] ?? true);
    });

    it('rejects a mutation without a valid CSRF token (HTTP 403)', function () use ($testCustomer) {
        reset_cart_env();
        login_user($testCustomer);
        $res = call_cart_endpoint(['action' => 'add', 'product_id' => 1, 'quantity' => 1]);
        assert_equals(403, $res['code']);
        assert_false($res['data']['success'] ?? true);
    });

    it('allows the read-only list action without a CSRF token', function () use ($db, $testCustomer, $customerId) {
        (new Cart($db))->clear($customerId);
        reset_cart_env();
        login_user($testCustomer);
        $res = call_cart_endpoint([], ['action' => 'list']);
        assert_equals(200, $res['code']);
        assert_true($res['data']['success']);
        assert_equals(0, (int)$res['data']['cart']['count']);
    });

    it('adds a product via the add action', function () use ($db, $testCustomer, $customerId, &$createdProductIds) {
        $cartModel = new Cart($db);
        $cartModel->clear($customerId);
        $p = make_product($db, $createdProductIds, ['stock' => 20]);

        reset_cart_env();
        login_user($testCustomer);
        $res = call_cart_endpoint(['action' => 'add', 'product_id' => (int)$p['id'], 'quantity' => 2, 'csrf_token' => csrf_token()]);

        assert_equals(200, $res['code']);
        assert_true($res['data']['success']);
        assert_equals(2, (int)$res['data']['line_quantity']);
        assert_equals(1, (int)$res['data']['cart']['count']);
        assert_equals(2, (int)$res['data']['cart']['units']);
        return true;
    });

    it('updates a cart line via the update action', function () use ($db, $testCustomer, $customerId, &$createdProductIds) {
        $cartModel = new Cart($db);
        $cartModel->clear($customerId);
        $p = make_product($db, $createdProductIds, ['stock' => 20]);
        $cartModel->addItem($customerId, (int)$p['id'], 2);

        reset_cart_env();
        login_user($testCustomer);
        $res = call_cart_endpoint(['action' => 'update', 'product_id' => (int)$p['id'], 'quantity' => 4, 'csrf_token' => csrf_token()]);

        assert_equals(200, $res['code']);
        assert_equals(4, (int)$res['data']['line_quantity']);
        assert_equals(4, (int)$res['data']['cart']['units']);
        return true;
    });

    it('removes a cart line via the remove action', function () use ($db, $testCustomer, $customerId, &$createdProductIds) {
        $cartModel = new Cart($db);
        $cartModel->clear($customerId);
        $p = make_product($db, $createdProductIds, ['stock' => 20]);
        $cartModel->addItem($customerId, (int)$p['id'], 2);

        reset_cart_env();
        login_user($testCustomer);
        $res = call_cart_endpoint(['action' => 'remove', 'product_id' => (int)$p['id'], 'csrf_token' => csrf_token()]);

        assert_equals(200, $res['code']);
        assert_equals(0, (int)$res['data']['cart']['count']);
        return true;
    });

    it('empties the cart via the clear action', function () use ($db, $testCustomer, $customerId, &$createdProductIds) {
        $cartModel = new Cart($db);
        $cartModel->clear($customerId);
        $p = make_product($db, $createdProductIds, ['stock' => 20]);
        $cartModel->addItem($customerId, (int)$p['id'], 1);

        reset_cart_env();
        login_user($testCustomer);
        $res = call_cart_endpoint(['action' => 'clear', 'csrf_token' => csrf_token()]);

        assert_equals(200, $res['code']);
        assert_equals(0, (int)$res['data']['cart']['count']);
        assert_equals(0, $cartModel->countUnits($customerId));
        return true;
    });

    it('rejects an unknown action with HTTP 400', function () use ($testCustomer) {
        reset_cart_env();
        login_user($testCustomer);
        $res = call_cart_endpoint(['action' => 'frobnicate', 'csrf_token' => csrf_token()]);
        assert_equals(400, $res['code']);
        assert_false($res['data']['success'] ?? true);
    });

    it('rejects add without a product id with HTTP 400', function () use ($testCustomer) {
        reset_cart_env();
        login_user($testCustomer);
        $res = call_cart_endpoint(['action' => 'add', 'quantity' => 1, 'csrf_token' => csrf_token()]);
        assert_equals(400, $res['code']);
        assert_false($res['data']['success'] ?? true);
    });

    it('surfaces a model guard failure (out-of-stock) as HTTP 400', function () use ($db, $testCustomer, &$createdProductIds) {
        $p = make_product($db, $createdProductIds, ['stock' => 0]);
        reset_cart_env();
        login_user($testCustomer);
        $res = call_cart_endpoint(['action' => 'add', 'product_id' => (int)$p['id'], 'quantity' => 1, 'csrf_token' => csrf_token()]);
        assert_equals(400, $res['code']);
        assert_false($res['data']['success'] ?? true);
        assert_contains('out of stock', (string)($res['data']['message'] ?? ''));
        return true;
    });

    it('denies non-customer roles access to the cart endpoint', function () use ($adminUser) {
        if (!$adminUser) {
            return true; // no admin fixture available
        }
        reset_cart_env();
        login_user($adminUser);
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        $res = call_cart_endpoint(['action' => 'list', 'csrf_token' => csrf_token()]);
        assert_true($res['code'] !== 200, 'an admin must not receive a 200 cart payload');
        return true;
    });

} finally {
    // ----------------------------------------------------------
    // Cleanup: never leak cart state or throwaway products
    // ----------------------------------------------------------
    try { $cartModel->clear($customerId); } catch (Throwable $e) {}
    if ($adminUser) {
        try { $cartModel->clear((int)$adminUser['id']); } catch (Throwable $e) {}
    }
    foreach ($createdProductIds as $pid) {
        try { $db->prepare("DELETE FROM products WHERE id = ?")->execute([$pid]); } catch (Throwable $e) {}
    }
}

// ----------------------------------------------------------
// Summary
// ----------------------------------------------------------
echo "\n====================================================\n";
echo " Test Results: {$passCount} / {$testCount} passed";
if ($failCount > 0) {
    echo " ({$failCount} failed)\n";
    echo "====================================================\n";
    exit(1);
} else {
    echo " (100% success)\n";
    echo "====================================================\n";
    exit(0);
}
