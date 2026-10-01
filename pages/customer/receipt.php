<?php
/**
 * Customer Order Receipt PDF
 * LPG Delivery System v2
 *
 * Renders an 80mm Philippine-format official receipt for one of the signed-in
 * customer's own orders. The PDF is generated on demand from the `orders` row,
 * so nothing is written to the database.
 *
 * Usage:
 *   pages/customer/receipt.php?id=1042            -> preview in the browser
 *   pages/customer/receipt.php?id=1042&download=1 -> force a save dialog
 *
 * Authorization: the customer must own the order. Riders and admins are refused
 * here and use pages/admin/receipt.php instead.
 */

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/middleware.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/Order.php';
require_once __DIR__ . '/../../classes/Receipt.php';

require_role('customer');

$customerId = (int)current_user_id();
$orderId = (int)($_GET['id'] ?? 0);

if ($orderId <= 0) {
    set_flash('error', 'Invalid order ID specified.');
    redirect('/pages/customer/orders.php');
    return;
}

$db = Database::connect();
$orderModel = new Order($db);
$order = $orderModel->findById($orderId);

// Scope to the signed-in customer: a receipt for someone else's order is
// treated as not found so the endpoint does not leak that the order exists.
if (!$order || (int)$order['customer_id'] !== $customerId) {
    set_flash('error', 'Receipt not found or access unauthorized.');
    redirect('/pages/customer/orders.php');
    return;
}

$shouldDownload = isset($_GET['download']) && $_GET['download'] !== '0';

try {
    $pdf = Receipt::buildPdfBytes($order);
} catch (Throwable $e) {
    error_log('Receipt generation failed for order #' . $orderId . ': ' . $e->getMessage());
    set_flash('error', 'We could not generate your receipt right now. Please try again.');
    redirect('/pages/customer/order-detail.php?id=' . $orderId);
    return;
}

stream_pdf($pdf, Receipt::filename($orderId), $shouldDownload);
