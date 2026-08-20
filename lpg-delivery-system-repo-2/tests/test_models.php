<?php
/**
 * Automated Model Layer Test Suite
 * LPG Delivery System v2
 *
 * Tests:
 * 1. User model (CRUD, bcrypt cost 12, email check, status updates, role queries, password resets)
 * 2. Product model (listings, findById, findByIdForUpdate, decrementStock, updateStock, updatePrice, toggleStatus)
 * 3. Order model (transactional place(), price snapshot, concurrency-safe assignRider(), state machine transitions, cancel with restock, dashboard stats)
 * 4. Mailer class (mock mode, sendResetEmail, HTML/plaintext template output, sent logging)
 */

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
        echo "  ✗ {$description} (Exception: {$e->getMessage()})\n";
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

echo "====================================================\n";
echo " LPG Delivery System v2 - Model Layer Unit Tests\n";
echo "====================================================\n\n";

// Require dependencies
require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/classes/Database.php';
require_once dirname(__DIR__) . '/classes/User.php';
require_once dirname(__DIR__) . '/classes/Product.php';
require_once dirname(__DIR__) . '/classes/Order.php';
require_once dirname(__DIR__) . '/classes/Mailer.php';

$pdo = Database::connect();

// ----------------------------------------------------------
// 1. User Model Tests
// ----------------------------------------------------------
echo "--- 1. User Model Tests ---\n";

$userModel = new User($pdo);

it("User::findByEmail finds seed accounts", function() use ($userModel) {
    $admin = $userModel->findByEmail('admin@lpg.com');
    assert_true($admin !== null, "Admin must be found");
    assert_equals('admin', $admin['role']);
    assert_equals('Maria Santos', $admin['full_name']);

    $rider = $userModel->findByEmail('rider@lpg.com');
    assert_true($rider !== null, "Rider must be found");
    assert_equals('rider', $rider['role']);

    $cust = $userModel->findByEmail('customer@lpg.com');
    assert_true($cust !== null, "Customer must be found");
    assert_equals('customer', $cust['role']);

    $nonExistent = $userModel->findByEmail('nobody@example.com');
    assert_equals(null, $nonExistent, "Non-existent user must return null");

    return true;
});

it("User::findById retrieves user by ID", function() use ($userModel) {
    $user = $userModel->findById(1);
    assert_true($user !== null, "User #1 must exist");
    assert_equals('admin@lpg.com', $user['email']);

    $user999 = $userModel->findById(99999);
    assert_equals(null, $user999, "Non-existent ID must return null");
    return true;
});

it("User::emailExists checks for duplicates accurately", function() use ($userModel) {
    assert_true($userModel->emailExists('admin@lpg.com'), "admin@lpg.com must exist");
    assert_true($userModel->emailExists('ADMIN@LPG.COM'), "Email check must be case-insensitive");
    assert_false($userModel->emailExists('unknown_' . uniqid() . '@example.com'), "Unique email must not exist");

    // Test with excludeUserId
    assert_false($userModel->emailExists('admin@lpg.com', 1), "admin@lpg.com excluding user #1 must return false");
    assert_true($userModel->emailExists('admin@lpg.com', 2), "admin@lpg.com excluding user #2 must return true");
    return true;
});

$testUserEmail = 'test_user_' . time() . '@example.com';
$createdUserId = 0;

it("User::create hashes password with Bcrypt cost 12 and inserts user", function() use ($userModel, $testUserEmail, &$createdUserId) {
    $createdUserId = $userModel->create([
        'full_name' => 'Test Unit User',
        'email' => $testUserEmail,
        'password' => 'SecretPass@2026',
        'role' => 'customer',
        'phone' => '09181234567',
        'address' => '789 Test Avenue, Manila',
        'status' => 'active'
    ]);

    assert_true($createdUserId > 0, "Created user ID must be positive integer");

    $user = $userModel->findById($createdUserId);
    assert_true($user !== null, "Created user must be found in DB");
    assert_equals('Test Unit User', $user['full_name']);
    assert_equals($testUserEmail, $user['email']);
    assert_equals('customer', $user['role']);
    assert_equals('09181234567', $user['phone']);
    assert_equals('active', $user['status']);

    // Verify Bcrypt cost 12
    assert_true(password_verify('SecretPass@2026', $user['password']), "Password verification must succeed");
    $info = password_get_info($user['password']);
    assert_equals(PASSWORD_BCRYPT, $info['algo'], "Password algorithm must be BCRYPT");
    assert_equals(12, $info['options']['cost'], "Bcrypt cost factor must be 12");

    return true;
});

