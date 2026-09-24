<?php
/**
 * Automated Notifications Test Suite
 * LPG Delivery System v2
 *
 * Tests:
 * 1. Schema Integrity                        - notifications table & columns exist
 * 2. Notification Model                      - create, getNew, getRecent, markRead, markAllRead, guards
 * 3. Notifications API Endpoint              - auth 401, poll, recent, mark_read, mark_all_read, CSRF 403
 * 4. Integration Hooks                       - delivered notification, admin assignment notification,
 *                                              self-claim (no notification), chat peer notification
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
require_once __DIR__ . '/../classes/ChatMessage.php';
require_once __DIR__ . '/../classes/Notification.php';
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

function assert_contains(string $needle, string $haystack, string $message = ''): bool {
    if (is_array($haystack)) $haystack = json_encode($haystack, JSON_UNESCAPED_UNICODE);
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
    unset($GLOBALS['LAST_HTTP_CODE'], $GLOBALS['LAST_RESPONSE'], $GLOBALS['LAST_EXCEPTION']);
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
echo " LPG Delivery System v2 - Notifications Test Suite\n";
echo "====================================================\n\n";

$db = Database::connect();
$userModel = new User($db);
$orderModel = new Order($db);
$notificationModel = new Notification($db);
$chatModel = new ChatMessage($db);

// Retrieve or seed standard test accounts (same pattern as test_api.php)
function ensure_user(PDO $db, User $userModel, string $email, string $role, string $fullName, string $phone, string $address): array {
    $user = $userModel->findByEmail($email);
    if ($user) return $user;
    $pass = password_hash('password', PASSWORD_BCRYPT, ['cost' => 12]);
    $stmt = $db->prepare("
        INSERT INTO users (full_name, email, password, role, phone, address, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, 'active', NOW(), NOW())
    ");
    $stmt->execute([$fullName, $email, $pass, $role, $phone, $address]);
    return $userModel->findByEmail($email);
}

$testAdmin = ensure_user($db, $userModel, 'admin@lpg.com', 'admin', 'Maria Santos', '09289876543', 'Admin HQ, Quezon City');
$testRider = ensure_user($db, $userModel, 'rider@lpg.com', 'rider', 'Pedro Reyes', '09351112222', '456 Mabini Ave, Caloocan City');
$testRider2 = ensure_user($db, $userModel, 'rider2@lpg.com', 'rider', 'Juan Rider Two', '09353334444', '789 Rizal St, Pasay City');
$testCustomer = ensure_user($db, $userModel, 'customer@lpg.com', 'customer', 'Janister Singson', '09171234567', '123 Rizal St, Caloocan City');

$adminId = (int)$testAdmin['id'];
$riderId = (int)$testRider['id'];
$customerId = (int)$testCustomer['id'];

$productStmt = $db->query("SELECT id FROM products WHERE status = 'active' AND stock > 0 ORDER BY id ASC LIMIT 1");
$productRow = $productStmt->fetch();
$productId = $productRow ? (int)$productRow['id'] : 1;

function create_test_order(PDO $db, int $customerId, int $productId, string $status, ?int $riderId = null): int {
    $statement = $db->prepare("
        INSERT INTO orders (customer_id, product_id, rider_id, quantity, unit_price, total_amount, payment_method, status, delivery_address, contact_phone, created_at, updated_at)
        VALUES (?, ?, ?, 1, 850.00, 850.00, 'cod', ?, '456 Test Street, Caloocan', '09171112233', NOW(), NOW())
    ");
    $statement->execute([$customerId, $productId, $riderId, $status]);
    return (int)$db->lastInsertId();
}

$notificationsApi = __DIR__ . '/../api/notifications.php';
$chatApi = __DIR__ . '/../api/chat.php';

// =========================================================================
// Group 1: Schema Integrity
// =========================================================================
echo "Group 1: Schema Integrity\n";

it('notifications table exists with all expected columns', function () use ($db) {
    $stmt = $db->query("SELECT id, user_id, order_id, type, title, message, link, is_read, created_at FROM notifications LIMIT 0");
    assert_true($stmt !== false);
});

it('notification FKs point at users and orders', function () use ($db) {
    $stmt = $db->query("
        SELECT COUNT(*) AS c
        FROM information_schema.TABLE_CONSTRAINTS tc
        JOIN information_schema.KEY_COLUMN_USAGE kcu
          ON tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME AND tc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
        WHERE tc.CONSTRAINT_SCHEMA = DATABASE()
          AND tc.TABLE_NAME = 'notifications'
          AND tc.CONSTRAINT_TYPE = 'FOREIGN KEY'
          AND kcu.COLUMN_NAME IN ('user_id', 'order_id')
    ");
    $count = (int)$stmt->fetchColumn();
    assert_equals(2, $count, 'Expected 2 FK constraints (user_id, order_id) on notifications');
});

// =========================================================================
// Group 2: Notification Model
// =========================================================================
echo "\nGroup 2: Notification Model\n";

// Reset unread state for the affected test users so repeated runs stay
// deterministic (previous runs may have left unread notifications behind).
$notificationModel->markAllRead($customerId);
$notificationModel->markAllRead($riderId);

$notifId = 0;

it('create() inserts a notification and returns a numeric id', function () use ($notificationModel, $customerId, &$notifId) {
    $id = $notificationModel->create($customerId, 'test_type', 'Test Title', 'Test message body.', null, 'pages/customer/dashboard.php');
    assert_true(is_int($id) && $id > 0);
    $notifId = $id;
});

it('create() rejects an invalid recipient user id', function () use ($notificationModel) {
    try {
        $notificationModel->create(0, 'test_type', 'Title', 'Body');
        throw new Exception('Expected InvalidArgumentException was not thrown.');
    } catch (InvalidArgumentException $e) {
        assert_contains('recipient', $e->getMessage());
    }
});

it('create() rejects empty title or message', function () use ($notificationModel, $customerId) {
    try {
        $notificationModel->create($customerId, 'test_type', '  ', 'Body');
        throw new Exception('Expected InvalidArgumentException was not thrown.');
    } catch (InvalidArgumentException $e) {
        assert_contains('required', $e->getMessage());
    }
});

it('getNew() returns notifications newer than the reference id, scoped to the user', function () use ($notificationModel, $customerId, $notifId) {
    $newOnes = $notificationModel->getNew($customerId, $notifId > 0 ? $notifId - 1 : 0, 10);
    $found = array_filter($newOnes, fn($n) => (int)$n['id'] === $notifId);
    assert_equals(1, count($found));
    $matches = array_values($found);
    assert_equals('test_type', $matches[0]['type']);
    assert_equals('Test Title', $matches[0]['title']);
    assert_equals($customerId, (int)$matches[0]['user_id']);
});

it('getNew() does not leak another user\'s notifications', function () use ($notificationModel, $riderId, $notifId) {
    $riderOnes = $notificationModel->getNew($riderId, 0, 50);
    $leaked = array_filter($riderOnes, fn($n) => (int)$n['id'] === $notifId);
    assert_equals(0, count($leaked));
});

it('getUnreadCount() counts unread notifications', function () use ($notificationModel, $customerId) {
    assert_true($notificationModel->getUnreadCount($customerId) >= 1);
});

it('markRead() marks a specific notification read', function () use ($notificationModel, $customerId, $notifId) {
    assert_true($notificationModel->markRead($customerId, [$notifId]));
    $rows = $notificationModel->getNew($customerId, $notifId - 1, 5);
    $found = array_values(array_filter($rows, fn($n) => (int)$n['id'] === $notifId));
    assert_equals(1, (int)$found[0]['is_read']);
});

it('markRead() cannot mark another user\'s notification read', function () use ($notificationModel, $riderId, $customerId) {
    $id = $notificationModel->create($customerId, 'test_type', 'Second Title', 'Still unread.', null, null);
    assert_true($notificationModel->markRead($riderId, [$id]));
    // The notification remains unread for its real owner.
    $rows = $notificationModel->getNew($customerId, $id - 1, 5);
    $found = array_values(array_filter($rows, fn($n) => (int)$n['id'] === $id));
    assert_equals(0, (int)$found[0]['is_read']);
});

it('markAllRead() clears all unread notifications for the user', function () use ($notificationModel, $customerId) {
    $notificationModel->create($customerId, 'test_type', 'Third Title', 'Body');
    assert_true($notificationModel->markAllRead($customerId));
    assert_equals(0, $notificationModel->getUnreadCount($customerId));
});

it('getRecent() returns newest-first, newest id first', function () use ($notificationModel, $customerId, $notifId) {
    $recent = $notificationModel->getRecent($customerId, 5);
    assert_true(count($recent) >= 2);
    assert_true((int)$recent[0]['id'] > (int)$recent[1]['id']);
    assert_equals('test_type', $recent[0]['type']);
});

// =========================================================================
// Group 3: Notifications API Endpoint
// =========================================================================
echo "\nGroup 3: Notifications API Endpoint\n";

it('api/notifications.php blocks unauthenticated requests with HTTP 401', function () use ($notificationsApi) {
    reset_api_env();
    $res = call_endpoint($notificationsApi, ['action' => 'poll']);
    assert_equals(401, $res['code']);
    assert_false($res['data']['success'] ?? true);
    assert_contains('Authentication required', $res['data']['error'] ?? ($res['data']['message'] ?? ''));
});

it('poll returns success with a data array and unread count', function () use ($notificationsApi, $testCustomer) {
    reset_api_env();
    login_user($testCustomer);
    $res = call_endpoint($notificationsApi, ['action' => 'poll', 'after_id' => 0]);
    assert_equals(200, $res['code']);
    assert_true($res['data']['success'] ?? false);
    assert_true(is_array($res['data']['data'] ?? null));
    assert_true(isset($res['data']['unread']));
    assert_contains('created_at', json_encode($res['data']['data']));
});

it('poll with after_id only returns newer notifications', function () use ($notificationsApi, $db, $testCustomer, $notificationModel, $customerId) {
    $row = $db->query("SELECT COALESCE(MAX(id), 0) AS m FROM notifications WHERE user_id = {$customerId}")->fetch();
    $lastId = (int)$row['m'];

    $newId = $notificationModel->create($customerId, 'poll_test', 'Poll Test', 'Only this one is new.', null, null);

    reset_api_env();
    login_user($testCustomer);
    $res = call_endpoint($notificationsApi, ['action' => 'poll', 'after_id' => $lastId]);

    assert_equals(200, $res['code']);
    assert_equals(1, count($res['data']['data'] ?? []));
    assert_equals($newId, (int)($res['data']['data'][0]['id'] ?? 0));
    assert_equals('poll_test', $res['data']['data'][0]['type']);
});

it('recent returns newest-first list for the bell dropdown', function () use ($notificationsApi, $testCustomer) {
    reset_api_env();
    login_user($testCustomer);
    $res = call_endpoint($notificationsApi, ['action' => 'recent', 'limit' => 15]);
    assert_equals(200, $res['code']);
    assert_true($res['data']['success'] ?? false);
    $items = $res['data']['data'] ?? [];
    assert_true(count($items) >= 1);
    assert_true(is_bool($items[0]['is_read']));
});

it('mark_read returns HTTP 403 without a valid CSRF token', function () use ($notificationsApi, $testRider) {
    reset_api_env();
    login_user($testRider);
    $res = call_endpoint($notificationsApi, ['action' => 'mark_read', 'ids' => '1']);
    assert_equals(403, $res['code']);
    assert_contains('security token', strtolower($res['data']['error'] ?? ''));
});

it('mark_all_read returns HTTP 403 without a valid CSRF token', function () use ($notificationsApi, $testRider) {
    reset_api_env();
    login_user($testRider);
    $res = call_endpoint($notificationsApi, ['action' => 'mark_all_read']);
    assert_equals(403, $res['code']);
});

it('mark_all_read with CSRF clears the user\'s unread count', function () use ($notificationsApi, $testRider, $notificationModel, $riderId) {
    $notificationModel->create($riderId, 'test_type', 'Mark All', 'Unread before marking.', null, null);
    assert_true($notificationModel->getUnreadCount($riderId) >= 1);

    reset_api_env();
    login_user($testRider);
    $token = csrf_token();
    $res = call_endpoint($notificationsApi, [
        'action'     => 'mark_all_read',
        'csrf_token' => $token
    ]);

    assert_equals(200, $res['code']);
    assert_true($res['data']['success'] ?? false);
    assert_equals(0, $res['data']['unread']);
    assert_equals(0, $notificationModel->getUnreadCount($riderId));
});

it('mark_read with CSRF marks only the targeted ids', function () use ($notificationsApi, $testRider, $notificationModel, $riderId) {
    $id1 = $notificationModel->create($riderId, 'test_type', 'MR One', 'Unread.', null, null);
    $id2 = $notificationModel->create($riderId, 'test_type', 'MR Two', 'Stays unread.', null, null);

    reset_api_env();
    login_user($testRider);
    $token = csrf_token();
    $res = call_endpoint($notificationsApi, [
        'action'     => 'mark_read',
        'ids'        => (string)$id1,
        'csrf_token' => $token
    ]);

    assert_equals(200, $res['code']);
    assert_true($res['data']['success'] ?? false);

    $rows = $notificationModel->getNew($riderId, $id1 - 1, 5);
    $byId = [];
    foreach ($rows as $r) { $byId[(int)$r['id']] = $r; }
    assert_equals(1, (int)$byId[$id1]['is_read']);
    assert_equals(0, (int)$byId[$id2]['is_read']);
});

it('unknown action returns HTTP 400', function () use ($notificationsApi, $testRider) {
    reset_api_env();
    login_user($testRider);
    $res = call_endpoint($notificationsApi, ['action' => 'bogus_action']);
    assert_equals(400, $res['code']);
    assert_false($res['data']['success'] ?? true);
});

it('mark_read requires POST (405 on GET)', function () use ($notificationsApi, $testRider) {
    reset_api_env();
    login_user($testRider);
    $token = csrf_token();
    // GET without POST body => action comes from $_GET, but mark_read demands POST method
    $res = call_endpoint($notificationsApi, [], ['action' => 'mark_read', 'ids' => '1', 'csrf_token' => $token]);
    assert_equals(405, $res['code']);
});

// =========================================================================
// Group 4: Integration Hooks
// =========================================================================
echo "\nGroup 4: Integration Hooks\n";

it('Order::updateStatus(\'delivered\') notifies the customer', function () use ($db, $orderModel, $customerId, $productId, $notificationModel) {
    $oid = create_test_order($db, $customerId, $productId, 'out_for_delivery', null);
    assert_true($orderModel->updateStatus($oid, 'delivered'));

    $rows = $notificationModel->getRecent($customerId, 50);
    $found = array_values(array_filter($rows, fn($n) => (int)$n['order_id'] === $oid && $n['type'] === 'order_delivered'));
    assert_equals(1, count($found));
    assert_contains('delivered', strtolower($found[0]['title']));
    assert_contains('pages/customer/order-detail.php?id=' . $oid, $found[0]['link']);
});

it('Order::assignRider() with an admin actor notifies the assigned rider', function () use ($db, $orderModel, $productId, $customerId, $riderId, $adminId, $notificationModel) {
    $notificationModel->markAllRead($riderId);
    $oid = create_test_order($db, $customerId, $productId, 'approved', null);
    assert_true($orderModel->assignRider($oid, $riderId, 'picked_up', $adminId));

    $rows = $notificationModel->getRecent($riderId, 50);
    $found = array_values(array_filter($rows, fn($n) => (int)$n['order_id'] === $oid && $n['type'] === 'order_assigned'));
    assert_equals(1, count($found));
    assert_contains('Order #' . $oid, $found[0]['message']);
    assert_contains('pages/rider/order-detail.php?id=' . $oid, $found[0]['link']);
});

it('Order::assignRider() self-claim does not create a notification', function () use ($db, $orderModel, $productId, $customerId, $riderId, $notificationModel) {
    $notificationModel->markAllRead($riderId);
    $before = $notificationModel->getUnreadCount($riderId);

    $oid = create_test_order($db, $customerId, $productId, 'approved', null);
    assert_true($orderModel->assignRider($oid, $riderId, 'picked_up', $riderId));

    $rows = $notificationModel->getRecent($riderId, 50);
    $found = array_values(array_filter($rows, fn($n) => (int)$n['order_id'] === $oid && $n['type'] === 'order_assigned'));
    assert_equals(0, count($found));
    assert_equals($before, $notificationModel->getUnreadCount($riderId));
});

it('api/chat.php send notifies the rider peer when a customer messages', function () use ($db, $chatApi, $productId, $customerId, $riderId, $notificationModel, $testCustomer) {
    $notificationModel->markAllRead($riderId);
    $oid = create_test_order($db, $customerId, $productId, 'picked_up', $riderId);

    reset_api_env();
    login_user($testCustomer);
    $token = csrf_token();
    $res = call_endpoint($chatApi, [
        'action'     => 'send',
        'order_id'   => $oid,
        'message'    => 'Please deliver at the gate, thank you!',
        'csrf_token' => $token
    ]);

    assert_equals(200, $res['code']);
    assert_true($res['data']['success'] ?? false);

    $rows = $notificationModel->getRecent($riderId, 50);
    $found = array_values(array_filter($rows, fn($n) => (int)$n['order_id'] === $oid && $n['type'] === 'chat_message'));
    assert_equals(1, count($found));
    assert_contains('Order #' . $oid, $found[0]['title']);
    assert_contains('at the gate', $found[0]['message']);
    assert_contains('pages/rider/order-detail.php?id=' . $oid, $found[0]['link']);
});

it('api/chat.php send notifies the customer peer when a rider messages', function () use ($db, $chatApi, $productId, $customerId, $riderId, $notificationModel, $testRider) {
    $notificationModel->markAllRead($customerId);
    $oid = create_test_order($db, $customerId, $productId, 'picked_up', $riderId);

    reset_api_env();
    login_user($testRider);
    $token = csrf_token();
    $res = call_endpoint($chatApi, [
        'action'     => 'send',
        'order_id'   => $oid,
        'message'    => 'On my way now.',
        'csrf_token' => $token
    ]);

    assert_equals(200, $res['code']);
    assert_true($res['data']['success'] ?? false);

    $rows = $notificationModel->getRecent($customerId, 50);
    $found = array_values(array_filter($rows, fn($n) => (int)$n['order_id'] === $oid && $n['type'] === 'chat_message'));
    assert_equals(1, count($found));
    assert_contains('On my way now', $found[0]['message']);
    assert_contains('pages/customer/order-detail.php?id=' . $oid, $found[0]['link']);
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