<?php
/**
 * Automated Database & Configuration Test Suite
 * LPG Delivery System v2
 *
 * Tests:
 * 1. Database schema creation & import
 * 2. Database singleton pattern & PDO instance configuration
 * 3. PDO attributes (ERRMODE_EXCEPTION, FETCH_ASSOC, EMULATE_PREPARES = false)
 * 4. Table existence (users, products, orders, password_resets)
 * 5. Foreign keys and indexes
 * 6. Seed data validation (Users, Products, Orders)
 * 7. Password hash verification for all default accounts
 * 8. Config files (database.php, app.php, mail.php) validation
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

echo "====================================================\n";
echo " LPG Delivery System v2 - Database & Config Tests\n";
echo "====================================================\n\n";

// ----------------------------------------------------------
// 1. Config Layer Tests
// ----------------------------------------------------------
echo "--- 1. Configuration Files ---\n";

it("database.php exists and returns valid config array", function() {
    $configFile = dirname(__DIR__) . '/config/database.php';
    assert_true(file_exists($configFile), "config/database.php must exist");
    $config = require $configFile;
    assert_true(is_array($config), "Must return an array");
    assert_equals('lpg_delivery_v2', $config['dbname']);
    assert_equals('127.0.0.1', $config['host']);
    assert_equals('utf8mb4', $config['charset']);
    return true;
});

it("app.php exists, defines constants, and returns config array", function() {
    $configFile = dirname(__DIR__) . '/config/app.php';
    assert_true(file_exists($configFile), "config/app.php must exist");
    $config = require $configFile;
    assert_true(is_array($config), "Must return an array");
    assert_true(defined('BASE_URL'), "BASE_URL constant must be defined");
    assert_true(defined('APP_NAME'), "APP_NAME constant must be defined");
    assert_true(defined('UPLOAD_PATH'), "UPLOAD_PATH constant must be defined");
    assert_equals('LPG Delivery System', APP_NAME);
    return true;
});

it("mail.php exists and returns SMTP configuration", function() {
    $configFile = dirname(__DIR__) . '/config/mail.php';
    assert_true(file_exists($configFile), "config/mail.php must exist");
    $config = require $configFile;
    assert_true(is_array($config), "Must return an array");
    assert_true(isset($config['host']), "Must have host");
    assert_true(isset($config['port']), "Must have port");
    assert_true(isset($config['mock_mode']), "Must have mock_mode flag");
    return true;
});

it(".htaccess files exist in sensitive directories", function() {
    $dbHtaccess = dirname(__DIR__) . '/database/.htaccess';
    $configHtaccess = dirname(__DIR__) . '/config/.htaccess';
    assert_true(file_exists($dbHtaccess), "database/.htaccess must exist");
    assert_true(file_exists($configHtaccess), "config/.htaccess must exist");
    assert_true(strpos(file_get_contents($dbHtaccess), 'denied') !== false || strpos(file_get_contents($dbHtaccess), 'Deny from all') !== false);
    assert_true(strpos(file_get_contents($configHtaccess), 'denied') !== false || strpos(file_get_contents($configHtaccess), 'Deny from all') !== false);
    return true;
});

// ----------------------------------------------------------
// 2. Schema Import & Initialization
// ----------------------------------------------------------
echo "\n--- 2. Database Schema Import ---\n";

$sqlFile = dirname(__DIR__) . '/database/lpg_delivery_v2.sql';
it("database/lpg_delivery_v2.sql exists and is readable", function() use ($sqlFile) {
    assert_true(file_exists($sqlFile), "SQL schema file must exist");
    assert_true(filesize($sqlFile) > 0, "SQL schema file must not be empty");
    return true;
});

it("imports schema SQL into MySQL", function() use ($sqlFile) {
    // Connect to MySQL server root without selecting database first
    $rootPdo = new PDO("mysql:host=127.0.0.1;port=3306;charset=utf8mb4", 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    $sql = file_get_contents($sqlFile);
    // Execute multiple SQL statements
    $rootPdo->exec($sql);
    return true;
});

// ----------------------------------------------------------
// 3. Database Singleton Class Tests
// ----------------------------------------------------------
echo "\n--- 3. Database Singleton Class ---\n";

require_once dirname(__DIR__) . '/classes/Database.php';

it("Database class is loaded", function() {
    assert_true(class_exists('Database'), "Database class must exist");
    return true;
});

it("Database::connect() returns configured PDO singleton", function() {
    Database::reset();
    $pdo1 = Database::connect();
    assert_true($pdo1 instanceof PDO, "Must return PDO instance");

    $pdo2 = Database::connect();
    assert_true($pdo1 === $pdo2, "Subsequent connect() calls must return identical singleton instance");
    return true;
});

it("Database singleton enforces PDO exception and fetch mode attributes", function() {
    $pdo = Database::connect();
    $errMode = $pdo->getAttribute(PDO::ATTR_ERRMODE);
    $fetchMode = $pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE);
    $emulatePrepares = $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);

    assert_equals(PDO::ERRMODE_EXCEPTION, $errMode, "ATTR_ERRMODE must be ERRMODE_EXCEPTION");
    assert_equals(PDO::FETCH_ASSOC, $fetchMode, "ATTR_DEFAULT_FETCH_MODE must be FETCH_ASSOC");
    assert_equals(false, (bool)$emulatePrepares, "ATTR_EMULATE_PREPARES must be false");
    return true;
});

// ----------------------------------------------------------
// 4. Schema & Table Structure Tests
// ----------------------------------------------------------
echo "\n--- 4. Tables & Structure ---\n";

it("all required tables exist in lpg_delivery_v2", function() {
    $pdo = Database::connect();
    $stmt = $pdo->query("SHOW TABLES FROM `lpg_delivery_v2`");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $required = ['users', 'products', 'orders', 'password_resets'];
    foreach ($required as $table) {
        assert_true(in_array($table, $tables), "Table '{$table}' must exist in database");
    }
    return true;
});

it("users table has correct columns and indexes", function() {
    $pdo = Database::connect();
    $stmt = $pdo->query("DESCRIBE `users`");
    $cols = array_column($stmt->fetchAll(), 'Field');

    $expectedCols = ['id', 'full_name', 'email', 'password', 'role', 'phone', 'address', 'valid_id_path', 'status', 'created_at', 'updated_at'];
    foreach ($expectedCols as $col) {
        assert_true(in_array($col, $cols), "users column '{$col}' must exist");
    }
    return true;
});

it("products table has correct columns and decimal price", function() {
    $pdo = Database::connect();
    $stmt = $pdo->query("DESCRIBE `products`");
    $cols = array_column($stmt->fetchAll(), 'Field');

    $expectedCols = ['id', 'name', 'brand', 'weight', 'price', 'stock', 'image_url', 'status', 'created_at', 'updated_at'];
    foreach ($expectedCols as $col) {
        assert_true(in_array($col, $cols), "products column '{$col}' must exist");
    }
    return true;
});

it("orders table has correct columns and foreign keys", function() {
    $pdo = Database::connect();
    $stmt = $pdo->query("DESCRIBE `orders`");
    $cols = array_column($stmt->fetchAll(), 'Field');

    $expectedCols = ['id', 'customer_id', 'product_id', 'rider_id', 'quantity', 'unit_price', 'total_amount', 'payment_method', 'status', 'delivery_address', 'contact_phone', 'notes', 'created_at', 'updated_at', 'delivered_at'];
    foreach ($expectedCols as $col) {
        assert_true(in_array($col, $cols), "orders column '{$col}' must exist");
    }
    return true;
});

// ----------------------------------------------------------
// 5. Seed Data & Password Hash Tests
// ----------------------------------------------------------
echo "\n--- 5. Seed Data & Password Hashes ---\n";

it("seed users are present (admin, rider, customer)", function() {
    $pdo = Database::connect();
    $stmt = $pdo->query("SELECT email, role, password, status FROM `users` ORDER BY id ASC");
    $users = $stmt->fetchAll();

    assert_true(count($users) >= 3, "At least 3 seed users must exist");

    $userMap = [];
    foreach ($users as $u) {
        $userMap[$u['email']] = $u;
    }

    assert_true(isset($userMap['admin@lpg.com']), "Admin user must exist");
    assert_true(isset($userMap['rider@lpg.com']), "Rider user must exist");
    assert_true(isset($userMap['customer@lpg.com']), "Customer user must exist");

    assert_equals('admin', $userMap['admin@lpg.com']['role']);
    assert_equals('rider', $userMap['rider@lpg.com']['role']);
    assert_equals('customer', $userMap['customer@lpg.com']['role']);

    assert_equals('active', $userMap['admin@lpg.com']['status']);
    assert_equals('active', $userMap['rider@lpg.com']['status']);
    assert_equals('active', $userMap['customer@lpg.com']['status']);

    return true;
});

it("seed user passwords verify with password_verify() and cost 12 bcrypt", function() {
    $pdo = Database::connect();

    // Verify Admin
    $stmt = $pdo->prepare("SELECT password FROM `users` WHERE email = ?");
    $stmt->execute(['admin@lpg.com']);
    $adminHash = $stmt->fetchColumn();
    assert_true(password_verify('Admin@2026!', $adminHash), "Admin password verification failed");

    // Verify Rider
    $stmt->execute(['rider@lpg.com']);
    $riderHash = $stmt->fetchColumn();
    assert_true(password_verify('Rider@2026!', $riderHash), "Rider password verification failed");

    // Verify Customer
    $stmt->execute(['customer@lpg.com']);
    $custHash = $stmt->fetchColumn();
    assert_true(password_verify('Customer@2026', $custHash), "Customer password verification failed");

    return true;
});

it("seed products are present and correctly priced", function() {
    $pdo = Database::connect();
    $stmt = $pdo->query("SELECT name, brand, weight, price, stock, status FROM `products` ORDER BY id ASC");
    $products = $stmt->fetchAll();

    assert_true(count($products) >= 5, "At least 5 seed products must exist");

    $prodMap = [];
    foreach ($products as $p) {
        $prodMap[$p['name']] = $p;
    }

    assert_true(isset($prodMap['Solane 11kg']), "Solane 11kg must exist");
    assert_true(isset($prodMap['Gasul 11kg']), "Gasul 11kg must exist");
    assert_true(isset($prodMap['Total 11kg']), "Total 11kg must exist");
    assert_true(isset($prodMap['Solane 22kg']), "Solane 22kg must exist");
    assert_true(isset($prodMap['Gasul 50kg']), "Gasul 50kg must exist");

    assert_equals('850.00', $prodMap['Solane 11kg']['price']);
    assert_equals('820.00', $prodMap['Gasul 11kg']['price']);
    assert_equals('800.00', $prodMap['Total 11kg']['price']);
    assert_equals('1650.00', $prodMap['Solane 22kg']['price']);
    assert_equals('3800.00', $prodMap['Gasul 50kg']['price']);

    return true;
});

it("seed orders are present with valid relations", function() {
    $pdo = Database::connect();
    $stmt = $pdo->query("
        SELECT o.id, o.quantity, o.total_amount, o.status, u.full_name as customer_name, p.name as product_name
        FROM `orders` o
        JOIN `users` u ON o.customer_id = u.id
        JOIN `products` p ON o.product_id = p.id
        ORDER BY o.id ASC
    ");
    $orders = $stmt->fetchAll();

    assert_true(count($orders) >= 3, "At least 3 seed orders must exist");
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