it("User::create rejects duplicate email", function() use ($userModel, $testUserEmail) {
    $threw = false;
    try {
        $userModel->create([
            'full_name' => 'Duplicate Email User',
            'email' => $testUserEmail,
            'password' => 'AnotherPass@2026',
            'role' => 'customer',
            'phone' => '09189999999',
            'address' => 'Test',
        ]);
    } catch (RuntimeException $e) {
        $threw = true;
    }
    assert_true($threw, "Must throw RuntimeException on duplicate email registration");
    return true;
});

it("User::updatePassword updates password with Bcrypt cost 12", function() use ($userModel, &$createdUserId) {
    $updated = $userModel->updatePassword($createdUserId, 'NewSecret@2026!');
    assert_true($updated, "Password update must succeed");

    $user = $userModel->findById($createdUserId);
    assert_true(password_verify('NewSecret@2026!', $user['password']), "New password must verify");
    assert_false(password_verify('SecretPass@2026', $user['password']), "Old password must fail");

    $info = password_get_info($user['password']);
    assert_equals(12, $info['options']['cost'], "Bcrypt cost must remain 12");
    return true;
});

it("User::updateProfile modifies profile fields", function() use ($userModel, &$createdUserId) {
    $updated = $userModel->updateProfile($createdUserId, [
        'full_name' => 'Updated User Name',
        'phone' => '09199998888',
        'address' => '999 New Street, Quezon City'
    ]);
    assert_true($updated, "updateProfile must return true");

    $user = $userModel->findById($createdUserId);
    assert_equals('Updated User Name', $user['full_name']);
    assert_equals('09199998888', $user['phone']);
    assert_equals('999 New Street, Quezon City', $user['address']);
    return true;
});

it("User::updateStatus modifies account status and rejects invalid values", function() use ($userModel, &$createdUserId) {
    $userModel->updateStatus($createdUserId, 'suspended');
    $user = $userModel->findById($createdUserId);
    assert_equals('suspended', $user['status']);

    $userModel->updateStatus($createdUserId, 'active');
    $user = $userModel->findById($createdUserId);
    assert_equals('active', $user['status']);

    $threw = false;
    try {
        $userModel->updateStatus($createdUserId, 'banned');
    } catch (InvalidArgumentException $e) {
        $threw = true;
    }
    assert_true($threw, "Must reject invalid status");
    return true;
});

it("User::getAllByRole and User::countByRole work accurately", function() use ($userModel) {
    $customers = $userModel->getAllByRole('customer');
    $customerCount = $userModel->countByRole('customer');
    assert_equals(count($customers), $customerCount, "Count must match fetched array count");
    assert_true($customerCount >= 1, "At least 1 customer must exist");

    $riders = $userModel->getAllByRole('rider');
    $riderCount = $userModel->countByRole('rider');
    assert_equals(count($riders), $riderCount);
    assert_true($riderCount >= 1, "At least 1 rider must exist");

    $admins = $userModel->getAllByRole('admin');
    $adminCount = $userModel->countByRole('admin');
    assert_equals(count($admins), $adminCount);
    assert_true($adminCount >= 1, "At least 1 admin must exist");

    return true;
});

it("User password reset token lifecycle", function() use ($userModel, &$createdUserId) {
    $token = bin2hex(random_bytes(32));
    $inserted = $userModel->createPasswordResetToken($createdUserId, $token, 30);
    assert_true($inserted, "createPasswordResetToken must return true");

    $verified = $userModel->verifyPasswordResetToken($token);
    assert_true($verified !== null, "Token must verify successfully");
    assert_equals($createdUserId, (int)$verified['user_id']);

    $marked = $userModel->markPasswordResetUsed($token);
    assert_true($marked, "markPasswordResetUsed must return true");

    $verifyAgain = $userModel->verifyPasswordResetToken($token);
    assert_equals(null, $verifyAgain, "Used token must no longer verify");
    return true;
});

