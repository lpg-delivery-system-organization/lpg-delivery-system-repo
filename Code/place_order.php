<?php
require_once "config.php";
requireRole("customer");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: customer_panel.php");
    exit;
}

$customerId = (int)$_SESSION["user_id"];
$productId = (int)($_POST["product_id"] ?? 0);
$quantity = (int)($_POST["quantity"] ?? 0);
$payment = $_POST["payment_method"] ?? "cod";

if ($productId <= 0 || $quantity <= 0 || !in_array($payment, ["cod","gcash"], true)) {
    die("Invalid order data.");
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "SELECT id, price, stock FROM products
         WHERE id = ? AND status = 'active'
         FOR UPDATE"
    );
    $stmt->execute([$productId]);
    $product = $stmt->fetch();

    if (!$product || $product["stock"] < $quantity) {
        throw new RuntimeException("Product is out of stock or the requested quantity is unavailable.");
    }

    $total = (float)$product["price"] * $quantity;

    $stmt = $pdo->prepare(
        "INSERT INTO orders
        (customer_id, product_id, quantity, total_amount, payment_method, status)
        VALUES (?, ?, ?, ?, ?, 'pending')"
    );
    $stmt->execute([$customerId, $productId, $quantity, $total, $payment]);

    $stmt = $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
    $stmt->execute([$quantity, $productId]);

    $pdo->commit();
    header("Location: customer_panel.php#orders");
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    die("Could not place order: " . htmlspecialchars($e->getMessage()));
}
?>
