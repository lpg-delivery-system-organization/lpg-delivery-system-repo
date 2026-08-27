<?php
/**
 * Orders AJAX API Endpoint
 * LPG Delivery System v2
 *
 * Supported Actions:
 * - update_status: (Admin or Rider) Updates order state along valid state machine transitions
 * - assign_rider:  (Admin only) Concurrency-safe rider dispatch
 * - claim:         (Rider only) Self-assigns available order to logged-in rider
 * - cancel:        (Customer or Admin) Cancels order and restores product stock
 * - get_order:     (Admin, assigned Rider, or Order Owner) Returns order details
 */

// Ensure JSON response header
if (!headers_sent()) {
    header('Content-Type: application/json; charset=UTF-8');
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Product.php';
require_once __DIR__ . '/../classes/Order.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/middleware.php';

// Initialize session if not active
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    init_session();
}

// 1. Authentication Check
if (!is_logged_in()) {
    json_response([
        'success' => false,
        'message' => 'Authentication required. Please log in.',
        'error'   => 'Unauthorized'
    ], 401);
    return;
}

// 2. Parse Request Payload (supports JSON body, $_POST, and $_GET)
$input = $_POST;
$rawBody = file_get_contents('php://input');
if (!empty($rawBody)) {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $input = array_merge($input, $decoded);
    }
}
if (empty($input['action']) && !empty($_GET['action'])) {
    $input['action'] = $_GET['action'];
}
if (empty($input['order_id']) && !empty($_GET['order_id'])) {
    $input['order_id'] = $_GET['order_id'];
}

$action = trim((string)($input['action'] ?? ''));

// 3. CSRF Verification for state-changing operations
// For GET requests with read-only actions (e.g. get_order), CSRF check is bypassed if GET
$isReadOnly = ($_SERVER['REQUEST_METHOD'] === 'GET' && in_array($action, ['get_order', 'details'], true));
if (!$isReadOnly) {
    $csrfToken = $input['csrf_token'] ?? null;
    if (!verify_csrf($csrfToken)) {
        json_response([
            'success' => false,
            'message' => 'Invalid or missing CSRF security token.',
            'error'   => 'Forbidden'
        ], 403);
        return;
    }
}

// 4. Initialize Models
$db = Database::connect();
$orderModel = new Order($db);
$userModel = new User($db);
$productModel = new Product($db);

$currentUser = current_user();
$currentUserId = (int)current_user_id();
$currentUserRole = (string)current_user_role();