// ----------------------------------------------------------
// 2. Product Model Tests
// ----------------------------------------------------------
echo "\n--- 2. Product Model Tests ---\n";

$productModel = new Product($pdo);

it("Product::getAll returns all seed products", function() use ($productModel) {
    $products = $productModel->getAll();
    assert_true(count($products) >= 5, "Must return at least 5 products");
    return true;
});

it("Product::getActive returns only active products", function() use ($productModel) {
    $activeProducts = $productModel->getActive();
    assert_true(count($activeProducts) > 0, "Must have active products");
    foreach ($activeProducts as $prod) {
        assert_equals('active', $prod['status'], "All returned products must be active");
    }
    return true;
});

it("Product::findById retrieves product by ID", function() use ($productModel) {
    $solane = $productModel->findById(1);
    assert_true($solane !== null, "Product #1 must exist");
    assert_equals('Solane 11kg', $solane['name']);
    assert_equals('Solane', $solane['brand']);
    assert_equals('850.00', $solane['price']);

    $nullProd = $productModel->findById(99999);
    assert_equals(null, $nullProd, "Non-existent product ID must return null");
    return true;
});

it("Product::findByIdForUpdate works inside transaction", function() use ($productModel, $pdo) {
    $pdo->beginTransaction();
    $prod = $productModel->findByIdForUpdate(1);
    assert_true($prod !== null, "Product #1 locked for update");
    assert_equals('Solane 11kg', $prod['name']);
    $pdo->commit();
    return true;
});

$testProductId = 0;
it("Product::create and Product::update", function() use ($productModel, &$testProductId) {
    $testProductId = $productModel->create([
        'name' => 'Phoenix 11kg Test',
        'brand' => 'Phoenix',
        'weight' => '11kg',
        'price' => 790.00,
        'stock' => 25,
        'image_url' => 'assets/img/products/phoenix-11kg.png',
        'status' => 'active'
    ]);

    assert_true($testProductId > 0, "Created product ID must be positive integer");
    $created = $productModel->findById($testProductId);
    assert_equals('Phoenix 11kg Test', $created['name']);
    assert_equals('790.00', $created['price']);
    assert_equals(25, (int)$created['stock']);

    $updated = $productModel->update($testProductId, [
        'name' => 'Phoenix 11kg Super Test',
        'price' => 810.00,
        'stock' => 30
    ]);
    assert_true($updated, "Product update must succeed");

    $refreshed = $productModel->findById($testProductId);
    assert_equals('Phoenix 11kg Super Test', $refreshed['name']);
    assert_equals('810.00', $refreshed['price']);
    assert_equals(30, (int)$refreshed['stock']);
    return true;
});

it("Product::updateStock modifies stock", function() use ($productModel, &$testProductId) {
    $productModel->updateStock($testProductId, 50);
    $prod = $productModel->findById($testProductId);
    assert_equals(50, (int)$prod['stock']);
    return true;
});

it("Product::decrementStock decrements stock safely and rejects overdrawing", function() use ($productModel, &$testProductId) {
    // Current stock is 50
    $success = $productModel->decrementStock($testProductId, 10);
    assert_true($success, "Decrementing 10 from 50 must succeed");

    $prod = $productModel->findById($testProductId);
    assert_equals(40, (int)$prod['stock']);

    // Attempt to overdraw 100 from 40
    $overdraw = $productModel->decrementStock($testProductId, 100);
    assert_false($overdraw, "Overdrawing stock must return false");

    $prod = $productModel->findById($testProductId);
    assert_equals(40, (int)$prod['stock'], "Stock must remain unchanged on failed decrement");
    return true;
});

it("Product::incrementStock increases stock", function() use ($productModel, &$testProductId) {
    $productModel->incrementStock($testProductId, 5);
    $prod = $productModel->findById($testProductId);
    assert_equals(45, (int)$prod['stock']);
    return true;
});

it("Product::updatePrice changes price", function() use ($productModel, &$testProductId) {
    $productModel->updatePrice($testProductId, 835.50);
    $prod = $productModel->findById($testProductId);
    assert_equals('835.50', $prod['price']);
    return true;
});

