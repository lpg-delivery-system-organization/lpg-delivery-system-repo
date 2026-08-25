<?php
session_start();
include 'connection.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}

$username = isset($_POST['username']) ? trim($_POST['username']) : '';
$password = isset($_POST['password']) ? $_POST['password'] : '';

if ($username === '' || $password === '') {
    header('Location: login.php?error=' . urlencode('Please fill both fields'));
    exit;
}

$stmt = $connection->prepare("SELECT id, username, email, password_hash FROM tbl_users WHERE username = ? OR email = ? LIMIT 1");
if (!$stmt) {
    header('Location: login.php?error=' . urlencode('Server error'));
    exit;
}
$stmt->bind_param('ss', $username, $username);
$stmt->execute();
$res = $stmt->get_result();

if ($res && $res->num_rows === 1) {
    $user = $res->fetch_assoc();
    $hash = $user['password'];

    $validated = false;
    if (password_needs_rehash($hash, PASSWORD_DEFAULT) || password_verify($password, $hash)) {
        $validated = password_verify($password, $hash) || ($hash === $password);
    } else {
        $validated = ($hash === $password);
    }

    if ($validated) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        header('Location: index.php');
        exit;
    }
}

header('Location: login.php?error=1');
exit;
