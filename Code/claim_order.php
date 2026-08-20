<?php
require_once "config.php";
requireRole("rider");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: rider_panel.php");
    exit;
}

$orderId = (int)($_POST["order_id"] ?? 0);
$riderId = (int)$_SESSION["user_id"];

if ($orderId <= 0) {
    header("Location: rider_panel.php");
    exit;
}

$stmt = $pdo->prepare(
    "UPDATE orders
     SET rider_id = ?, status = 'picked_up'
     WHERE id = ?
       AND rider_id IS NULL
       AND status IN ('approved','ready_for_delivery')"
);
$stmt->execute([$riderId, $orderId]);

header("Location: rider_panel.php#assignments");
exit;
?>
