<?php
/**
 * Rider Proof-of-Delivery Proxy Viewer
 * LPG Delivery System v2
 *
 * Securely streams the proof-of-delivery photo for an order assigned to the
 * logged-in rider. Proof files live in the private uploads/proofs/ directory
 * (blocked from direct web access) and are served here with assignment checks
 * and path-traversal mitigation.
 */

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/middleware.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/Order.php';

require_role('rider');

$db = Database::connect();
$orderModel = new Order($db);

$orderId = (int)($_GET['order_id'] ?? $_GET['id'] ?? 0);

if ($orderId <= 0) {
    if (!empty($GLOBALS['TEST_MODE'])) {
        $GLOBALS['LAST_HTTP_CODE'] = 400;
        return;
    }
    http_response_code(400);
    die('Invalid order ID specified.');
}

$order = $orderModel->findById($orderId);

if (!$order || empty($order['delivery_proof_path'])) {
    if (!empty($GLOBALS['TEST_MODE'])) {
        $GLOBALS['LAST_HTTP_CODE'] = 404;
        return;
    }
    http_response_code(404);
    die('Proof of delivery not found for this order.');
}

// Assignment check: riders may only view proofs for orders assigned to them.
if ((int)$order['rider_id'] !== (int)current_user_id()) {
    if (!empty($GLOBALS['TEST_MODE'])) {
        $GLOBALS['LAST_HTTP_CODE'] = 403;
        return;
    }
    http_response_code(403);
    die('You do not have permission to view this proof of delivery.');
}

$relativePath = ltrim($order['delivery_proof_path'], '/\\');

// Only ever serve files from the private proofs directory.
if (!str_starts_with($relativePath, 'uploads/proofs/')) {
    if (!empty($GLOBALS['TEST_MODE'])) {
        $GLOBALS['LAST_HTTP_CODE'] = 403;
        return;
    }
    http_response_code(403);
    die('Requested proof file is outside the permitted directory.');
}

$realPath = upload_abs_path($order['delivery_proof_path']);

// Security Check: Path Traversal Protection
if ($realPath === null) {
    if (!empty($GLOBALS['TEST_MODE'])) {
        $GLOBALS['LAST_HTTP_CODE'] = 404;
        return;
    }
    http_response_code(404);
    die('Requested proof file is missing or path is unauthorized.');
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = $finfo ? finfo_file($finfo, $realPath) : 'application/octet-stream';
if ($finfo) {
    finfo_close($finfo);
}

if (!empty($GLOBALS['TEST_MODE'])) {
    $GLOBALS['LAST_HTTP_CODE'] = 200;
    $GLOBALS['LAST_SERVED_FILE'] = [
        'path'      => $realPath,
        'mime_type' => $mimeType,
        'size'      => filesize($realPath),
        'order_id'  => $orderId
    ];
    return;
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($realPath));
header('Content-Disposition: inline; filename="' . basename($realPath) . '"');
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');

readfile($realPath);
exit;