it("Product::toggleStatus switches active and inactive status", function() use ($productModel, &$testProductId) {
    // Current status is active
    $productModel->toggleStatus($testProductId);
    $prod = $productModel->findById($testProductId);
    assert_equals('inactive', $prod['status']);

    $productModel->toggleStatus($testProductId);
    $prod = $productModel->findById($testProductId);
    assert_equals('active', $prod['status']);
    return true;
});

// ----------------------------------------------------------
// 3. Order Model Tests
// ----------------------------------------------------------
echo "\n--- 3. Order Model Tests ---\n";

$orderModel = new Order($pdo);

$placedOrderId = 0;
it("Order::place creates order with transactional row locking & price snapshot", function() use ($orderModel, $productModel, &$testProductId, &$createdUserId, &$placedOrderId) {
    // Current product stock is 45, price is 835.50
    $initialStock = (int)$productModel->findById($testProductId)['stock'];

    $placedOrderId = $orderModel->place([
        'customer_id' => $createdUserId,
        'product_id' => $testProductId,
        'quantity' => 2,
        'payment_method' => 'cod',
        'delivery_address' => '123 Testing Road, District 1',
        'contact_phone' => '09199998888',
        'notes' => 'Handle with care',
        'status' => 'pending'
    ]);

    assert_true($placedOrderId > 0, "Placed order ID must be positive integer");

    // Verify order in database
    $order = $orderModel->findById($placedOrderId);
    assert_true($order !== null, "Order must be found");
    assert_equals($createdUserId, (int)$order['customer_id']);
    assert_equals($testProductId, (int)$order['product_id']);
    assert_equals(2, (int)$order['quantity']);
    assert_equals('835.50', $order['unit_price'], "Unit price must be snapshotted");
    assert_equals('1671.00', $order['total_amount'], "Total amount must be 2 * 835.50 = 1671.00");
    assert_equals('pending', $order['status']);
    assert_equals('cod', $order['payment_method']);
    assert_equals('123 Testing Road, District 1', $order['delivery_address']);
    assert_equals('09199998888', $order['contact_phone']);
    assert_equals('Handle with care', $order['notes']);

    // Check customer name joined
    assert_equals('Updated User Name', $order['customer_name']);
    assert_equals('Phoenix 11kg Super Test', $order['product_name']);

    // Verify product stock was decremented by 2
    $newStock = (int)$productModel->findById($testProductId)['stock'];
    assert_equals($initialStock - 2, $newStock, "Stock must decrease by ordered quantity (2)");

    return true;
});

it("Order::place rolls back transaction when quantity exceeds available stock", function() use ($orderModel, $productModel, &$testProductId, &$createdUserId) {
    $stockBefore = (int)$productModel->findById($testProductId)['stock'];

    $threw = false;
    try {
        $orderModel->place([
            'customer_id' => $createdUserId,
            'product_id' => $testProductId,
            'quantity' => 99999, // Exceeds stock
            'payment_method' => 'cod',
            'delivery_address' => '123 Testing Road',
            'contact_phone' => '09199998888'
        ]);
    } catch (RuntimeException $e) {
        $threw = true;
    }

    assert_true($threw, "Must throw RuntimeException when quantity > stock");

    $stockAfter = (int)$productModel->findById($testProductId)['stock'];
    assert_equals($stockBefore, $stockAfter, "Stock must remain unchanged after rollback");
    return true;
});

it("Order::getByCustomer returns customer orders", function() use ($orderModel, &$createdUserId, &$placedOrderId) {
    $orders = $orderModel->getByCustomer($createdUserId);
    assert_true(count($orders) >= 1, "Must return at least 1 order for created customer");
    assert_equals($placedOrderId, (int)$orders[0]['id']);
    return true;
});

it("Order::getAvailableForRider returns orders in approved or ready_for_delivery status", function() use ($orderModel, &$placedOrderId) {
    // Current status is 'pending', so it should NOT appear in getAvailableForRider
    $available = $orderModel->getAvailableForRider();
    $availableIds = array_column($available, 'id');
    assert_false(in_array($placedOrderId, $availableIds), "Pending order must not be available for rider claiming");

    // Admin approves order
    $orderModel->updateStatus($placedOrderId, 'approved');
    $availableNow = $orderModel->getAvailableForRider();
    $availableIdsNow = array_column($availableNow, 'id');
    assert_true(in_array($placedOrderId, $availableIdsNow), "Approved order without rider must be available");

    return true;
});

