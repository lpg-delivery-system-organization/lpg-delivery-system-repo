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
require_once __DIR__ . '/../classes/Notification.php';

if (!headers_sent()) {
    header('Content-Type: application/json; charset=UTF-8');
}

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    init_session();
}

if (!is_logged_in()) {
    json_response(['success' => false, 'error' => 'Authentication required.'], 401);
    return;
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
            return;
        }
        if (!verify_csrf($input['csrf_token'] ?? null)) {
            json_response(['success' => false, 'error' => 'Invalid security token.'], 403);
            return;
        }

        $orderId = (int)($input['order_id'] ?? 0);
        $message = trim($input['message'] ?? '');

        if ($orderId <= 0) {
            json_response(['success' => false, 'error' => 'Invalid order ID.'], 400);
            return;
        }
        if ($message === '') {
            json_response(['success' => false, 'error' => 'Message cannot be empty.'], 400);
            return;
        }

        // Verify order exists and user is authorized (customer or rider)
        $order = $orderModel->findById($orderId);
        if (!$order) {
            json_response(['success' => false, 'error' => 'Order not found.'], 404);
            return;
        }

        $isCustomer = ($userRole === 'customer' && (int)$order['customer_id'] === $userId);
        $isRider = ($userRole === 'rider' && !empty($order['rider_id']) && (int)$order['rider_id'] === $userId);

        if (!$isCustomer && !$isRider) {
            json_response(['success' => false, 'error' => 'You are not authorized to chat on this order.'], 403);
            return;
        }

        try {
            $msgId = $chatModel->send($orderId, $userId, $message);

            // Notify the other party in the conversation so they get a popup
            // even when they are not currently viewing the chat panel.
            $peerId = null;
            $peerLink = null;
            $senderLabel = null;

            if ($isCustomer && !empty($order['rider_id'])) {
                $peerId = (int)$order['rider_id'];
                $peerLink = 'pages/rider/order-detail.php?id=' . $orderId;
                $senderLabel = trim((string)($order['customer_name'] ?? '')) ?: 'Customer';
            } elseif ($isRider) {
                $peerId = (int)$order['customer_id'];
                $peerLink = 'pages/customer/order-detail.php?id=' . $orderId;
                $senderLabel = trim((string)($order['rider_name'] ?? '')) ?: 'Rider';
            }

            if ($peerId && $peerId > 0) {
                try {
                    $preview = mb_strlen($message) > 80 ? mb_substr($message, 0, 80) . '...' : $message;
                    $notification = new Notification($db);
                    $notification->create(
                        $peerId,
                        'chat_message',
                        'New message on Order #' . $orderId,
                        $senderLabel . ': ' . $preview,
                        $orderId,
                        $peerLink
                    );
                } catch (Throwable $e) {
                    // Never fail a chat message because of a notification issue.
                }
            }

            json_response([
                'success' => true,
                'message' => 'Message sent.',
                'data'    => ['id' => $msgId],
            ]);
            return;
        } catch (Throwable $e) {
            json_response(['success' => false, 'error' => $e->getMessage()], 500);
            return;
        }
        break;

    // ── Poll for New Messages ─────────────────────────────────────────
    case 'poll':
        $orderId = (int)($input['order_id'] ?? 0);
        $afterId = (int)($input['after_id'] ?? 0);

        if ($orderId <= 0) {
            json_response(['success' => false, 'error' => 'Invalid order ID.'], 400);
            return;
        }

        $order = $orderModel->findById($orderId);
        if (!$order) {
            json_response(['success' => false, 'error' => 'Order not found.'], 404);
            return;
        }

        $isCustomer = ($userRole === 'customer' && (int)$order['customer_id'] === $userId);
        $isRider = ($userRole === 'rider' && !empty($order['rider_id']) && (int)$order['rider_id'] === $userId);

        if (!$isCustomer && !$isRider) {
            json_response(['success' => false, 'error' => 'Unauthorized.'], 403);
            return;
        }

        $messages = $chatModel->getByOrder($orderId, $afterId > 0 ? $afterId : null, 100);
        json_response([
            'success' => true,
            'data'    => array_map(fn($msg) => [
                'id'         => (int)$msg['id'],
                'order_id'   => (int)$msg['order_id'],
                'sender_id'  => (int)$msg['sender_id'],
                'message'    => $msg['message'],
                'created_at' => date('F d, Y h:i:s a', strtotime($msg['created_at'])),
            ], $messages),
        ]);
        break;

    // ── Unread Count ──────────────────────────────────────────────────
    case 'unread':
        $orderId = (int)($input['order_id'] ?? 0);
        $lastSeenId = (int)($input['last_seen_id'] ?? 0);

        if ($orderId <= 0) {
            json_response(['success' => false, 'error' => 'Invalid order ID.'], 400);
            return;
        }

        $order = $orderModel->findById($orderId);
        if (!$order) {
            json_response(['success' => false, 'error' => 'Order not found.'], 404);
            return;
        }

        $isCustomer = ($userRole === 'customer' && (int)$order['customer_id'] === $userId);
        $isRider = ($userRole === 'rider' && !empty($order['rider_id']) && (int)$order['rider_id'] === $userId);

        if (!$isCustomer && !$isRider) {
            json_response(['success' => false, 'error' => 'Unauthorized.'], 403);
            return;
        }

        $count = $chatModel->getUnreadCount($orderId, $userId, $lastSeenId);
        json_response([
            'success' => true,
            'data'    => ['unread' => $count],
        ]);
        break;

    default:
        json_response(['success' => false, 'error' => 'Unknown action.'], 400);
        return;
}
