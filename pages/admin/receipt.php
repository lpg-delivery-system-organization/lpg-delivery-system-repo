<?php
/**
 * Admin Order Receipt PDF
 * LPG Delivery System v2
 *
 * Renders an 80mm Philippine-format official receipt for any order, so staff can
 * verify a transaction or re-print a receipt at the counter. The PDF is
 * generated on demand from the `orders` row, so nothing is written to the
 * database.
 *
 * Usage:
 *   pages/admin/receipt.php?id=1042            -> preview in the browser
 *   pages/admin/receipt.php?id=1042&download=1 -> force a save dialog
 *
 * Authorization: administrators only.
 */

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/middleware.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/Order.php';
require_once __DIR__ . '/../../classes/Receipt.php';

require_role('admin');

$orderId = (int)($_GET['id'] ?? 0);

if ($orderId <= 0) {
    if (!empty($GLOBALS['TEST_MODE'])) {
        $GLOBALS['LAST_HTTP_CODE'] = 400;
        return;
    }
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    die('Invalid order ID specified.');
}

$db = Database::connect();
$orderModel = new Order($db);
$order = $orderModel->findById($orderId);

if (!$order) {
    if (!empty($GLOBALS['TEST_MODE'])) {
        $GLOBALS['LAST_HTTP_CODE'] = 404;
        return;
    }
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    die('Receipt not found for this order.');
}

$shouldDownload = isset($_GET['download']) && $_GET['download'] !== '0';

try {
    $pdf = Receipt::buildPdfBytes($order);
} catch (Throwable $e) {
    error_log('Receipt generation failed for order #' . $orderId . ': ' . $e->getMessage());

    if (!empty($GLOBALS['TEST_MODE'])) {
        $GLOBALS['LAST_HTTP_CODE'] = 500;
        return;
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    die('We could not generate the receipt right now. Please try again.');
}

stream_pdf($pdf, Receipt::filename($orderId), $shouldDownload);
