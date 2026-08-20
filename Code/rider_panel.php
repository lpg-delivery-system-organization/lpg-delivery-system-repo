<?php
require_once "config.php";
requireRole("rider");

$riderId = (int) $_SESSION["user_id"];
$fullName = $_SESSION["full_name"];

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "logout") {
    session_unset();
    session_destroy();
    header("Location: index.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "update_status") {
    $orderId = (int)($_POST["order_id"] ?? 0);
    $status = $_POST["status"] ?? "";

    $allowed = ["picked_up", "out_for_delivery", "delivered"];

    if ($orderId > 0 && in_array($status, $allowed, true)) {
        $stmt = $pdo->prepare(
            "UPDATE orders
             SET status = ?
             WHERE id = ? AND rider_id = ?"
        );
        $stmt->execute([$status, $orderId, $riderId]);
    }

    header("Location: rider_panel.php");
    exit;
}

$stmt = $pdo->prepare(
    "SELECT o.id, o.quantity, o.total_amount, o.payment_method, o.status,
            o.created_at, u.full_name AS customer, u.phone, u.address,
            p.name AS product, p.size
     FROM orders o
     JOIN users u ON u.id = o.customer_id
     JOIN products p ON p.id = o.product_id
     WHERE o.rider_id = ?
     ORDER BY o.created_at DESC"
);
$stmt->execute([$riderId]);
$assignments = $stmt->fetchAll();

$available = $pdo->query(
    "SELECT o.id, o.quantity, o.total_amount, o.payment_method, o.status,
            o.created_at, u.full_name AS customer, u.phone, u.address,
            p.name AS product, p.size
     FROM orders o
     JOIN users u ON u.id = o.customer_id
     JOIN products p ON p.id = o.product_id
     WHERE o.rider_id IS NULL
       AND o.status IN ('approved','ready_for_delivery')
     ORDER BY o.created_at ASC"
)->fetchAll();

$stmt = $pdo->prepare("SELECT full_name, email, phone, address FROM users WHERE id = ?");
$stmt->execute([$riderId]);
$profile = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LPG Delivery - Rider Panel</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<nav class="topnav">
    <div class="nav-brand"><div class="nav-brand-icon">🔥</div>LPG Delivery</div>
    <div class="nav-right">
        <div class="nav-user">
            <div class="nav-avatar"><?= htmlspecialchars(strtoupper(substr($fullName, 0, 1))) ?></div>
            <span><?= htmlspecialchars($fullName) ?></span>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="logout">
            <button class="nav-logout" type="submit">Logout</button>
        </form>
    </div>
</nav>

<div class="tabs">
    <a href="#assignments">My Deliveries</a>
    <a href="#available">Available Orders</a>
    <a href="#profile">My Profile</a>
</div>

<main class="app-content">
    <section id="assignments" class="panel">
        <div class="page-title">My Deliveries</div>
        <div class="page-sub">Orders assigned to you</div>

        <?php foreach ($assignments as $order): ?>
            <div class="delivery-card">
                <h3>Order #<?= (int)$order["id"] ?></h3>
                <p><strong>Customer:</strong> <?= htmlspecialchars($order["customer"]) ?></p>
                <p><strong>Phone:</strong> <?= htmlspecialchars($order["phone"]) ?></p>
                <p><strong>Address:</strong> <?= htmlspecialchars($order["address"]) ?></p>
                <p><strong>Product:</strong> <?= htmlspecialchars($order["product"] . " " . $order["size"]) ?></p>
                <p><strong>Quantity:</strong> <?= (int)$order["quantity"] ?></p>
                <p><strong>Payment:</strong> <?= htmlspecialchars(strtoupper($order["payment_method"])) ?></p>
                <p><strong>Status:</strong> <?= htmlspecialchars($order["status"]) ?></p>

                <?php if ($order["status"] !== "delivered"): ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="order_id" value="<?= (int)$order["id"] ?>">

                        <select name="status" required>
                            <option value="picked_up">Picked Up</option>
                            <option value="out_for_delivery">Out for Delivery</option>
                            <option value="delivered">Delivered</option>
                        </select>

                        <button class="btn btn-primary" type="submit">Update Status</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <?php if (!$assignments): ?>
            <p>No deliveries have been assigned to you.</p>
        <?php endif; ?>
    </section>

    <section id="available" class="panel">
        <div class="page-title">Available Orders</div>
        <div class="page-sub">Orders waiting for rider assignment</div>

        <?php foreach ($available as $order): ?>
            <div class="delivery-card">
                <h3>Order #<?= (int)$order["id"] ?></h3>
                <p><strong>Customer:</strong> <?= htmlspecialchars($order["customer"]) ?></p>
                <p><strong>Address:</strong> <?= htmlspecialchars($order["address"]) ?></p>
                <p><strong>Product:</strong> <?= htmlspecialchars($order["product"] . " " . $order["size"]) ?></p>
                <p><strong>Quantity:</strong> <?= (int)$order["quantity"] ?></p>
                <p><strong>Payment:</strong> <?= htmlspecialchars(strtoupper($order["payment_method"])) ?></p>

                <form method="POST" action="claim_order.php">
                    <input type="hidden" name="order_id" value="<?= (int)$order["id"] ?>">
                    <button class="btn btn-primary" type="submit">Claim Order</button>
                </form>
            </div>
        <?php endforeach; ?>

        <?php if (!$available): ?>
            <p>No available orders right now.</p>
        <?php endif; ?>
    </section>

    <section id="profile" class="panel">
        <div class="page-title">My Profile</div>
        <div class="page-sub">Your rider information</div>

        <div class="profile-card">
            <p><strong>Full Name:</strong> <?= htmlspecialchars($profile["full_name"]) ?></p>
            <p><strong>Email:</strong> <?= htmlspecialchars($profile["email"]) ?></p>
            <p><strong>Phone:</strong> <?= htmlspecialchars($profile["phone"]) ?></p>
            <p><strong>Address:</strong> <?= htmlspecialchars($profile["address"]) ?></p>
            <p><strong>Role:</strong> Rider</p>
        </div>
    </section>
</main>
</body>
</html>
