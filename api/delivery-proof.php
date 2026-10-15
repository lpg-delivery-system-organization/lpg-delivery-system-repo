<?php
/**
 * Proof-of-Delivery AJAX API Endpoint
 * LPG Delivery System v2
 *
 * Single action (`complete`): a rider submits the mandatory proof-of-delivery
 * photo (plus an optional note) for an order that is currently
 * `out_for_delivery`. The photo is validated and stored privately in
 * uploads/proofs/, its path/note/timestamp are written to the order, and only
 * then is the order transitioned to `delivered`. If the transition fails the
 * just-stored photo and the order columns are rolled back, so an order can
 * never be marked delivered without a valid proof on disk.
 *
 * The upload is multipart/form-data, so the CSRF token arrives in $_POST.
 */

if (!headers_sent()) {
    header('Content-Type: application/json; charset=UTF-8');
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Order.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/middleware.php';

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    init_session();
}

if (!is_logged_in()) {
    json_response([
        'success' => false,
        'message' => 'Authentication required.',
        'error'   => 'Unauthorized'
    ], 401);
    return;
}

if ((string)current_user_role() !== 'rider') {
    json_response([
        'success' => false,
        'message' => 'Only riders can submit proof of delivery.',
        'error'   => 'Forbidden'
    ], 403);
    return;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response([
        'success' => false,
        'message' => 'This endpoint only accepts POST requests.',
        'error'   => 'Method Not Allowed'
    ], 405);
    return;
}

if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    json_response([
        'success' => false,
        'message' => 'Invalid or missing CSRF token.',
        'error'   => 'Forbidden'
    ], 403);
    return;
}

$db = Database::connect();
$orderModel = new Order($db);

$orderId = (int)($_POST['order_id'] ?? 0);
$note = sanitize_input($_POST['delivery_note'] ?? '');
if (strlen($note) > 255) {
    $note = substr($note, 0, 255);
}

if ($orderId <= 0) {
    json_response([
        'success' => false,
        'message' => 'Order ID is required.',
        'error'   => 'Bad Request'
    ], 400);
    return;
}

$order = $orderModel->findById($orderId);
if (!$order) {
    json_response([
        'success' => false,
        'message' => "Order #{$orderId} not found.",
        'error'   => 'Not Found'
    ], 404);
    return;
}

if ((int)$order['rider_id'] !== (int)current_user_id()) {
    json_response([
        'success' => false,
        'message' => 'Access denied. You are not assigned to this order.',
        'error'   => 'Forbidden'
    ], 403);
    return;
}

if (($order['status'] ?? '') !== 'out_for_delivery') {
    json_response([
        'success' => false,
        'message' => 'Proof of delivery can only be submitted for an out-for-delivery order.',
        'error'   => 'Invalid State'
    ], 400);
    return;
}

$file = $_FILES['proof_photo'] ?? null;
if (!is_array($file)
    || !isset($file['error'])
    || $file['error'] === UPLOAD_ERR_NO_FILE
    || empty($file['name'])) {
    json_response([
        'success' => false,
        'message' => 'A proof-of-delivery photo is required to complete the delivery.',
        'error'   => 'Bad Request'
    ], 400);
    return;
}

$upload = validate_delivery_proof_upload($file);
if (empty($upload['success'])) {
    json_response([
        'success' => false,
        'message' => $upload['error'] ?? 'The proof-of-delivery photo could not be processed.',
        'error'   => 'Bad Request'
    ], 400);
    return;
}

$storedPath = $upload['relative_path'];

try {
    // Record the proof first, then transition. Order::updateStatus() validates
    // the transition and dispatches the customer/admin notifications.
    $orderModel->setDeliveryProof($orderId, $storedPath, $note !== '' ? $note : null);
    $orderModel->updateStatus($orderId, 'delivered', (int)current_user_id());
} catch (Throwable $e) {
    // Never leave an orphan photo or a proof recorded without the delivered
    // transition: undo both, then report the failure.
    try {
        $orderModel->clearDeliveryProof($orderId);
    } catch (Throwable $ignore) {
        // Best effort - the photo cleanup below is the important part.
    }
    delete_old_delivery_proof($storedPath);

    json_response([
        'success' => false,
        'message' => 'Could not complete the delivery: ' . $e->getMessage(),
        'error'   => 'Server Error'
    ], 500);
    return;
}

json_response([
    'success' => true,
    'message' => "Delivery #{$orderId} completed with proof of delivery.",
    'data'    => [
        'order_id'   => $orderId,
        'proof_path' => $storedPath
    ]
], 200);
return;
