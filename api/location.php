<?php
/**
 * Rider Location AJAX API Endpoint
 * LPG Delivery System v2
 *
 * Supported Actions:
 * - update:  (Rider only) Stores the rider's current GPS coordinates for an active order
 * - get:     (Customer/Admin/Assigned Rider) Fetches the latest rider GPS position for an order
 * - history: (Customer/Assigned Rider) Fetches GPS trail for polyline rendering
 */

if (!headers_sent()) {
    header('Content-Type: application/json; charset=UTF-8');
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Order.php';
require_once __DIR__ . '/../classes/RiderLocation.php';
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

$isReadOnly = ($_SERVER['REQUEST_METHOD'] === 'GET' && in_array($action, ['get', 'history'], true));
if (!$isReadOnly) {
    $csrfToken = $input['csrf_token'] ?? null;
    if (!verify_csrf($csrfToken)) {
        json_response([
            'success' => false,
            'message' => 'Invalid or missing CSRF token.',
            'error'   => 'Forbidden'
        ], 403);
        return;
    }
}

$db = Database::connect();
$orderModel = new Order($db);
$locationModel = new RiderLocation($db);

$currentUserId = (int)current_user_id();
$currentUserRole = (string)current_user_role();

switch ($action) {

    case 'update':
        if ($currentUserRole !== 'rider') {
            json_response([
                'success' => false,
                'message' => 'Only riders can submit GPS coordinates.',
                'error'   => 'Forbidden'
            ], 403);
            return;
        }

        $orderId = (int)($input['order_id'] ?? 0);
        $latitude = (float)($input['latitude'] ?? 0);
        $longitude = (float)($input['longitude'] ?? 0);
        $accuracy = !empty($input['accuracy']) ? (float)$input['accuracy'] : null;

        if ($orderId <= 0) {
            json_response([
                'success' => false,
                'message' => 'Order ID is required.',
                'error'   => 'Bad Request'
            ], 400);
            return;
        }

        if ($latitude == 0 && $longitude == 0) {
            json_response([
                'success' => false,
                'message' => 'Valid GPS coordinates are required.',
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

        if ((int)$order['rider_id'] !== $currentUserId) {
            json_response([
                'success' => false,
                'message' => 'Access denied. You are not assigned to this order.',
                'error'   => 'Forbidden'
            ], 403);
            return;
        }

        $activeStatuses = ['picked_up', 'out_for_delivery'];
        if (!in_array($order['status'], $activeStatuses, true)) {
            json_response([
                'success' => false,
                'message' => 'Location tracking is only available for picked up or out-for-delivery orders.',
                'error'   => 'Invalid State'
            ], 400);
            return;
        }

        try {
            $locationId = $locationModel->create($orderId, $currentUserId, $latitude, $longitude, $accuracy);
            json_response([
                'success' => true,
                'message' => 'Location updated.',
                'data'    => ['location_id' => $locationId]
            ], 200);
            return;
        } catch (InvalidArgumentException $e) {
            json_response([
                'success' => false,
                'message' => $e->getMessage(),
                'error'   => 'Bad Request'
            ], 400);
            return;
        } catch (Throwable $e) {
            json_response([
                'success' => false,
                'message' => 'Failed to store location.',
                'error'   => 'Server Error'
            ], 500);
            return;
        }

    case 'get':
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

        if ($currentUserRole === 'customer') {
            if ((int)$order['customer_id'] !== $currentUserId) {
                json_response([
                    'success' => false,
                    'message' => 'Access denied.',
                    'error'   => 'Forbidden'
                ], 403);
                return;
            }
        } elseif ($currentUserRole === 'rider') {
            if ((int)$order['rider_id'] !== $currentUserId) {
                json_response([
                    'success' => false,
                    'message' => 'Access denied.',
                    'error'   => 'Forbidden'
                ], 403);
                return;
            }
        }

        $location = $locationModel->getLatestByOrder($orderId);

        json_response([
            'success' => true,
            'message' => $location ? 'Location retrieved.' : 'No location data available yet.',
            'data'    => $location
        ], 200);
        return;

    case 'history':
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

        if ($currentUserRole === 'customer') {
            if ((int)$order['customer_id'] !== $currentUserId) {
                json_response([
                    'success' => false,
                    'message' => 'Access denied.',
                    'error'   => 'Forbidden'
                ], 403);
                return;
            }
        } elseif ($currentUserRole === 'rider') {
            if ((int)$order['rider_id'] !== $currentUserId) {
                json_response([
                    'success' => false,
                    'message' => 'Access denied.',
                    'error'   => 'Forbidden'
                ], 403);
                return;
            }
        }

        $riderId = (int)$order['rider_id'];
        $history = $riderId > 0 ? $locationModel->getHistory($orderId, $riderId, 100) : [];

        json_response([
            'success' => true,
            'message' => 'History retrieved.',
            'data'    => $history
        ], 200);
        return;

    default:
        json_response([
            'success' => false,
            'message' => !empty($action) ? "Invalid action '{$action}'." : 'Action parameter is required.',
            'error'   => 'Bad Request'
        ], 400);
        return;
}
