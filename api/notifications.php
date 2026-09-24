<?php
/**
 * Notifications API Endpoint
 * LPG Delivery System v2
 *
 * Actions:
 *   poll          (GET)  — Poll for notifications newer than a given id + unread count
 *   recent        (GET)  — Recent notifications for the bell dropdown
 *   mark_read     (POST) — Mark specific notification ids as read
 *   mark_all_read (POST) — Mark all notifications as read
 *
 * All data is scoped to the authenticated user.
 *
 * NOTE: Under $GLOBALS['TEST_MODE'] json_response() does not exit, so every
 * response is followed by `return;` (mirroring api/orders.php) to keep the
 * captured HTTP status accurate in the automated test harness.
 */

// Ensure JSON response header
if (!headers_sent()) {
    header('Content-Type: application/json; charset=UTF-8');
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Notification.php';

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    init_session();
}

// 1. Authentication Check
if (!is_logged_in()) {
    json_response(['success' => false, 'error' => 'Authentication required.'], 401);
    return;
}

$userId = (int)current_user_id();

$input = array_merge($_POST, json_decode(file_get_contents('php://input'), true) ?? [], $_GET);
$action = trim($input['action'] ?? '');

$db = Database::connect();
$notificationModel = new Notification($db);

switch ($action) {

    // ── Poll for New Notifications ─────────────────────────────────────
    case 'poll':
        $afterId = (int)($input['after_id'] ?? 0);

        $items = $notificationModel->getNew($userId, $afterId, 50);
        $unread = $notificationModel->getUnreadCount($userId);

        json_response([
            'success' => true,
            'data'    => array_map(fn($n) => [
                'id'         => (int)$n['id'],
                'order_id'   => $n['order_id'] !== null ? (int)$n['order_id'] : null,
                'type'       => $n['type'],
                'title'      => $n['title'],
                'message'    => $n['message'],
                'link'       => $n['link'],
                'created_at' => date('F d, Y h:i:s a', strtotime($n['created_at'])),
            ], $items),
            'unread'  => $unread,
        ]);
        return;

    // ── Recent Notifications (Bell Dropdown) ──────────────────────────
    case 'recent':
        $limit = (int)($input['limit'] ?? 15);

        $items = $notificationModel->getRecent($userId, $limit > 0 ? $limit : 15);
        $unread = $notificationModel->getUnreadCount($userId);

        json_response([
            'success' => true,
            'data'    => array_map(fn($n) => [
                'id'         => (int)$n['id'],
                'order_id'   => $n['order_id'] !== null ? (int)$n['order_id'] : null,
                'type'       => $n['type'],
                'title'      => $n['title'],
                'message'    => $n['message'],
                'link'       => $n['link'],
                'is_read'    => (int)$n['is_read'] === 1,
                'created_at' => date('F d, Y h:i:s a', strtotime($n['created_at'])),
            ], $items),
            'unread'  => $unread,
        ]);
        return;

    // ── Mark Specific Notifications Read ───────────────────────────────
    case 'mark_read':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_response(['success' => false, 'error' => 'POST required.'], 405);
            return;
        }
        if (!verify_csrf($input['csrf_token'] ?? null)) {
            json_response(['success' => false, 'error' => 'Invalid security token.'], 403);
            return;
        }

        $rawIds = $input['ids'] ?? null;
        if (is_string($rawIds)) {
            $rawIds = explode(',', $rawIds);
        }
        if (!is_array($rawIds) || empty($rawIds)) {
            json_response(['success' => false, 'error' => 'No notification IDs provided.'], 400);
            return;
        }

        $ids = array_map(fn($id) => (int)$id, $rawIds);
        $marked = $notificationModel->markRead($userId, $ids);
        if (!$marked) {
            json_response(['success' => false, 'error' => 'No readable notification IDs provided.'], 400);
            return;
        }

        json_response([
            'success' => true,
            'message' => 'Notifications marked as read.',
            'unread'  => $notificationModel->getUnreadCount($userId),
        ]);
        return;

    // ── Mark All Notifications Read ────────────────────────────────────
    case 'mark_all_read':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_response(['success' => false, 'error' => 'POST required.'], 405);
            return;
        }
        if (!verify_csrf($input['csrf_token'] ?? null)) {
            json_response(['success' => false, 'error' => 'Invalid security token.'], 403);
            return;
        }

        $notificationModel->markAllRead($userId);

        json_response([
            'success' => true,
            'message' => 'All notifications marked as read.',
            'unread'  => 0,
        ]);
        return;

    default:
        json_response(['success' => false, 'error' => 'Unknown action.'], 400);
        return;
}