it("Order::assignRider is concurrency-safe and claims order", function() use ($orderModel, &$placedOrderId) {
    $riderId = 2; // Seed rider: Pedro Reyes

    // First claim succeeds
    $claimed = $orderModel->assignRider($placedOrderId, $riderId, 'picked_up');
    assert_true($claimed, "First assignRider claim must return true");

    $order = $orderModel->findById($placedOrderId);
    assert_equals($riderId, (int)$order['rider_id']);
    assert_equals('picked_up', $order['status']);
    assert_equals('Pedro Reyes', $order['rider_name']);

    // Second claim by another rider on same order must fail (concurrency race check)
    $secondClaim = $orderModel->assignRider($placedOrderId, 999, 'picked_up');
    assert_false($secondClaim, "Subsequent claim on already-assigned order must return false");

    return true;
});

it("Order::getByRider returns orders assigned to rider", function() use ($orderModel, &$placedOrderId) {
    $riderOrders = $orderModel->getByRider(2);
    $riderOrderIds = array_column($riderOrders, 'id');
    assert_true(in_array($placedOrderId, $riderOrderIds), "Assigned order must appear in rider orders");
    return true;
});

it("Order::updateStatus enforces state machine transitions", function() use ($orderModel, &$placedOrderId) {
    // Current status is 'picked_up'
    // Valid: picked_up -> out_for_delivery
    $success = $orderModel->updateStatus($placedOrderId, 'out_for_delivery');
    assert_true($success, "picked_up -> out_for_delivery must succeed");

    $order = $orderModel->findById($placedOrderId);
    assert_equals('out_for_delivery', $order['status']);

    // Invalid: out_for_delivery -> pending (backward illegal transition)
    $threw = false;
    try {
        $orderModel->updateStatus($placedOrderId, 'pending');
    } catch (InvalidArgumentException $e) {
        $threw = true;
    }
    assert_true($threw, "out_for_delivery -> pending must throw InvalidArgumentException");

    // Invalid: out_for_delivery -> approved
    $threwApproved = false;
    try {
        $orderModel->updateStatus($placedOrderId, 'approved');
    } catch (InvalidArgumentException $e) {
        $threwApproved = true;
    }
    assert_true($threwApproved, "out_for_delivery -> approved must throw InvalidArgumentException");

    // Valid: out_for_delivery -> delivered
    $delivered = $orderModel->updateStatus($placedOrderId, 'delivered');
    assert_true($delivered, "out_for_delivery -> delivered must succeed");

    $orderDelivered = $orderModel->findById($placedOrderId);
    assert_equals('delivered', $orderDelivered['status']);
    assert_true(!empty($orderDelivered['delivered_at']), "delivered_at timestamp must be set upon delivery");

    // Terminal state: delivered -> cancelled must fail
    $threwDeliveredCancel = false;
    try {
        $orderModel->updateStatus($placedOrderId, 'cancelled');
    } catch (InvalidArgumentException $e) {
        $threwDeliveredCancel = true;
    }
    assert_true($threwDeliveredCancel, "delivered -> cancelled must fail");

    return true;
});

it("Order::cancel restores product stock in transaction", function() use ($orderModel, $productModel, &$testProductId, &$createdUserId) {
    $stockBefore = (int)$productModel->findById($testProductId)['stock'];

    // Place a new pending order of 3 units
    $orderToCancelId = $orderModel->place([
        'customer_id' => $createdUserId,
        'product_id' => $testProductId,
        'quantity' => 3,
        'payment_method' => 'cod',
        'delivery_address' => 'Cancel Street 101',
        'contact_phone' => '09199998888'
    ]);

    $stockAfterPlace = (int)$productModel->findById($testProductId)['stock'];
    assert_equals($stockBefore - 3, $stockAfterPlace, "Stock deducted by 3");

    // Cancel the pending order
    $cancelled = $orderModel->cancel($orderToCancelId, "Customer requested cancellation");
    assert_true($cancelled, "Order cancellation must succeed");

    $order = $orderModel->findById($orderToCancelId);
    assert_equals('cancelled', $order['status']);
    assert_true(strpos($order['notes'], 'Customer requested cancellation') !== false);

    // Stock must be restored
    $stockAfterCancel = (int)$productModel->findById($testProductId)['stock'];
    assert_equals($stockBefore, $stockAfterCancel, "Stock must be fully restored upon cancellation");

    return true;
});

