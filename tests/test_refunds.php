<?php
/**
 * Refund (PayMongo) Automated Test Suite
 * LPG Delivery System v2
 *
 * Covers the refund-request lifecycle on paid online (GCash) orders:
 *   - requestRefund sets refund_status='requested' with a reason
 *   - requestRefund guards (already requested / non-gcash / unpaid)
 *   - rejectRefund sets refund_status='rejected'
 *   - a rejected request can be re-submitted
 *   - approveRefund returns controlled errors WITHOUT calling PayMongo
 *     when the order has no pending request or no captured payment id
 *
 * These tests deliberately do NOT call the live PayMongo /v1/refunds API —
 * the approveRefund happy path requires a real payment, so only its
 * pre-network guards are exercised here for determinism.
 */

// Enable test mode before components load
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
require_once __DIR__ . '/../classes/PayMongo.php';
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
        throw new Exception($message ?: 'Expected a non-empty value but it was empty.');
    }
    return true;
}

function assert_contains(string $needle, string $haystack, string $message = ''): bool {
    if (strpos($haystack, $needle) === false) {
        throw new Exception(
            ($message ? $message . ': ' : '') .
            "Expected to find '{$needle}' in '" . $haystack . "'"
        );
    }
    return true;
}

function assert_exception(callable $fn, string $expectedMsgPart): void {
    try {
        $fn();
        throw new Exception('Expected an exception but none was thrown.');
    } catch (Exception $e) {
        if ($e->getMessage() === 'Expected an exception but none was thrown.') {
            throw $e;
        }
        if ($expectedMsgPart !== '' && strpos($e->getMessage(), $expectedMsgPart) === false) {
            throw new Exception("Exception message did not contain '{$expectedMsgPart}': " . $e->getMessage());
        }
        return;
    }
}

echo "====================================================\n";
echo " LPG Delivery System v2 - Refund (PayMongo) Test Suite\n";
echo "====================================================\n\n";

$db = Database::connect();
$userModel = new User($db);
$productModel = new Product($db);
$orderModel = new Order($db);

$testCustomer = $userModel->findByEmail('customer@lpg.com');
assert_not_empty($testCustomer, 'Test customer must exist');
$customerId = (int)$testCustomer['id'];

// Helper: create a paid GCash order for the test customer.
$makePaidGcashOrder = function ($withPaymentId) use ($orderModel, $productModel, $customerId) {
    $products = $productModel->getActive();
    assert_not_empty($products, 'At least one active product required');
    $product = $products[0];
    $productId = (int)$product['id'];
    if ((int)$product['stock'] < 2) {
        $productModel->updateStock($productId, 10);
    }

    $orderId = $orderModel->place([
        'customer_id'        => $customerId,
        'product_id'         => $productId,
        'quantity'           => 1,
        'payment_method'     => 'gcash',
        'delivery_address'   => 'Refund Test St, Pasig City',
        'contact_phone'      => '09171234567',
        'delivery_latitude'  => '14.5600',
        'delivery_longitude' => '121.0800',
        'notes'              => '',
        'status'             => 'pending_payment',
    ]);

    $orderModel->confirmPayment($orderId, $withPaymentId ? 'pay_test_refund' : null);
    return $orderId;
};

echo "\n--- 1. Refund Request (customer) ---\n";

it('requestRefund sets refund_status=requested with reason on a paid GCash order', function () use ($makePaidGcashOrder, $orderModel) {
    $orderId = $makePaidGcashOrder(true);
    $ok = $orderModel->requestRefund($orderId, 'Customer no longer needs the order');
    assert_true($ok);
    $order = $orderModel->findById($orderId);
    assert_equals('requested', strtolower($order['refund_status']));
    assert_equals('Customer no longer needs the order', $order['refund_reason']);
    assert_true(!empty($order['refund_requested_at']), 'refund_requested_at should be set');
});

it('requestRefund rejects a duplicate (already requested) request', function () use ($makePaidGcashOrder, $orderModel) {
    $orderId = $makePaidGcashOrder(true);
    $orderModel->requestRefund($orderId, 'First request');
    assert_exception(function () use ($orderModel, $orderId) {
        $orderModel->requestRefund($orderId, 'Second request');
    }, 'already');
});

it('requestRefund rejects a COD order', function () use ($orderModel, $productModel, $customerId) {
    $products = $productModel->getActive();
    $product = $products[0];
    $orderId = $orderModel->place([
        'customer_id'        => $customerId,
        'product_id'         => (int)$product['id'],
        'quantity'           => 1,
        'payment_method'     => 'cod',
        'delivery_address'   => 'Refund Test St',
        'contact_phone'      => '09171234567',
        'delivery_latitude'  => '14.5600',
        'delivery_longitude' => '121.0800',
        'notes'              => '',
        'status'             => 'pending',
    ]);
    assert_exception(function () use ($orderModel, $orderId) {
        $orderModel->requestRefund($orderId, 'nope');
    }, 'online');
});

echo "\n--- 2. Reject / Re-submit ---\n";

it('rejectRefund sets refund_status=rejected', function () use ($makePaidGcashOrder, $orderModel) {
    $orderId = $makePaidGcashOrder(true);
    $orderModel->requestRefund($orderId, 'Please cancel');
    $ok = $orderModel->rejectRefund($orderId, 'Order already dispatched');
    assert_true($ok);
    $order = $orderModel->findById($orderId);
    assert_equals('rejected', strtolower($order['refund_status']));
});

it('a rejected refund can be requested again', function () use ($makePaidGcashOrder, $orderModel) {
    $orderId = $makePaidGcashOrder(true);
    $orderModel->requestRefund($orderId, 'First');
    $orderModel->rejectRefund($orderId, 'no');
    $ok = $orderModel->requestRefund($orderId, 'Second attempt');
    assert_true($ok);
    $order = $orderModel->findById($orderId);
    assert_equals('requested', strtolower($order['refund_status']));
    assert_equals('Second attempt', $order['refund_reason']);
});

echo "\n--- 3. Approve (guard paths, no live API) ---\n";

it('approveRefund returns a controlled error when there is no pending request', function () use ($makePaidGcashOrder, $orderModel) {
    $orderId = $makePaidGcashOrder(true);
    $result = $orderModel->approveRefund($orderId);
    assert_true(empty($result['refunded']));
    assert_contains('no pending refund request', $result['error'] ?? '');
});

it('approveRefund returns a controlled error when no payment_id is captured', function () use ($makePaidGcashOrder, $orderModel) {
    $orderId = $makePaidGcashOrder(false);
    $orderModel->requestRefund($orderId, 'cancel please');
    $result = $orderModel->approveRefund($orderId);
    assert_true(empty($result['refunded']));
    assert_contains('no captured payment', $result['error'] ?? '');
});

echo "\n====================================================\n";
echo " Test Results: {$passCount} / {$testCount} passed";
if ($failCount > 0) {
    echo " ({$failCount} failed)";
}
echo "\n====================================================\n";

exit($failCount > 0 ? 1 : 0);
