<?php
// Temporary verification — delete after use
error_reporting(E_ALL & ~E_WARNING);
require_once __DIR__ . '/db.php';

$action = $_GET['a'] ?? 'users';

header('Content-Type: application/json');

if ($action === 'users') {
    $data = getDbData();
    echo json_encode(['count' => count($data['users']), 'users' => $data['users']]);
} elseif ($action === 'exists') {
    $email = $_GET['email'] ?? '';
    echo json_encode(['email' => $email, 'exists' => userExists($email)]);
} elseif ($action === 'register') {
    $name = $_GET['name'] ?? 'Test User';
    $email = $_GET['email'] ?? 'test@lpg.com';
    $pw = $_GET['pw'] ?? 'test123';
    $address = $_GET['address'] ?? '123 Test St';
    $phone = $_GET['phone'] ?? '09170000000';
    if (userExists($email)) {
        echo json_encode(['ok' => false, 'error' => 'Email already registered']);
    } else {
        $user = storeUser($name, $email, $pw, $address, $phone);
        echo json_encode(['ok' => true, 'user' => $user]);
    }
}

