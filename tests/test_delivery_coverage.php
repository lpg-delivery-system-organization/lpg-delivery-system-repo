<?php
/**
 * Delivery Coverage (3 km geofence) Test Suite
 * LPG Delivery System v2
 *
 * Verifies:
 * 1. Haversine distance math (symmetry, latitude scale, known offsets)
 * 2. evaluate() coverage decisions including the exact 3,000 m boundary
 * 3. Invalid / missing coordinates are never treated as deliverable
 * 4. Human-readable distance formatting
 * 5. The TEST_MODE enforcement gate used by the automated harness
 * 6. Order::place() rejects out-of-coverage orders before touching inventory
 * 7. Order::place() still accepts in-coverage orders when enforced
 * 8. Legacy suites keep working (enforcement bypassed under TEST_MODE)
 *
 * Every order created here uses status "pending_payment" so product stock is
 * never decremented, and each row is deleted again in the finally block.
 */

// The whole suite runs under TEST_MODE; individual tests toggle
// ENFORCE_DELIVERY_COVERAGE to opt in/out of the geofence explicitly.
$GLOBALS['TEST_MODE'] = true;

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Product.php';
require_once __DIR__ . '/../classes/Order.php';
require_once __DIR__ . '/../classes/DeliveryCoverage.php';
require_once __DIR__ . '/../includes/helpers.php';

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