it("Order::getAll supports filtering by status", function() use ($orderModel) {
    $allOrders = $orderModel->getAll();
    assert_true(count($allOrders) >= 3, "At least 3 orders must exist");

    $deliveredOrders = $orderModel->getAll('delivered');
    assert_true(count($deliveredOrders) >= 1, "At least 1 delivered order exists");
    foreach ($deliveredOrders as $o) {
        assert_equals('delivered', $o['status']);
    }
    return true;
});

it("Order::getDashboardStats returns accurate aggregations", function() use ($orderModel) {
    $stats = $orderModel->getDashboardStats();

    assert_true(isset($stats['total_orders']), "Must have total_orders");
    assert_true(isset($stats['pending_orders']), "Must have pending_orders");
    assert_true(isset($stats['in_transit_orders']), "Must have in_transit_orders");
    assert_true(isset($stats['delivered_orders']), "Must have delivered_orders");
    assert_true(isset($stats['cancelled_orders']), "Must have cancelled_orders");
    assert_true(isset($stats['total_revenue']), "Must have total_revenue");
    assert_true(isset($stats['recent_orders']), "Must have recent_orders");

    assert_true($stats['total_orders'] >= 3, "Total orders count >= 3");
    assert_true($stats['total_revenue'] > 0, "Delivered revenue > 0");
    assert_true(is_array($stats['recent_orders']), "recent_orders must be an array");

    return true;
});

// ----------------------------------------------------------
// 4. Mailer Model Tests
// ----------------------------------------------------------
echo "\n--- 4. Mailer Model Tests ---\n";

$mailer = new Mailer();

it("Mailer initializes in mock mode and provides getters/setters", function() use ($mailer) {
    assert_true($mailer->isMockMode(), "Mailer should default to mock_mode=true in test environment");

    $mailer->setMockMode(true);
    assert_true($mailer->isMockMode());
    return true;
});

it("Mailer::send logs outgoing messages in mock mode", function() use ($mailer) {
    Mailer::clearSentLogs();
    assert_equals(0, count(Mailer::getSentLogs()));

    $sent = $mailer->send(
        'customer@example.com',
        'Janister Singson',
        'Your Order #1001 is Confirmed',
        '<p>Thank you for ordering!</p>',
        'Thank you for ordering!'
    );

    assert_true($sent, "Mailer::send must return true");
    assert_equals(1, count(Mailer::getSentLogs()));

    $last = Mailer::getLastSent();
    assert_true($last !== null);
    assert_equals('customer@example.com', $last['to_email']);
    assert_equals('Janister Singson', $last['to_name']);
    assert_equals('Your Order #1001 is Confirmed', $last['subject']);
    assert_equals('<p>Thank you for ordering!</p>', $last['body_html']);

    return true;
});

it("Mailer::sendResetEmail formats HTML and includes token link", function() use ($mailer) {
    Mailer::clearSentLogs();
    $token = bin2hex(random_bytes(32));

    $sent = $mailer->sendResetEmail('janister@example.com', $token, 'Janister', 'http://localhost/lpg-delivery-system-repo-2');
    assert_true($sent, "sendResetEmail must return true");

    $last = Mailer::getLastSent();
    assert_true($last !== null);
    assert_equals('janister@example.com', $last['to_email']);
    assert_equals('Janister', $last['to_name']);
    assert_true(strpos($last['subject'], 'Password Reset') !== false, "Subject must contain Password Reset");
    assert_true(strpos($last['body_html'], $token) !== false, "HTML body must contain the token URL");
    assert_true(strpos($last['alt_body'], $token) !== false, "Plaintext body must contain the token URL");

    return true;
});

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