// 5. Handle Actions
switch ($action) {
    case 'update_status':
        // Allowed: Admin or Rider
        if ($currentUserRole !== 'admin' && $currentUserRole !== 'rider') {
            json_response([
                'success' => false,
                'message' => 'Access denied. Only administrators and riders can update order status.',
                'error'   => 'Forbidden'
            ], 403);
            return;
        }

        $orderId = (int)($input['order_id'] ?? 0);
        $newStatus = trim((string)($input['status'] ?? ''));

        if ($orderId <= 0 || empty($newStatus)) {
            json_response([
                'success' => false,
                'message' => 'Order ID and status are required.',
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

        // Rider authorization guard: rider can only update orders assigned to them
        if ($currentUserRole === 'rider') {
            if ((int)$order['rider_id'] !== $currentUserId) {
                json_response([
                    'success' => false,
                    'message' => 'Access denied. You can only update orders assigned to you.',
                    'error'   => 'Forbidden'
                ], 403);
                return;
            }
        }

        // Validate state machine transition
        if (!Order::canTransition($order['status'], $newStatus)) {
            json_response([
                'success' => false,
                'message' => "Invalid status transition from '{$order['status']}' to '{$newStatus}'.",
                'error'   => 'Invalid State Transition'
            ], 400);
            return;
        }

        try {
            $updated = $orderModel->updateStatus($orderId, $newStatus);
            if (!$updated) {
                json_response([
                    'success' => false,
                    'message' => 'Failed to update order status.',
                    'error'   => 'Internal Error'
                ], 500);
                return;
            }

            $freshOrder = $orderModel->findById($orderId);
            json_response([
                'success' => true,
                'message' => "Order #{$orderId} status updated to '{$newStatus}'.",
                'data'    => $freshOrder
            ], 200);
            return;
        } catch (InvalidArgumentException $e) {
            json_response([
                'success' => false,
                'message' => $e->getMessage(),
                'error'   => 'Invalid Argument'
            ], 400);
            return;
        } catch (Throwable $e) {
            json_response([
                'success' => false,
                'message' => $e->getMessage(),
                'error'   => 'Server Error'
            ], 500);
            return;
        }

    case 'assign_rider':
        // Allowed: Admin only
        if ($currentUserRole !== 'admin') {
            json_response([
                'success' => false,
                'message' => 'Access denied. Only administrators can assign riders.',
                'error'   => 'Forbidden'
            ], 403);
            return;
        }

        $orderId = (int)($input['order_id'] ?? 0);
        $riderId = (int)($input['rider_id'] ?? 0);
        $status = trim((string)($input['status'] ?? 'picked_up'));

        if ($orderId <= 0 || $riderId <= 0) {
            json_response([
                'success' => false,
                'message' => 'Order ID and Rider ID are required.',
                'error'   => 'Bad Request'
            ], 400);
            return;
        }

        // Validate target rider
        $rider = $userModel->findById($riderId);
        if (!$rider || $rider['role'] !== 'rider') {
            json_response([
                'success' => false,
                'message' => 'Invalid rider account specified.',
                'error'   => 'Bad Request'
            ], 400);
            return;
        }

        if ($rider['status'] !== 'active') {
            json_response([
                'success' => false,
                'message' => 'Cannot assign an inactive or suspended rider.',
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

        // Concurrency check: must be unassigned and in assignable status
        if (!empty($order['rider_id']) || !in_array($order['status'], ['approved', 'ready_for_delivery'], true)) {
            json_response([
                'success' => false,
                'message' => 'Order cannot be assigned or has already been assigned/claimed.',
                'error'   => 'Conflict'
            ], 409);
            return;
        }

        $assigned = $orderModel->assignRider($orderId, $riderId, $status);
        if (!$assigned) {
            json_response([
                'success' => false,
                'message' => 'Failed to assign rider. The order state may have changed.',
                'error'   => 'Conflict'
            ], 409);
            return;
        }

        $freshOrder = $orderModel->findById($orderId);
        json_response([
            'success' => true,
            'message' => "Rider {$rider['full_name']} successfully assigned to Order #{$orderId}.",
            'data'    => $freshOrder
        ], 200);
        return;

    case 'claim':
        // Allowed: Rider only
        if ($currentUserRole !== 'rider') {
            json_response([
                'success' => false,
                'message' => 'Access denied. Only delivery riders can claim orders.',
                'error'   => 'Forbidden'
            ], 403);
            return;
        }

        $orderId = (int)($input['order_id'] ?? 0);
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

        // Check if unassigned and ready
        if (!empty($order['rider_id']) || !in_array($order['status'], ['approved', 'ready_for_delivery'], true)) {
            json_response([
                'success' => false,
                'message' => 'Order is no longer available for claiming.',
                'error'   => 'Conflict'
            ], 409);
            return;
        }

        $claimed = $orderModel->assignRider($orderId, $currentUserId, 'picked_up');
        if (!$claimed) {
            json_response([
                'success' => false,
                'message' => 'Failed to claim order. It may have already been claimed by another rider.',
                'error'   => 'Conflict'
            ], 409);
            return;
        }

        $freshOrder = $orderModel->findById($orderId);
        json_response([
            'success' => true,
            'message' => "Order #{$orderId} claimed successfully.",
            'data'    => $freshOrder
        ], 200);
        return;

    case 'cancel':
        // Allowed: Customer or Admin
        if ($currentUserRole !== 'admin' && $currentUserRole !== 'customer') {
            json_response([
                'success' => false,
                'message' => 'Access denied. Only customers and administrators can cancel orders.',
                'error'   => 'Forbidden'
            ], 403);
            return;
        }

        $orderId = (int)($input['order_id'] ?? 0);
        $reason = trim((string)($input['reason'] ?? ''));

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

        // Customer guard: only own orders and only if status is 'pending'
        if ($currentUserRole === 'customer') {
            if ((int)$order['customer_id'] !== $currentUserId) {
                json_response([
                    'success' => false,
                    'message' => 'Access denied. You can only cancel your own orders.',
                    'error'   => 'Forbidden'
                ], 403);
                return;
            }

            if (!in_array($order['status'], ['pending', 'pending_payment'], true)) {
                json_response([
                    'success' => false,
                    'message' => "Orders with status '{$order['status']}' cannot be cancelled by customers.",
                    'error'   => 'Invalid State'
                ], 400);
                return;
            }
        }

        // Admin guard: check if transition to cancelled is valid
        if ($currentUserRole === 'admin') {
            if (!Order::canTransition($order['status'], 'cancelled')) {
                json_response([
                    'success' => false,
                    'message' => "Order #{$orderId} in status '{$order['status']}' cannot be cancelled.",
                    'error'   => 'Invalid State'
                ], 400);
                return;
            }
        }

        try {
            $defaultReason = ($currentUserRole === 'admin') ? 'Cancelled by Administrator' : 'Cancelled by Customer';
            $cancelReason = !empty($reason) ? $reason : $defaultReason;

            $cancelled = $orderModel->cancel($orderId, $cancelReason);
            if (!$cancelled) {
                json_response([
                    'success' => false,
                    'message' => 'Failed to cancel order.',
                    'error'   => 'Internal Error'
                ], 500);
                return;
            }

            $freshOrder = $orderModel->findById($orderId);
            json_response([
                'success' => true,
                'message' => "Order #{$orderId} has been cancelled and inventory stock was restored.",
                'data'    => $freshOrder
            ], 200);
            return;
        } catch (InvalidArgumentException $e) {
            json_response([
                'success' => false,
                'message' => $e->getMessage(),
                'error'   => 'Invalid Argument'
            ], 400);
            return;
        } catch (Throwable $e) {
            json_response([
                'success' => false,
                'message' => $e->getMessage(),
                'error'   => 'Server Error'
            ], 500);
            return;
        }

    case 'get_order':
    case 'details':
        $orderId = (int)($input['order_id'] ?? 0);
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

        // Authorization check
        if ($currentUserRole === 'customer') {
            if ((int)$order['customer_id'] !== $currentUserId) {
                json_response([
                    'success' => false,
                    'message' => 'Access denied. You can only view your own orders.',
                    'error'   => 'Forbidden'
                ], 403);
                return;
            }
        } elseif ($currentUserRole === 'rider') {
            $isAssigned = ((int)$order['rider_id'] === $currentUserId);
            $isAvailable = (empty($order['rider_id']) && in_array($order['status'], ['approved', 'ready_for_delivery'], true));
            if (!$isAssigned && !$isAvailable) {
                json_response([
                    'success' => false,
                    'message' => 'Access denied. You do not have permission to view this order.',
                    'error'   => 'Forbidden'
                ], 403);
                return;
            }
        }

        json_response([
            'success' => true,
            'message' => 'Order retrieved successfully.',
            'data'    => $order
        ], 200);
        return;

    case 'request_refund':
        // Allowed: Customer (own order) or Admin
        {
            $orderId = (int)($input['order_id'] ?? 0);
            $reason = trim((string)($input['reason'] ?? $input['refund_reason'] ?? ''));
            if ($orderId <= 0) {
                json_response(['success' => false, 'message' => 'Order ID is required.', 'error' => 'Bad Request'], 400);
                return;
            }

            $order = $orderModel->findById($orderId);
            if (!$order) {
                json_response(['success' => false, 'message' => "Order #{$orderId} not found.", 'error' => 'Not Found'], 404);
                return;
            }
            if ($currentUserRole === 'customer' && (int)$order['customer_id'] !== $currentUserId) {
                json_response(['success' => false, 'message' => 'Access denied. You can only request a refund for your own orders.', 'error' => 'Forbidden'], 403);
                return;
            }

            try {
                $done = $orderModel->requestRefund($orderId, $reason);
                json_response([
                    'success' => true,
                    'message' => "Refund request submitted for order #{$orderId}. An administrator will review it shortly.",
                    'data'    => $orderModel->findById($orderId)
                ], 200);
                return;
            } catch (InvalidArgumentException $e) {
                json_response(['success' => false, 'message' => $e->getMessage(), 'error' => 'Invalid Argument'], 400);
                return;
            } catch (Throwable $e) {
                json_response(['success' => false, 'message' => $e->getMessage(), 'error' => 'Server Error'], 500);
                return;
            }
        }

    case 'approve_refund':
        // Allowed: Admin only
        if ($currentUserRole !== 'admin') {
            json_response(['success' => false, 'message' => 'Access denied. Only administrators can approve refunds.', 'error' => 'Forbidden'], 403);
            return;
        }
        {
            $orderId = (int)($input['order_id'] ?? 0);
            if ($orderId <= 0) {
                json_response(['success' => false, 'message' => 'Order ID is required.', 'error' => 'Bad Request'], 400);
                return;
            }

            $result = $orderModel->approveRefund($orderId);
            if (!empty($result['refunded'])) {
                json_response([
                    'success' => true,
                    'message' => "Refund issued for order #{$orderId} and the order was cancelled with stock restored.",
                    'data'    => ['refund_id' => $result['refund_id'] ?? null, 'order' => $orderModel->findById($orderId)]
                ], 200);
                return;
            }

            json_response([
                'success' => false,
                'message' => "Refund could not be completed: " . ($result['error'] ?? 'Unknown error'),
                'error'   => 'Refund Failed'
            ], 400);
            return;
        }

    case 'reject_refund':
        // Allowed: Admin only
        if ($currentUserRole !== 'admin') {
            json_response(['success' => false, 'message' => 'Access denied. Only administrators can reject refunds.', 'error' => 'Forbidden'], 403);
            return;
        }
        {
            $orderId = (int)($input['order_id'] ?? 0);
            $reason = trim((string)($input['reason'] ?? ''));
            if ($orderId <= 0) {
                json_response(['success' => false, 'message' => 'Order ID is required.', 'error' => 'Bad Request'], 400);
                return;
            }

            try {
                $done = $orderModel->rejectRefund($orderId, $reason);
                json_response([
                    'success' => true,
                    'message' => "Refund request for order #{$orderId} was rejected.",
                    'data'    => $orderModel->findById($orderId)
                ], 200);
                return;
            } catch (Throwable $e) {
                json_response(['success' => false, 'message' => $e->getMessage(), 'error' => 'Server Error'], 400);
                return;
            }
        }

    default:
        json_response([
            'success' => false,
            'message' => !empty($action) ? "Invalid action '{$action}'." : 'Action parameter is required.',
            'error'   => 'Bad Request'
        ], 400);
        return;
}