function assert_contains(string $needle, string $haystack, string $message = ''): bool {
    if (strpos($haystack, $needle) === false) {
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

echo "====================================================\n";
echo " LPG Delivery System v2 - Delivery Coverage Tests\n";
echo "====================================================\n\n";

$db = Database::connect();
$userModel = new User($db);
$orderModel = new Order($db);

$storeLat = DeliveryCoverage::storeLatitude();
$storeLng = DeliveryCoverage::storeLongitude();
$radius   = DeliveryCoverage::radiusMeters();

/**
 * Build a point $meters due north of the store (same longitude).
 * On a meridian the Haversine central angle equals the latitude delta, so the
 * resulting distance is exact (no projection approximation).
 */
function coverage_north_of(float $lat, float $lng, float $meters): array {
    return [$lat + rad2deg($meters / DeliveryCoverage::EARTH_RADIUS_METERS), $lng];
}

$createdOrderIds = [];

try {
    // ----------------------------------------------------------
    // 1. Haversine distance math
    // ----------------------------------------------------------
    echo "--- 1. Distance math ---\n";

    it("distanceMeters() is ~0 for two identical points", function() use ($storeLat, $storeLng) {
        $d = DeliveryCoverage::distanceMeters($storeLat, $storeLng, $storeLat, $storeLng);
        assert_true($d < 0.001, "Expected ~0 m, got {$d}");
        return true;
    });

    it("distanceMeters() scales ~111.19 km per degree of latitude", function() use ($storeLat, $storeLng) {
        $d = DeliveryCoverage::distanceMeters($storeLat, $storeLng, $storeLat + 1.0, $storeLng);
        assert_true(abs($d - 111194.93) < 5, "Expected ~111194.9 m, got {$d}");
        return true;
    });

    it("distanceMeters() is symmetric", function() use ($storeLat, $storeLng) {
        [$lat, $lng] = coverage_north_of($storeLat, $storeLng, 1500);
        $a = DeliveryCoverage::distanceMeters($storeLat, $storeLng, $lat, $lng);
        $b = DeliveryCoverage::distanceMeters($lat, $lng, $storeLat, $storeLng);
        assert_true(abs($a - $b) < 0.001, "Asymmetric: {$a} vs {$b}");
        return true;
    });

    it("distanceMeters() returns ~1000 m for a point 1000 m due north", function() use ($storeLat, $storeLng) {
        [$lat, $lng] = coverage_north_of($storeLat, $storeLng, 1000);
        $d = DeliveryCoverage::distanceMeters($storeLat, $storeLng, $lat, $lng);
        assert_true(abs($d - 1000) < 0.5, "Expected ~1000 m, got {$d}");
        return true;
    });

    it("calculate_distance_meters() helper delegates to DeliveryCoverage", function() use ($storeLat, $storeLng) {
        [$lat, $lng] = coverage_north_of($storeLat, $storeLng, 2500);
        $a = calculate_distance_meters($storeLat, $storeLng, $lat, $lng);
        $b = DeliveryCoverage::distanceMeters($storeLat, $storeLng, $lat, $lng);
        assert_true(abs($a - $b) < 0.001, "Wrapper mismatch: {$a} vs {$b}");
        return true;
    });

    // ----------------------------------------------------------
    // 2. evaluate() coverage decisions
    // ----------------------------------------------------------
    echo "\n--- 2. Coverage decisions ---\n";

    it("a point 1 km from the store is within coverage", function() use ($storeLat, $storeLng) {
        [$lat, $lng] = coverage_north_of($storeLat, $storeLng, 1000);
        $r = DeliveryCoverage::evaluate($lat, $lng);
        assert_true($r['valid'], "Coordinates should be valid");
        assert_true($r['within'], "1 km must be within 3 km");
        assert_equals(DeliveryCoverage::WITHIN_MESSAGE, $r['message']);
        return true;
    });

    it("a point 2.9 km from the store is within coverage", function() use ($storeLat, $storeLng) {
        [$lat, $lng] = coverage_north_of($storeLat, $storeLng, 2900);
        $r = DeliveryCoverage::evaluate($lat, $lng);
        assert_true($r['within'], "2.9 km must be within 3 km");
        return true;
    });

    it("the 3 km boundary is inclusive (within === (distance <= radius))", function() use ($storeLat, $storeLng) {
        // A synthetic point can drift past 3,000 m by a hair of float error, so
        // assert the rule itself rather than a hard-coded boolean.
        [$lat, $lng] = coverage_north_of($storeLat, $storeLng, 3000);
        $r = DeliveryCoverage::evaluate($lat, $lng);
        assert_true(abs($r['distance_meters'] - 3000) < 1, "Boundary distance should be ~3000 m, got {$r['distance_meters']}");
        assert_equals(
            $r['distance_meters'] <= DeliveryCoverage::radiusMeters(),
            $r['within'],
            "within must equal (distance <= radius)"
        );
        return true;
    });

    it("a point just inside the boundary (2999.5 m) is allowed", function() use ($storeLat, $storeLng) {
        [$lat, $lng] = coverage_north_of($storeLat, $storeLng, 2999.5);
        $r = DeliveryCoverage::evaluate($lat, $lng);
        assert_true($r['within'], "2999.5 m must be within 3 km");
        return true;
    });

    it("a point just outside the boundary (3000.5 m) is rejected", function() use ($storeLat, $storeLng) {
        [$lat, $lng] = coverage_north_of($storeLat, $storeLng, 3000.5);
        $r = DeliveryCoverage::evaluate($lat, $lng);
        assert_false($r['within'], "3000.5 m must be outside 3 km");
        return true;
    });

    it("a point 3.1 km from the store is rejected", function() use ($storeLat, $storeLng) {
        [$lat, $lng] = coverage_north_of($storeLat, $storeLng, 3100);
        $r = DeliveryCoverage::evaluate($lat, $lng);
        assert_false($r['within'], "3.1 km must be outside 3 km");
        assert_equals(DeliveryCoverage::OUT_OF_RANGE_MESSAGE, $r['message']);
        return true;
    });

    it("a point 5 km from the store is rejected", function() use ($storeLat, $storeLng) {
        [$lat, $lng] = coverage_north_of($storeLat, $storeLng, 5000);
        $r = DeliveryCoverage::evaluate($lat, $lng);
        assert_false($r['within'], "5 km must be outside 3 km");
        return true;
    });

    it("evaluate() reports the configured store, radius and limit", function() use ($storeLat, $storeLng, $radius) {
        $r = DeliveryCoverage::evaluate($storeLat, $storeLng);
        assert_equals($storeLat, $r['store_latitude']);
        assert_equals($storeLng, $r['store_longitude']);
        assert_equals($radius, $r['radius_meters']);
        assert_equals(3.0, $r['limit_km']);
        return true;
    });

    // ----------------------------------------------------------
    // 3. Missing / invalid coordinates
    // ----------------------------------------------------------
    echo "\n--- 3. Missing / invalid coordinates ---\n";

    it("null coordinates are invalid and never deliverable", function() {
        $r = DeliveryCoverage::evaluate(null, null);
        assert_false($r['valid']);
        assert_false($r['within']);
        assert_equals(null, $r['distance_meters']);
        assert_equals(DeliveryCoverage::MISSING_LOCATION_MESSAGE, $r['message']);
        return true;
    });

    it("empty-string coordinates are invalid", function() {
        assert_false(DeliveryCoverage::coordinatesAreValid('', ''));
        assert_false(DeliveryCoverage::evaluate('', '')['within']);
        return true;
    });

    it("non-numeric coordinates are invalid", function() {
        assert_false(DeliveryCoverage::coordinatesAreValid('abc', 'def'));
        assert_false(DeliveryCoverage::evaluate('abc', 'def')['within']);
        return true;
    });

    it("out-of-range coordinates are invalid", function() {
        assert_false(DeliveryCoverage::coordinatesAreValid(91, 0), "Latitude 91 is invalid");
        assert_false(DeliveryCoverage::coordinatesAreValid(0, 181), "Longitude 181 is invalid");
        assert_false(DeliveryCoverage::evaluate(91, 181)['within']);
        return true;
    });

    // ----------------------------------------------------------
    // 4. Distance formatting
    // ----------------------------------------------------------
    echo "\n--- 4. Distance formatting ---\n";

    it("formatDistance() uses metres below 1 km", function() {
        assert_equals('0 m', DeliveryCoverage::formatDistance(0));
        assert_equals('850 m', DeliveryCoverage::formatDistance(850));
        assert_equals('999 m', DeliveryCoverage::formatDistance(999.4));
        return true;
    });

    it("formatDistance() uses km at/above 1 km", function() {
        assert_equals('1.00 km', DeliveryCoverage::formatDistance(1000));
        assert_equals('2.94 km', DeliveryCoverage::formatDistance(2940));
        assert_equals('3.00 km', DeliveryCoverage::formatDistance(3000));
        return true;
    });

    // ----------------------------------------------------------
    // 5. Enforcement gate
    // ----------------------------------------------------------
    echo "\n--- 5. Enforcement gate ---\n";

    it("isEnforced() is bypassed under TEST_MODE by default", function() {
        $GLOBALS['TEST_MODE'] = true;
        unset($GLOBALS['ENFORCE_DELIVERY_COVERAGE']);
        assert_false(DeliveryCoverage::isEnforced());
        return true;
    });

    it("isEnforced() can be re-enabled under TEST_MODE", function() {
        $GLOBALS['TEST_MODE'] = true;
        $GLOBALS['ENFORCE_DELIVERY_COVERAGE'] = true;
        assert_true(DeliveryCoverage::isEnforced());
        return true;
    });

    it("isEnforced() is always true outside TEST_MODE (production)", function() {
        $savedTest = $GLOBALS['TEST_MODE'];
        $savedEnforce = $GLOBALS['ENFORCE_DELIVERY_COVERAGE'] ?? null;

        $GLOBALS['TEST_MODE'] = false;
        unset($GLOBALS['ENFORCE_DELIVERY_COVERAGE']);
        assert_true(DeliveryCoverage::isEnforced());

        $GLOBALS['TEST_MODE'] = $savedTest;
        if ($savedEnforce === null) {
            unset($GLOBALS['ENFORCE_DELIVERY_COVERAGE']);
        } else {
            $GLOBALS['ENFORCE_DELIVERY_COVERAGE'] = $savedEnforce;
        }
        return true;
    });

    // ----------------------------------------------------------
    // 6. Order::place() enforcement
    // ----------------------------------------------------------
    echo "\n--- 6. Order::place() enforcement ---\n";

    $customer = $userModel->findByEmail('customer@lpg.com');
    if (!$customer) {
        throw new RuntimeException('Seed customer customer@lpg.com is required for the place() tests.');
    }
    $customerId = (int)$customer['id'];

    $productRow = $db->query("SELECT id FROM products WHERE status = 'active' AND stock >= 1 ORDER BY id ASC LIMIT 1")->fetch();
    if (!$productRow) {
        throw new RuntimeException('At least one active product with stock is required for the place() tests.');
    }
    $productId = (int)$productRow['id'];

    $placeOrder = function (array $overrides = []) use ($orderModel, $customerId, $productId) {
        return $orderModel->place(array_merge([
            'customer_id'      => $customerId,
            'product_id'       => $productId,
            'quantity'         => 1,
            'payment_method'   => 'cod',
            'delivery_address' => '123 In-Coverage Test Street',
            'contact_phone'    => '09171234567',
            'status'           => 'pending_payment',
        ], $overrides), false);
    };

    it("place() rejects an out-of-coverage address and creates no order", function() use ($placeOrder, $db, $customerId) {
        $GLOBALS['ENFORCE_DELIVERY_COVERAGE'] = true;

        $before = (int)$db->query("SELECT COUNT(*) FROM orders WHERE customer_id = {$customerId}")->fetchColumn();

        assert_throws_message(
            'outside our 3 km delivery coverage',
            fn() => $placeOrder(['delivery_latitude' => 14.5600, 'delivery_longitude' => 121.0800]),
            'Far coordinates must be rejected'
        );

        $after = (int)$db->query("SELECT COUNT(*) FROM orders WHERE customer_id = {$customerId}")->fetchColumn();
        assert_equals($before, $after, "No order row may be created for a rejected location");

        unset($GLOBALS['ENFORCE_DELIVERY_COVERAGE']);
        return true;
    });

    it("place() accepts an in-coverage address when enforced", function() use ($placeOrder, &$createdOrderIds, $storeLat, $storeLng) {
        $GLOBALS['ENFORCE_DELIVERY_COVERAGE'] = true;

        [$lat, $lng] = coverage_north_of($storeLat, $storeLng, 1200);
        $orderId = $placeOrder(['delivery_latitude' => $lat, 'delivery_longitude' => $lng]);
        $createdOrderIds[] = $orderId;

        assert_true($orderId > 0, "In-coverage order must be created");

        unset($GLOBALS['ENFORCE_DELIVERY_COVERAGE']);
        return true;
    });

    it("place() accepts a location just inside the 3 km boundary", function() use ($placeOrder, &$createdOrderIds, $storeLat, $storeLng) {
        $GLOBALS['ENFORCE_DELIVERY_COVERAGE'] = true;

        [$lat, $lng] = coverage_north_of($storeLat, $storeLng, 2999);
        $orderId = $placeOrder(['delivery_latitude' => $lat, 'delivery_longitude' => $lng]);
        $createdOrderIds[] = $orderId;

        assert_true($orderId > 0, "Near-boundary order must be created");

        unset($GLOBALS['ENFORCE_DELIVERY_COVERAGE']);
        return true;
    });

    it("place() skips the geofence for legacy suites under TEST_MODE", function() use ($placeOrder, &$createdOrderIds) {
        $GLOBALS['TEST_MODE'] = true;
        unset($GLOBALS['ENFORCE_DELIVERY_COVERAGE']);

        $orderId = $placeOrder(['delivery_latitude' => 14.5600, 'delivery_longitude' => 121.0800]);
        $createdOrderIds[] = $orderId;

        assert_true($orderId > 0, "Legacy bypass must still allow far coordinates");
        return true;
    });
} finally {
    // ----------------------------------------------------------
    // Cleanup
    // ----------------------------------------------------------
    foreach ($createdOrderIds as $orderId) {
        try { $db->prepare("DELETE FROM orders WHERE id = ?")->execute([$orderId]); } catch (Throwable $e) {}
    }
    unset($GLOBALS['ENFORCE_DELIVERY_COVERAGE']);
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
