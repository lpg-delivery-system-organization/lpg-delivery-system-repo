<?php
require_once __DIR__ . '/config.php';

function connectDb()
{
    static $conn = null;
    if ($conn !== null) {
        return $conn;
    }

    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
    if ($conn->connect_error) {
        throw new Exception('MySQL connection failed: ' . $conn->connect_error);
    }

    $conn->query('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '`');
    if (!$conn->select_db(DB_NAME)) {
        throw new Exception('Could not select database: ' . $conn->error);
    }

    $conn->query('SET NAMES utf8mb4');
    ensureSchema($conn);

    return $conn;
}

function ensureSchema($conn)
{
    $conn->query(
        'CREATE TABLE IF NOT EXISTS users (
            id INT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            role VARCHAR(50) NOT NULL,
            address TEXT NOT NULL,
            phone VARCHAR(50) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $conn->query(
        'CREATE TABLE IF NOT EXISTS products (
            id INT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            brand VARCHAR(255) NOT NULL,
            weight VARCHAR(50) NOT NULL,
            price INT NOT NULL,
            stock INT NOT NULL,
            image VARCHAR(20) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

$conn->query(
        'CREATE TABLE IF NOT EXISTS orders (
            id INT PRIMARY KEY,
            customerId INT NOT NULL,
            customerName VARCHAR(255) NOT NULL,
            customerAddress TEXT NOT NULL,
            customerPhone VARCHAR(50) NOT NULL,
            productId INT NOT NULL,
            productName VARCHAR(255) NOT NULL,
            quantity INT NOT NULL,
            total INT NOT NULL,
            payment VARCHAR(50) DEFAULT NULL,
            status VARCHAR(50) NOT NULL,
            riderId INT DEFAULT NULL,
            riderName VARCHAR(255) DEFAULT NULL,
            createdAt VARCHAR(255) NOT NULL,
            deliveredAt VARCHAR(255) DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $conn->query(
        'CREATE TABLE IF NOT EXISTS password_resets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL,
            code VARCHAR(10) NOT NULL,
            expires_at VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    if (isTableEmpty($conn, 'users') && isTableEmpty($conn, 'products') && isTableEmpty($conn, 'orders')) {
        saveDbData(getSeedData(), $conn);
    }
}

function isTableEmpty($conn, $table)
{
    $result = $conn->query('SELECT COUNT(*) AS count FROM `' . $table . '`');
    if (!$result) {
        return true;
    }
    $row = $result->fetch_assoc();
    return (int) ($row['count'] ?? 0) === 0;
}

function getSeedData()
{
    return [
        'users' => [
['id' => 1, 'name' => 'Janister Singson', 'email' => 'customer@lpg.com', 'password' => 'Customer@2026', 'role' => 'customer', 'address' => '123 Rizal St, Caloocan City', 'phone' => '09171234567'],
            ['id' => 2, 'name' => 'Maria Santos', 'email' => 'admin@lpg.com', 'password' => 'Admin@2026!', 'role' => 'admin', 'address' => 'Admin HQ, Quezon City', 'phone' => '09289876543'],
            ['id' => 3, 'name' => 'Pedro Reyes', 'email' => 'rider@lpg.com', 'password' => 'Rider@2026!', 'role' => 'rider', 'address' => '456 Mabini Ave, Caloocan City', 'phone' => '09351112222'],
        ],
        'products' => [
            ['id' => 1, 'name' => 'Solane 11kg', 'brand' => 'Solane', 'weight' => '11kg', 'price' => 850, 'stock' => 45, 'image' => '🔵'],
            ['id' => 2, 'name' => 'Gasul 11kg', 'brand' => 'Gasul', 'weight' => '11kg', 'price' => 820, 'stock' => 30, 'image' => '🔴'],
            ['id' => 3, 'name' => 'Total 11kg', 'brand' => 'Total', 'weight' => '11kg', 'price' => 800, 'stock' => 20, 'image' => '🟡'],
            ['id' => 4, 'name' => 'Solane 22kg', 'brand' => 'Solane', 'weight' => '22kg', 'price' => 1650, 'stock' => 15, 'image' => '🔵'],
            ['id' => 5, 'name' => 'Gasul 50kg', 'brand' => 'Gasul', 'weight' => '50kg', 'price' => 3800, 'stock' => 8, 'image' => '🔴'],
        ],
        'orders' => [
            ['id' => 1001, 'customerId' => 1, 'customerName' => 'Janister Singson', 'customerAddress' => '123 Rizal St, Caloocan City', 'customerPhone' => '09171234567', 'productId' => 1, 'productName' => 'Solane 11kg', 'quantity' => 2, 'total' => 1700, 'payment' => 'cod', 'status' => 'delivered', 'riderId' => 3, 'riderName' => 'Pedro Reyes', 'createdAt' => '2025-04-18T09:00:00', 'deliveredAt' => '2025-04-18T11:30:00'],
            ['id' => 1002, 'customerId' => 1, 'customerName' => 'Janister Singson', 'customerAddress' => '123 Rizal St, Caloocan City', 'customerPhone' => '09171234567', 'productId' => 2, 'productName' => 'Gasul 11kg', 'quantity' => 1, 'total' => 820, 'payment' => 'cod', 'status' => 'in-transit', 'riderId' => 3, 'riderName' => 'Pedro Reyes', 'createdAt' => '2025-04-22T08:00:00', 'deliveredAt' => null],
            ['id' => 1003, 'customerId' => 1, 'customerName' => 'Janister Singson', 'customerAddress' => '123 Rizal St, Caloocan City', 'customerPhone' => '09171234567', 'productId' => 3, 'productName' => 'Total 11kg', 'quantity' => 1, 'total' => 800, 'payment' => 'cod', 'status' => 'pending', 'riderId' => null, 'riderName' => null, 'createdAt' => '2025-04-22T10:00:00', 'deliveredAt' => null],
        ],
    ];
}

function getDbData()
{
    $conn = connectDb();
    $users = fetchRows($conn, 'SELECT * FROM users ORDER BY id');
    $products = fetchRows($conn, 'SELECT * FROM products ORDER BY id');
    $orders = fetchRows($conn, 'SELECT * FROM orders ORDER BY id');

    return [
        'users' => $users,
        'products' => $products,
        'orders' => $orders,
    ];
}

function fetchRows($conn, $sql)
{
    $result = $conn->query($sql);
    if (!$result) {
        throw new Exception($conn->error);
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    return $rows;
}

function saveDbData($data, $conn = null)
{
    if ($conn === null) {
        $conn = connectDb();
    }

    $conn->begin_transaction();

    $conn->query('TRUNCATE TABLE orders');
    $conn->query('TRUNCATE TABLE products');
    $conn->query('TRUNCATE TABLE users');

    insertUsers($conn, $data['users'] ?? []);
    insertProducts($conn, $data['products'] ?? []);
    insertOrders($conn, $data['orders'] ?? []);

    $conn->commit();
    return true;
}

function insertUsers($conn, $rows)
{
    insertRows($conn, 'INSERT INTO users (id, name, email, password, role, address, phone) VALUES (?, ?, ?, ?, ?, ?, ?)', $rows, ['id', 'name', 'email', 'password', 'role', 'address', 'phone']);
}

function insertProducts($conn, $rows)
{
    insertRows($conn, 'INSERT INTO products (id, name, brand, weight, price, stock, image) VALUES (?, ?, ?, ?, ?, ?, ?)', $rows, ['id', 'name', 'brand', 'weight', 'price', 'stock', 'image']);
}

function insertOrders($conn, $rows)
{
    insertRows($conn, 'INSERT INTO orders (id, customerId, customerName, customerAddress, customerPhone, productId, productName, quantity, total, payment, status, riderId, riderName, createdAt, deliveredAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', $rows, ['id', 'customerId', 'customerName', 'customerAddress', 'customerPhone', 'productId', 'productName', 'quantity', 'total', 'payment', 'status', 'riderId', 'riderName', 'createdAt', 'deliveredAt']);
}

function insertRows($conn, $sql, $rows, $fields)
{
    $stmt = $conn->prepare($sql);
    foreach ($rows as $row) {
        $params = [];
        $types = '';

        foreach ($fields as $field) {
            $value = $row[$field] ?? null;
            if ($value === null) {
                $types .= 's';
            } elseif (is_int($value) || is_float($value)) {
                $types .= 'i';
            } else {
                $types .= 's';
            }
            $params[] = $value;
        }

        $refs = [];
        foreach ($params as $key => $value) {
            $refs[$key] = &$params[$key];
        }
        array_unshift($refs, $types);

call_user_func_array([$stmt, 'bind_param'], $refs);
        $stmt->execute();
    }
    $stmt->close();
}

// =========================================================
//  PASSWORD RESET HELPERS
// =========================================================
function storeResetCode($email, $code, $expiresAt)
{
    $conn = connectDb();

    // Remove any previous codes for this email
    $stmt = $conn->prepare('DELETE FROM password_resets WHERE email = ?');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare('INSERT INTO password_resets (email, code, expires_at) VALUES (?, ?, ?)');
    $stmt->bind_param('sss', $email, $code, $expiresAt);
    $stmt->execute();
    $stmt->close();
}

function getResetCode($email)
{
    $conn = connectDb();
    $stmt = $conn->prepare('SELECT code, expires_at FROM password_resets WHERE email = ? ORDER BY id DESC LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row;
}

function clearResetCode($email)
{
    $conn = connectDb();
    $stmt = $conn->prepare('DELETE FROM password_resets WHERE email = ?');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $stmt->close();
}

function updateUserPassword($email, $password)
{
    $conn = connectDb();
    $stmt = $conn->prepare('UPDATE users SET password = ? WHERE email = ?');
    $stmt->bind_param('ss', $password, $email);
    $stmt->execute();
    $stmt->close();
}

// =========================================================
//  USER REGISTRATION (direct to MySQL)
// =========================================================
function userExists($email)
{
    $conn = connectDb();
    $stmt = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result->fetch_assoc() !== null;
    $stmt->close();
    return $exists;
}

function getNextUserId()
{
    $conn = connectDb();
    // Find the lowest available positive integer ID (avoids collisions with
    // legacy huge Date.now()-based IDs that can overflow INT).
    $result = $conn->query('SELECT id FROM users ORDER BY id ASC');
    $used = [];
    while ($row = $result->fetch_assoc()) {
        $used[(int) $row['id']] = true;
    }
    $id = 1;
    while (isset($used[$id])) {
        $id++;
    }
    return $id;
}

function storeUser($name, $email, $password, $address, $phone)
{
    $conn = connectDb();
    $id = getNextUserId();
    $role = 'customer';

    $stmt = $conn->prepare('INSERT INTO users (id, name, email, password, role, address, phone) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('issssss', $id, $name, $email, $password, $role, $address, $phone);
    $stmt->execute();
    $stmt->close();

    return [
        'id' => $id,
        'name' => $name,
        'email' => $email,
        'password' => $password,
        'role' => $role,
        'address' => $address,
        'phone' => $phone
    ];
}
