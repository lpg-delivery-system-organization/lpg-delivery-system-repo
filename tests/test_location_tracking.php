<?php
/**
 * Live GPS Location Tracking Test Suite
 * LPG Delivery System v2
 *
 * Verifies the accuracy-aware rider tracking pipeline:
 * 1. RiderLocation::create() coordinate validation
 * 2. Id-ordered reads (a later insert wins even when its recorded_at is older)
 * 3. getBestByOrder() 3-tier resolution: recent+accurate -> most-accurate
 *    recent -> latest of any age, plus the derived age/accuracy flags
 * 4. api/location.php RBAC, CSRF and state guards on action=update
 * 5. api/location.php action=get tenant scoping & enriched payload
 *
 * Orders are created as "pending_payment" (no stock is reserved) and every row
 * is removed again in the finally block.
 */

$GLOBALS['TEST_MODE'] = true;

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Product.php';
require_once __DIR__ . '/../classes/Order.php';
require_once __DIR__ . '/../classes/RiderLocation.php';
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

function assert_null($actual, string $message = ''): bool {
    if ($actual !== null) {
        throw new Exception(($message ? $message . ': ' : '') . 'Expected null but got ' . var_export($actual, true));
    }
    return true;
}

function assert_contains(string $needle, string $haystack, string $message = ''): bool {
    if (strpos((string)$haystack, $needle) === false) {
        throw new Exception(($message ? $message . ': ' : '') . 'Expected to contain "' . $needle . '" but did not.');
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

// Execute an API endpoint in-process (json_response records under TEST_MODE)
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

    return ['code' => $code, 'data' => $response, 'raw' => $raw];
}

echo "====================================================\n";
echo " LPG Delivery System v2 - Live GPS Tracking Tests\n";
echo "====================================================\n\n";

$db = Database::connect();
$userModel = new User($db);
$orderModel = new Order($db);
$locationModel = new RiderLocation($db);

$locationApi = __DIR__ . '/../api/location.php';

$customer = $userModel->findByEmail('customer@lpg.com');
$customer2 = $userModel->findByEmail('customer2@lpg.com');
$rider = $userModel->findByEmail('rider@lpg.com');
$rider2 = $userModel->findByEmail('rider2@lpg.com');

if (!$customer || !$customer2 || !$rider || !$rider2) {
    fwrite(STDERR, "Seed users (customer@, customer2@, rider@, rider2@lpg.com) are required.\n");
    exit(1);
}

// Customers must clear ID verification to read tracking data.
$db->exec("UPDATE users SET id_verification_status = 'approved' WHERE id IN ("
    . (int)$customer['id'] . ',' . (int)$customer2['id'] . ")");

$productRow = $db->query("SELECT id FROM products WHERE status = 'active' AND stock >= 1 ORDER BY id ASC LIMIT 1")->fetch();
if (!$productRow) {
    fwrite(STDERR, "At least one active product with stock is required.\n");
    exit(1);
}
$productId = (int)$productRow['id'];

/** Create a location row with an explicit age (seconds ago) and accuracy. */
function insert_location(PDO $db, int $orderId, int $riderId, float $lat, float $lng, ?float $accuracy, int $ageSeconds): int {
    $stmt = $db->prepare("
        INSERT INTO rider_locations (order_id, rider_id, latitude, longitude, accuracy, recorded_at)
        VALUES (?, ?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL ? SECOND))
    ");
    $stmt->bindValue(1, $orderId, PDO::PARAM_INT);
    $stmt->bindValue(2, $riderId, PDO::PARAM_INT);
    $stmt->bindValue(3, $lat);
    $stmt->bindValue(4, $lng);
    if ($accuracy === null) {
        $stmt->bindValue(5, null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(5, $accuracy);
    }
    $stmt->bindValue(6, $ageSeconds, PDO::PARAM_INT);
    $stmt->execute();
    return (int)$db->lastInsertId();
}

$createdOrderIds = [];

/** Place a stock-neutral order, then force it into an assigned delivery state. */
$makeOrder = function (int $customerId, ?int $riderId, string $status) use ($orderModel, $db, $productId, &$createdOrderIds): int {
    $orderId = $orderModel->place([
        'customer_id'       => $customerId,
        'product_id'        => $productId,
        'quantity'          => 1,
        'payment_method'    => 'cod',
        'delivery_address'  => '999 GPS Tracking Test Ave',
        'delivery_latitude' => 14.5995,
        'delivery_longitude'=> 120.9842,
        'contact_phone'     => '09171234567',
        'status'            => 'pending_payment',
    ], false);
    $createdOrderIds[] = $orderId;

    $stmt = $db->prepare("UPDATE orders SET status = ?, rider_id = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$status, $riderId, $orderId]);
    return $orderId;
};
$GLOBALS['test_make_order'] = $makeOrder;

try {
    // ----------------------------------------------------------
    // 1. create() validation
    // ----------------------------------------------------------
    echo "--- 1. create() validation ---\n";

    $valOrder = $makeOrder((int)$customer['id'], (int)$rider['id'], 'picked_up');

    it("create() stores a valid fix and returns its id", function () use ($locationModel, $valOrder, $rider) {
        $id = $locationModel->create($valOrder, (int)$rider['id'], 14.5995, 120.9842, 18.5);
        assert_true($id > 0, "Location id must be positive");
        return true;
    });

    it("create() rejects an out-of-range latitude", function () use ($locationModel, $valOrder, $rider) {
        assert_throws_message('Invalid latitude', fn() => $locationModel->create($valOrder, (int)$rider['id'], 91.0, 120.0, 10.0));
        assert_throws_message('Invalid latitude', fn() => $locationModel->create($valOrder, (int)$rider['id'], -100.0, 120.0, 10.0));
        return true;
    });

    it("create() rejects an out-of-range longitude", function () use ($locationModel, $valOrder, $rider) {
        assert_throws_message('Invalid longitude', fn() => $locationModel->create($valOrder, (int)$rider['id'], 14.0, 181.0, 10.0));
        assert_throws_message('Invalid longitude', fn() => $locationModel->create($valOrder, (int)$rider['id'], 14.0, -181.0, 10.0));
        return true;
    });

    // ----------------------------------------------------------
    // 2. Id-ordered reads
    // ----------------------------------------------------------
    echo "\n--- 2. Id-ordered reads ---\n";

    $orderIdRead = $makeOrder((int)$customer['id'], (int)$rider['id'], 'out_for_delivery');
    $riderId = (int)$rider['id'];

    it("getLatest() returns the latest insert by id, not by recorded_at", function () use ($db, $locationModel, $orderIdRead, $riderId) {
        $older = insert_location($db, $orderIdRead, $riderId, 14.6000, 120.9800, 20.0, 60);
        $newerByInsert = insert_location($db, $orderIdRead, $riderId, 14.6010, 120.9810, 15.0, 300);
        $latest = $locationModel->getLatest($orderIdRead, $riderId);
        assert_equals($newerByInsert, (int)$latest['id'], 'Highest id must win despite the older timestamp');
        assert_equals(14.601, (float)$latest['latitude']);
        return true;
    });

    it("getLatestByOrder() orders by id descending", function () use ($db, $locationModel, $orderIdRead, $riderId) {
        $id = insert_location($db, $orderIdRead, $riderId, 14.6020, 120.9820, 12.0, 5);
        $latest = $locationModel->getLatestByOrder($orderIdRead);
        assert_equals($id, (int)$latest['id']);
        assert_true(isset($latest['rider_name']) && $latest['rider_name'] !== '', 'Rider name must be joined in');
        return true;
    });

    // ----------------------------------------------------------
    // 3. getBestByOrder() resolution tiers
    // ----------------------------------------------------------
    echo "\n--- 3. getBestByOrder() resolution ---\n";

    it("tier 1: prefers a fresh accurate fix over a newer inaccurate one", function () use ($db, $locationModel, $customer, $rider) {
        $orderId = $GLOBALS['test_make_order']((int)$customer['id'], (int)$rider['id'], 'out_for_delivery');
        $accurate = insert_location($db, $orderId, (int)$rider['id'], 14.6100, 120.9900, 20.0, 30);
        insert_location($db, $orderId, (int)$rider['id'], 14.7700, 120.9900, 500.0, 5);
        $best = $locationModel->getBestByOrder($orderId, 100, 180);
        assert_equals($accurate, (int)$best['id'], 'Fresh accurate fix must beat a newer coarse fix');
        assert_true($best['is_accurate']);
        assert_false($best['is_stale']);
        return true;
    });

    it("tier 2: falls back to the most accurate fresh fix when none meet the threshold", function () use ($db, $locationModel, $customer, $rider) {
        $orderId = $GLOBALS['test_make_order']((int)$customer['id'], (int)$rider['id'], 'out_for_delivery');
        insert_location($db, $orderId, (int)$rider['id'], 14.6200, 120.9900, 400.0, 10);
        $moreAccurate = insert_location($db, $orderId, (int)$rider['id'], 14.6201, 120.9901, 250.0, 60);
        $best = $locationModel->getBestByOrder($orderId, 100, 180);
        assert_equals($moreAccurate, (int)$best['id'], 'Most accurate fresh fix must win');
        assert_false($best['is_accurate'], '250 m exceeds the 100 m threshold');
        assert_false($best['is_stale']);
        return true;
    });

    it("tier 3: falls back to the latest fix of any age when all are stale", function () use ($db, $locationModel, $customer, $rider) {
        $orderId = $GLOBALS['test_make_order']((int)$customer['id'], (int)$rider['id'], 'out_for_delivery');
        insert_location($db, $orderId, (int)$rider['id'], 14.6300, 120.9900, 10.0, 1000);
        $latestStale = insert_location($db, $orderId, (int)$rider['id'], 14.6301, 120.9901, 12.0, 900);
        $best = $locationModel->getBestByOrder($orderId, 100, 180);
        assert_equals($latestStale, (int)$best['id'], 'Latest stale fix must be returned');
        assert_true($best['is_stale']);
        return true;
    });

    it("returns null when no fixes exist for the order", function () use ($locationModel, $customer, $rider) {
        $orderId = $GLOBALS['test_make_order']((int)$customer['id'], (int)$rider['id'], 'out_for_delivery');
        assert_null($locationModel->getBestByOrder($orderId, 100, 180));
        return true;
    });

    it("null-accuracy fixes are treated as inaccurate but still returned", function () use ($db, $locationModel, $customer, $rider) {
        $orderId = $GLOBALS['test_make_order']((int)$customer['id'], (int)$rider['id'], 'out_for_delivery');
        $id = insert_location($db, $orderId, (int)$rider['id'], 14.6400, 120.9900, null, 15);
        $best = $locationModel->getBestByOrder($orderId, 100, 180);
        assert_equals($id, (int)$best['id']);
        assert_null($best['accuracy']);
        assert_false($best['is_accurate']);
        assert_false($best['is_stale']);
        return true;
    });

    it("age_seconds reflects the row's recorded_at", function () use ($db, $locationModel, $customer, $rider) {
        $orderId = $GLOBALS['test_make_order']((int)$customer['id'], (int)$rider['id'], 'out_for_delivery');
        insert_location($db, $orderId, (int)$rider['id'], 14.6500, 120.9900, 15.0, 42);
        $best = $locationModel->getBestByOrder($orderId, 100, 180);
        assert_true($best['age_seconds'] >= 42 && $best['age_seconds'] <= 43, 'age_seconds ~42, got ' . $best['age_seconds']);
        return true;
    });

    // ----------------------------------------------------------
    // 4. api/location.php guards
    // ----------------------------------------------------------
    echo "\n--- 4. api/location.php guards ---\n";

    it("blocks unauthenticated reads with HTTP 401", function () use ($locationApi) {
        reset_api_env();
        $res = call_endpoint($locationApi, [], ['action' => 'get', 'order_id' => 1]);
        assert_equals(401, $res['code']);
        assert_false($res['data']['success'] ?? true);
        return true;
    });

    it("rejects an unknown action with HTTP 400", function () use ($locationApi, $customer) {
        reset_api_env();
        login_user($customer);
        $token = csrf_token();
        $res = call_endpoint($locationApi, ['action' => 'nonsense', 'csrf_token' => $token]);
        assert_equals(400, $res['code']);
        assert_contains('Invalid action', $res['data']['message'] ?? '');
        return true;
    });

    it("rejects action=get without an order_id with HTTP 400", function () use ($locationApi, $customer) {
        reset_api_env();
        login_user($customer);
        $res = call_endpoint($locationApi, [], ['action' => 'get']);
        assert_equals(400, $res['code']);
        return true;
    });

    it("customer can read their own order (empty payload before first fix)", function () use ($locationApi, $customer) {
        $orderId = $GLOBALS['test_make_order']((int)$customer['id'], null, 'approved');
        reset_api_env();
        login_user($customer);
        $res = call_endpoint($locationApi, [], ['action' => 'get', 'order_id' => $orderId]);
        assert_equals(200, $res['code']);
        assert_true($res['data']['success'] ?? false);
        assert_null($res['data']['data'], 'No fixes yet -> null data');
        return true;
    });

    it("customer cannot read another customer's order (HTTP 403)", function () use ($locationApi, $customer, $customer2, $rider) {
        $orderId = $GLOBALS['test_make_order']((int)$customer2['id'], (int)$rider['id'], 'out_for_delivery');
        reset_api_env();
        login_user($customer);
        $res = call_endpoint($locationApi, [], ['action' => 'get', 'order_id' => $orderId]);
        assert_equals(403, $res['code']);
        return true;
    });

    it("assigned rider can read the order with enriched flags", function () use ($locationApi, $db, $customer, $rider) {
        $orderId = $GLOBALS['test_make_order']((int)$customer['id'], (int)$rider['id'], 'out_for_delivery');
        insert_location($db, $orderId, (int)$rider['id'], 14.6600, 120.9900, 30.0, 10);
        reset_api_env();
        login_user($rider);
        $res = call_endpoint($locationApi, [], ['action' => 'get', 'order_id' => $orderId]);
        assert_equals(200, $res['code']);
        assert_true($res['data']['success'] ?? false);
        assert_true(isset($res['data']['data']['age_seconds']), 'age_seconds must be present');
        assert_true(isset($res['data']['data']['is_accurate']), 'is_accurate must be present');
        assert_true(isset($res['data']['data']['is_stale']), 'is_stale must be present');
        return true;
    });

    it("a rider not assigned to the order cannot read it (HTTP 403)", function () use ($locationApi, $customer, $rider, $rider2) {
        $orderId = $GLOBALS['test_make_order']((int)$customer['id'], (int)$rider['id'], 'out_for_delivery');
        reset_api_env();
        login_user($rider2);
        $res = call_endpoint($locationApi, [], ['action' => 'get', 'order_id' => $orderId]);
        assert_equals(403, $res['code']);
        return true;
    });

    // ----------------------------------------------------------
    // 5. api/location.php update
    // ----------------------------------------------------------
    echo "\n--- 5. api/location.php update ---\n";

    it("update requires a valid CSRF token (HTTP 403)", function () use ($locationApi, $customer, $rider) {
        $orderId = $GLOBALS['test_make_order']((int)$customer['id'], (int)$rider['id'], 'picked_up');
        reset_api_env();
        login_user($rider);
        $res = call_endpoint($locationApi, [
            'action' => 'update', 'order_id' => $orderId, 'latitude' => 14.6, 'longitude' => 121.0
        ]);
        assert_equals(403, $res['code']);
        assert_contains('CSRF', $res['data']['message'] ?? '');
        return true;
    });

    it("a customer cannot push GPS coordinates (HTTP 403)", function () use ($locationApi, $customer) {
        $orderId = $GLOBALS['test_make_order']((int)$customer['id'], null, 'approved');
        reset_api_env();
        login_user($customer);
        $token = csrf_token();
        $res = call_endpoint($locationApi, [
            'action' => 'update', 'order_id' => $orderId, 'latitude' => 14.6, 'longitude' => 121.0, 'csrf_token' => $token
        ]);
        assert_equals(403, $res['code']);
        assert_contains('Only riders', $res['data']['message'] ?? '');
        return true;
    });

    it("a rider cannot update an order they are not assigned to (HTTP 403)", function () use ($locationApi, $customer, $rider, $rider2) {
        $orderId = $GLOBALS['test_make_order']((int)$customer['id'], (int)$rider2['id'], 'picked_up');
        reset_api_env();
        login_user($rider);
        $token = csrf_token();
        $res = call_endpoint($locationApi, [
            'action' => 'update', 'order_id' => $orderId, 'latitude' => 14.6, 'longitude' => 121.0, 'csrf_token' => $token
        ]);
        assert_equals(403, $res['code']);
        assert_contains('Access denied', $res['data']['message'] ?? '');
        return true;
    });

    it("update rejects zero coordinates with HTTP 400", function () use ($locationApi, $customer, $rider) {
        $orderId = $GLOBALS['test_make_order']((int)$customer['id'], (int)$rider['id'], 'picked_up');
        reset_api_env();
        login_user($rider);
        $token = csrf_token();
        $res = call_endpoint($locationApi, [
            'action' => 'update', 'order_id' => $orderId, 'latitude' => 0, 'longitude' => 0, 'csrf_token' => $token
        ]);
        assert_equals(400, $res['code']);
        return true;
    });

    it("update is refused when the order is not out for delivery (HTTP 400)", function () use ($locationApi, $customer, $rider) {
        $orderId = $GLOBALS['test_make_order']((int)$customer['id'], (int)$rider['id'], 'approved');
        reset_api_env();
        login_user($rider);
        $token = csrf_token();
        $res = call_endpoint($locationApi, [
            'action' => 'update', 'order_id' => $orderId, 'latitude' => 14.6, 'longitude' => 121.0, 'csrf_token' => $token
        ]);
        assert_equals(400, $res['code']);
        assert_contains('picked up', $res['data']['message'] ?? '');
        return true;
    });

    it("a rider stores a valid fix and it is read back with its accuracy", function () use ($locationApi, $locationModel, $customer, $rider) {
        $orderId = $GLOBALS['test_make_order']((int)$customer['id'], (int)$rider['id'], 'picked_up');

        reset_api_env();
        login_user($rider);
        $token = csrf_token();
        $res = call_endpoint($locationApi, [
            'action'    => 'update',
            'order_id'  => $orderId,
            'latitude'  => 14.5995,
            'longitude' => 120.9842,
            'accuracy'  => 22.5,
            'csrf_token' => $token
        ]);
        assert_equals(200, $res['code']);
        assert_true($res['data']['success'] ?? false);
        assert_true(($res['data']['data']['location_id'] ?? 0) > 0);

        $best = $locationModel->getBestByOrder($orderId, 100, 180);
        assert_equals(22.5, $best['accuracy']);
        assert_true($best['is_accurate']);
        return true;
    });
} finally {
    foreach ($createdOrderIds as $orderId) {
        try { $db->prepare("DELETE FROM orders WHERE id = ?")->execute([$orderId]); } catch (Throwable $e) {}
    }
}

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
