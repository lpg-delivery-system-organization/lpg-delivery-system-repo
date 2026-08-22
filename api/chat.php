<?php
/**
 * Chat API Endpoint
 * LPG Delivery System v2
 *
 * Actions:
 *   send  (POST)  — Send a new message in an order chat
 *   poll  (GET)   — Poll for new messages since a given message ID
 *   unread (GET)  — Get unread message count
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Order.php';
require_once __DIR__ . '/../classes/ChatMessage.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    init_session();
}

if (!is_logged_in()) {
    json_response(['success' => false, 'error' => 'Authentication required.'], 401);
}

$currentUser = current_user();
$userId = (int)$currentUser['id'];
$userRole = $currentUser['role'];

$input = array_merge($_POST, json_decode(file_get_contents('php://input'), true) ?? [], $_GET);
$action = trim($input['action'] ?? '');

$db = Database::connect();
$orderModel = new Order($db);
$chatModel = new ChatMessage($db);

switch ($action) {

    // ── Send Message ──────────────────────────────────────────────────
    case 'send':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_response(['success' => false, 'error' => 'POST required.'], 405);
        }
        if (!verify_csrf($input['csrf_token'] ?? null)) {
            json_response(['success' => false, 'error' => 'Invalid security token.'], 403);
        }

        $orderId = (int)($input['order_id'] ?? 0);
        $message = trim($input['message'] ?? '');

        if ($orderId <= 0) {
            json_response(['success' => false, 'error' => 'Invalid order ID.'], 400);
        }
        if ($message === '') {
            json_response(['success' => false, 'error' => 'Message cannot be empty.'], 400);
        }

        // Verify order exists and user is authorized (customer or rider)
        $order = $orderModel->findById($orderId);
        if (!$order) {
            json_response(['success' => false, 'error' => 'Order not found.'], 404);
        }

        $isCustomer = ($userRole === 'customer' && (int)$order['customer_id'] === $userId);
        $isRider = ($userRole === 'rider' && !empty($order['rider_id']) && (int)$order['rider_id'] === $userId);

        if (!$isCustomer && !$isRider) {
            json_response(['success' => false, 'error' => 'You are not authorized to chat on this order.'], 403);
        }

        try {
            $msgId = $chatModel->send($orderId, $userId, $message);
            json_response([
                'success' => true,
                'message' => 'Message sent.',
                'data'    => ['id' => $msgId],
            ]);
        } catch (Throwable $e) {
            json_response(['success' => false, 'error' => $e->getMessage()], 500);
        }
        break;

    // ── Poll for New Messages ─────────────────────────────────────────
    case 'poll':
        $orderId = (int)($input['order_id'] ?? 0);
        $afterId = (int)($input['after_id'] ?? 0);

        if ($orderId <= 0) {
            json_response(['success' => false, 'error' => 'Invalid order ID.'], 400);
        }

        $order = $orderModel->findById($orderId);
        if (!$order) {
            json_response(['success' => false, 'error' => 'Order not found.'], 404);
        }

        $isCustomer = ($userRole === 'customer' && (int)$order['customer_id'] === $userId);
        $isRider = ($userRole === 'rider' && !empty($order['rider_id']) && (int)$order['rider_id'] === $userId);

        if (!$isCustomer && !$isRider) {
            json_response(['success' => false, 'error' => 'Unauthorized.'], 403);
        }

        $messages = $chatModel->getByOrder($orderId, $afterId > 0 ? $afterId : null, 100);
        json_response([
            'success' => true,
            'data'    => $messages,
        ]);
        break;

    // ── Unread Count ──────────────────────────────────────────────────
    case 'unread':
        $orderId = (int)($input['order_id'] ?? 0);
        $lastSeenId = (int)($input['last_seen_id'] ?? 0);

        if ($orderId <= 0) {
            json_response(['success' => false, 'error' => 'Invalid order ID.'], 400);
        }

        $order = $orderModel->findById($orderId);
        if (!$order) {
            json_response(['success' => false, 'error' => 'Order not found.'], 404);
        }

        $isCustomer = ($userRole === 'customer' && (int)$order['customer_id'] === $userId);
        $isRider = ($userRole === 'rider' && !empty($order['rider_id']) && (int)$order['rider_id'] === $userId);

        if (!$isCustomer && !$isRider) {
            json_response(['success' => false, 'error' => 'Unauthorized.'], 403);
        }

        $count = $chatModel->getUnreadCount($orderId, $userId, $lastSeenId);
        json_response([
            'success' => true,
            'data'    => ['unread' => $count],
        ]);
        break;

    default:
        json_response(['success' => false, 'error' => 'Unknown action.'], 400);
}
