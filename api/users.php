<?php
/**
 * Users AJAX API Endpoint
 * LPG Delivery System v2
 *
 * Supported Actions (Admin Only):
 * - update_status: Updates user account status (active, inactive, suspended) with self-lockout protection
 * - verify_id:     Approves or rejects a customer's uploaded government ID
 * - get_user:      Retrieves sanitized user profile details (password hash excluded)
 */

// Ensure JSON response header
if (!headers_sent()) {
    header('Content-Type: application/json; charset=UTF-8');
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../includes/verification.php';

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

// 2. Authorization Check (Admin Role Required)
if (current_user_role() !== 'admin') {
    json_response([
        'success' => false,
        'message' => 'Access denied. Administrator privileges required.',
        'error'   => 'Forbidden'
    ], 403);
    return;
}

// 3. Parse Request Payload
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
if (empty($input['user_id']) && !empty($_GET['user_id'])) {
    $input['user_id'] = $_GET['user_id'];
}

$action = trim((string)($input['action'] ?? ''));

// 4. CSRF Verification (bypassed only for read-only GET requests)
$isReadOnly = ($_SERVER['REQUEST_METHOD'] === 'GET' && in_array($action, ['get_user', 'details', 'get'], true));
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

// 5. Initialize Model
$db = Database::connect();
$userModel = new User($db);

$currentAdminId = (int)current_user_id();

// 6. Handle Actions
switch ($action) {
    case 'update_status':
        $userId = (int)($input['user_id'] ?? 0);
        $newStatus = trim((string)($input['status'] ?? ''));

        if ($userId <= 0) {
            json_response([
                'success' => false,
                'message' => 'User ID is required.',
                'error'   => 'Bad Request'
            ], 400);
            return;
        }

        $allowedStatuses = ['active', 'inactive', 'suspended'];
        if (!in_array($newStatus, $allowedStatuses, true)) {
            json_response([
                'success' => false,
                'message' => "Invalid status '{$newStatus}'. Allowed: " . implode(', ', $allowedStatuses) . '.',
                'error'   => 'Bad Request'
            ], 400);
            return;
        }

        $user = $userModel->findById($userId);
        if (!$user) {
            json_response([
                'success' => false,
                'message' => "User #{$userId} not found.",
                'error'   => 'Not Found'
            ], 404);
            return;
        }

        // Admin self-lockout guard
        if ($userId === $currentAdminId && in_array($newStatus, ['inactive', 'suspended'], true)) {
            json_response([
                'success' => false,
                'message' => 'You cannot suspend or deactivate your own administrator account.',
                'error'   => 'Self-Lockout Forbidden'
            ], 400);
            return;
        }

        try {
            $updated = $userModel->updateStatus($userId, $newStatus);
            if (!$updated) {
                json_response([
                    'success' => false,
                    'message' => 'Failed to update user status.',
                    'error'   => 'Internal Error'
                ], 500);
                return;
            }

            $freshUser = $userModel->findById($userId);
            // Ensure password hash is never exposed in JSON responses
            unset($freshUser['password']);

            json_response([
                'success' => true,
                'message' => "Account for '{$user['full_name']}' status updated to '{$newStatus}'.",
                'data'    => $freshUser
            ], 200);
            return;
        } catch (Throwable $e) {
            json_response([
                'success' => false,
                'message' => $e->getMessage(),
                'error'   => 'Server Error'
            ], 500);
            return;
        }

    case 'verify_id':
        $userId = (int)($input['user_id'] ?? 0);
        $decision = strtolower(trim((string)($input['decision'] ?? '')));
        $reason = trim((string)($input['reason'] ?? ''));

        if ($userId <= 0) {
            json_response([
                'success' => false,
                'message' => 'User ID is required.',
                'error'   => 'Bad Request'
            ], 400);
            return;
        }

        if (!in_array($decision, ['approve', 'reject'], true)) {
            json_response([
                'success' => false,
                'message' => "Invalid decision '{$decision}'. Allowed: approve, reject.",
                'error'   => 'Bad Request'
            ], 400);
            return;
        }

        $user = $userModel->findById($userId);
        if (!$user) {
            json_response([
                'success' => false,
                'message' => "User #{$userId} not found.",
                'error'   => 'Not Found'
            ], 404);
            return;
        }

        if (empty($user['valid_id_path'])) {
            json_response([
                'success' => false,
                'message' => "{$user['full_name']} has no uploaded ID to verify.",
                'error'   => 'Bad Request'
            ], 400);
            return;
        }

        if ($decision === 'reject' && $reason === '') {
            json_response([
                'success' => false,
                'message' => 'A reason is required when rejecting an ID.',
                'error'   => 'Bad Request'
            ], 400);
            return;
        }

        try {
            if ($decision === 'approve') {
                $userModel->approveId($userId, $currentAdminId);
                notify_user_id_decision($userId, 'approved');
                $message = "Government ID for '{$user['full_name']}' approved.";
            } else {
                $userModel->rejectId($userId, $reason, $currentAdminId);
                notify_user_id_decision($userId, 'rejected', $reason);
                $message = "Government ID for '{$user['full_name']}' rejected.";
            }

            $freshUser = $userModel->findById($userId);
            unset($freshUser['password']);

            json_response([
                'success' => true,
                'message' => $message,
                'data'    => $freshUser
            ], 200);
            return;
        } catch (Throwable $e) {
            json_response([
                'success' => false,
                'message' => $e->getMessage(),
                'error'   => 'Server Error'
            ], 500);
            return;
        }

    case 'get_user':
    case 'details':
        $userId = (int)($input['user_id'] ?? 0);

        if ($userId <= 0) {
            json_response([
                'success' => false,
                'message' => 'User ID is required.',
                'error'   => 'Bad Request'
            ], 400);
            return;
        }

        $user = $userModel->findById($userId);
        if (!$user) {
            json_response([
                'success' => false,
                'message' => "User #{$userId} not found.",
                'error'   => 'Not Found'
            ], 404);
            return;
        }

        // Sanitize sensitive fields (NEVER expose password hash)
        unset($user['password']);

        json_response([
            'success' => true,
            'message' => 'User retrieved successfully.',
            'data'    => $user
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
