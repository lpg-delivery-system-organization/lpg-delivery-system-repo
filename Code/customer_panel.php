<?php
require_once "config.php";
requireRole("customer");

$userId = (int) $_SESSION["user_id"];
$fullName = $_SESSION["full_name"];

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "logout") {
    session_unset();
    session_destroy();
    header("Location: index.php");
    exit;
}

$products = $pdo->query(
    "SELECT id, name, size, price, stock
     FROM products
     WHERE status = 'active'
     ORDER BY id ASC"
)->fetchAll();

$stmt = $pdo->prepare(
    "SELECT o.id, o.quantity, o.total_amount, o.payment_method, o.status,
            o.created_at, p.name AS product_name, p.size
     FROM orders o
     INNER JOIN products p ON p.id = o.product_id
     WHERE o.customer_id = ?
     ORDER BY o.created_at DESC"
);
$stmt->execute([$userId]);
$orders = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT full_name, email, phone, address FROM users WHERE id = ?");
$stmt->execute([$userId]);
$profile = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LPG Delivery - Customer Panel</title>
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
    <a href="#shop">Order LPG</a>
    <a href="#orders">My Orders</a>
    <a href="#profile">My Profile</a>
</div>

<main class="app-content">
    <section id="shop" class="panel">
        <div class="page-title">Order LPG</div>
        <div class="page-sub">Choose a product to order</div>

        <div class="products-grid">
            <?php foreach ($products as $product): ?>
                <div class="product-card">
                    <div class="product-icon">🔥</div>
                    <h3><?= htmlspecialchars($product["name"]) ?></h3>
                    <p><?= htmlspecialchars($product["size"]) ?></p>
                    <strong>₱<?= number_format((float)$product["price"], 2) ?></strong>

                    <?php if ((int)$product["stock"] > 0): ?>
                        <form method="POST" action="place_order.php" class="order-form">
                            <input type="hidden" name="product_id" value="<?= (int)$product["id"] ?>">
                            <label>Quantity</label>
                            <input type="number" name="quantity" value="1" min="1"
                                   max="<?= (int)$product["stock"] ?>" required>

                            <select name="payment_method" required>
                                <option value="cod">Cash on Delivery</option>
                                <option value="gcash">GCash</option>
                            </select>

                            <button class="btn btn-primary" type="submit">Place Order</button>
                        </form>
                    <?php else: ?>
                        <p>Out of stock</p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <?php if (!$products): ?>
                <p>No LPG products are currently available.</p>
            <?php endif; ?>
        </div>
    </section>

    <section id="orders" class="panel">
        <div class="page-title">My Orders</div>
        <div class="page-sub">Track your LPG deliveries</div>

        <div class="table-wrap">
            <div style="overflow-x:auto">
                <table>
                    <thead>
                    <tr>
                        <th>Order</th>
                        <th>Product</th>
                        <th>Qty</th>
                        <th>Total</th>
                        <th>Payment</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($orders as $order): ?>
                        <tr>
                            <td>#<?= (int)$order["id"] ?></td>
                            <td><?= htmlspecialchars($order["product_name"] . " " . $order["size"]) ?></td>
                            <td><?= (int)$order["quantity"] ?></td>
                            <td>₱<?= number_format((float)$order["total_amount"], 2) ?></td>
                            <td><?= htmlspecialchars(strtoupper($order["payment_method"])) ?></td>
                            <td><?= htmlspecialchars($order["status"]) ?></td>
                            <td><?= htmlspecialchars($order["created_at"]) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$orders): ?>
                        <tr><td colspan="7">You have no orders yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section id="profile" class="panel">
        <div class="page-title">My Profile</div>
        <div class="page-sub">Your account information</div>

        <div class="profile-card">
            <p><strong>Full Name:</strong> <?= htmlspecialchars($profile["full_name"]) ?></p>
            <p><strong>Email:</strong> <?= htmlspecialchars($profile["email"]) ?></p>
            <p><strong>Phone:</strong> <?= htmlspecialchars($profile["phone"]) ?></p>
            <p><strong>Address:</strong> <?= htmlspecialchars($profile["address"]) ?></p>
            <p><strong>Role:</strong> Customer</p>
        </div>
    </section>
</main>
</body>
</html>
