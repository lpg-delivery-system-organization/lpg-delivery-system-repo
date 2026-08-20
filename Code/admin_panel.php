<?php
require_once "config.php";
requireRole("admin");

$fullName = $_SESSION["full_name"];

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "logout") {
    session_unset();
    session_destroy();
    header("Location: index.php");
    exit;
}

$totalOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalProducts = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE status = 'active'")->fetchColumn();
$totalSales = (float)$pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status IN ('delivered','completed')")->fetchColumn();

$orders = $pdo->query(
    "SELECT o.id, u.full_name AS customer, p.name AS product, p.size,
            o.quantity, o.total_amount, o.payment_method, o.status, o.created_at
     FROM orders o
     JOIN users u ON u.id = o.customer_id
     JOIN products p ON p.id = o.product_id
     ORDER BY o.created_at DESC"
)->fetchAll();

$products = $pdo->query(
    "SELECT id, name, size, price, stock, status FROM products ORDER BY id ASC"
)->fetchAll();

$users = $pdo->query(
    "SELECT id, full_name, email, phone, role, status, created_at
     FROM users ORDER BY created_at DESC"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LPG Delivery - Admin Panel</title>
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
    <a href="#dashboard">Dashboard</a>
    <a href="#orders">Orders</a>
    <a href="#inventory">Inventory</a>
    <a href="#users">Users</a>
</div>

<main class="app-content">
    <section id="dashboard" class="panel">
        <div class="page-title">Dashboard</div>
        <div class="page-sub">Overview of operations</div>

        <div class="stats-grid">
            <div class="stat-card"><strong><?= $totalOrders ?></strong><span>Total Orders</span></div>
            <div class="stat-card"><strong><?= $totalUsers ?></strong><span>Users</span></div>
            <div class="stat-card"><strong><?= $totalProducts ?></strong><span>Products</span></div>
            <div class="stat-card"><strong>₱<?= number_format($totalSales, 2) ?></strong><span>Completed Sales</span></div>
        </div>

        <div class="table-wrap">
            <h3>Recent Orders</h3>
            <div style="overflow-x:auto">
                <table>
                    <thead>
                    <tr><th>ID</th><th>Customer</th><th>Product</th><th>Qty</th><th>Total</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach (array_slice($orders, 0, 10) as $order): ?>
                        <tr>
                            <td>#<?= (int)$order["id"] ?></td>
                            <td><?= htmlspecialchars($order["customer"]) ?></td>
                            <td><?= htmlspecialchars($order["product"] . " " . $order["size"]) ?></td>
                            <td><?= (int)$order["quantity"] ?></td>
                            <td>₱<?= number_format((float)$order["total_amount"], 2) ?></td>
                            <td><?= htmlspecialchars($order["status"]) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section id="orders" class="panel">
        <div class="page-title">All Orders</div>
        <div class="page-sub">Manage and update order statuses</div>

        <div class="table-wrap">
            <div style="overflow-x:auto">
                <table>
                    <thead>
                    <tr>
                        <th>ID</th><th>Customer</th><th>Product</th><th>Qty</th>
                        <th>Total</th><th>Payment</th><th>Status</th><th>Date</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($orders as $order): ?>
                        <tr>
                            <td>#<?= (int)$order["id"] ?></td>
                            <td><?= htmlspecialchars($order["customer"]) ?></td>
                            <td><?= htmlspecialchars($order["product"] . " " . $order["size"]) ?></td>
                            <td><?= (int)$order["quantity"] ?></td>
                            <td>₱<?= number_format((float)$order["total_amount"], 2) ?></td>
                            <td><?= htmlspecialchars(strtoupper($order["payment_method"])) ?></td>
                            <td><?= htmlspecialchars($order["status"]) ?></td>
                            <td><?= htmlspecialchars($order["created_at"]) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section id="inventory" class="panel">
        <div class="page-title">Inventory</div>
        <div class="page-sub">Manage LPG stock levels</div>

        <div class="table-wrap">
            <div style="overflow-x:auto">
                <table>
                    <thead><tr><th>ID</th><th>Product</th><th>Size</th><th>Price</th><th>Stock</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($products as $product): ?>
                        <tr>
                            <td><?= (int)$product["id"] ?></td>
                            <td><?= htmlspecialchars($product["name"]) ?></td>
                            <td><?= htmlspecialchars($product["size"]) ?></td>
                            <td>₱<?= number_format((float)$product["price"], 2) ?></td>
                            <td><?= (int)$product["stock"] ?></td>
                            <td><?= htmlspecialchars($product["status"]) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section id="users" class="panel">
        <div class="page-title">Users</div>
        <div class="page-sub">Registered accounts</div>

        <div class="table-wrap">
            <div style="overflow-x:auto">
                <table>
                    <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Phone</th><th>Role</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?= (int)$user["id"] ?></td>
                            <td><?= htmlspecialchars($user["full_name"]) ?></td>
                            <td><?= htmlspecialchars($user["email"]) ?></td>
                            <td><?= htmlspecialchars($user["phone"]) ?></td>
                            <td><?= htmlspecialchars($user["role"]) ?></td>
                            <td><?= htmlspecialchars($user["status"]) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</main>
</body>
</html>
