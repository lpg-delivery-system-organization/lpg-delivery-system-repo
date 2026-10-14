<?php
/**
 * Cart AJAX API Endpoint
 * LPG Delivery System v2
 *
 * Customer-only endpoint backing the shop page cart drawer. The cart is a
 * staging area: it never reserves stock and never creates an order — stock is
 * validated again inside Order::place() at checkout.
 *
 * Supported Actions:
 * - list:   (Customer) Returns the current cart with live product data
 * - add:    (Customer) Adds a product or increases its quantity
 * - update: (Customer) Sets an absolute quantity for a cart line
 * - remove: (Customer) Deletes a single product from the cart
 * - clear:  (Customer) Empties the cart
 */

// Ensure JSON response header
if (!headers_sent()) {
    header('Content-Type: application/json; charset=UTF-8');
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Cart.php';
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

$action = trim((string)($input['action'] ?? ''));

// 3. CSRF Verification for state-changing operations
// A GET request for the read-only `list` action is safe to serve without a
// token; every mutation is checked.
$isReadOnly = ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'list');
if (!$isReadOnly) {
    $csrfToken = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (!verify_csrf($csrfToken)) {
        json_response([
            'success' => false,
            'message' => 'Invalid or missing CSRF security token.',
            'error'   => 'Forbidden'
        ], 403);
        return;
    }
}

// 4. Authorization: cart is customer-only
require_role('customer');
require_verified_id();

// 5. Initialize Models
$db = Database::connect();
$cartModel = new Cart($db);
$currentUserId = (int)current_user_id();

/**
 * Shape the cart for the JSON response.
 */
$cartPayload = static function (Cart $cart, int $customerId): array {
    $summary = $cart->getSummary($customerId);
    return [
        'items'    => $summary['items'],
        'count'    => $summary['line_count'],
        'units'    => $summary['units'],
        'subtotal' => $summary['subtotal'],
    ];
};

// 6. Handle Actions
switch ($action) {
    case 'list':
        json_response([
            'success' => true,
            'message' => 'Cart loaded.',
            'cart'    => $cartPayload($cartModel, $currentUserId)
        ], 200);
        return;

    case 'add':
        $productId = (int)($input['product_id'] ?? 0);
        $quantity = (int)($input['quantity'] ?? 1);
        if ($productId <= 0) {
            json_response(['success' => false, 'message' => 'A valid product is required.', 'error' => 'Bad Request'], 400);
            return;
        }

        try {
            $lineQty = $cartModel->addItem($currentUserId, $productId, $quantity);
            json_response([
                'success' => true,
                'message' => 'Added to cart.',
                'line_quantity' => $lineQty,
                'cart'    => $cartPayload($cartModel, $currentUserId)
            ], 200);
            return;
        } catch (InvalidArgumentException $e) {
            json_response(['success' => false, 'message' => $e->getMessage(), 'error' => 'Invalid Argument'], 400);
            return;
        } catch (Throwable $e) {
            json_response(['success' => false, 'message' => $e->getMessage(), 'error' => 'Server Error'], 500);
            return;
        }

    case 'update':
        $productId = (int)($input['product_id'] ?? 0);
        $quantity = (int)($input['quantity'] ?? 0);
        if ($productId <= 0) {
            json_response(['success' => false, 'message' => 'A valid product is required.', 'error' => 'Bad Request'], 400);
            return;
        }

        try {
            $lineQty = $cartModel->updateItem($currentUserId, $productId, $quantity);
            json_response([
                'success' => true,
                'message' => 'Cart updated.',
                'line_quantity' => $lineQty,
                'cart'    => $cartPayload($cartModel, $currentUserId)
            ], 200);
            return;
        } catch (InvalidArgumentException $e) {
            json_response(['success' => false, 'message' => $e->getMessage(), 'error' => 'Invalid Argument'], 400);
            return;
        } catch (Throwable $e) {
            json_response(['success' => false, 'message' => $e->getMessage(), 'error' => 'Server Error'], 500);
            return;
        }

    case 'remove':
        $productId = (int)($input['product_id'] ?? 0);
        if ($productId <= 0) {
            json_response(['success' => false, 'message' => 'A valid product is required.', 'error' => 'Bad Request'], 400);
            return;
        }

        $removed = $cartModel->removeItem($currentUserId, $productId);
        json_response([
            'success' => true,
            'message' => $removed ? 'Removed from cart.' : 'That item was not in your cart.',
            'cart'    => $cartPayload($cartModel, $currentUserId)
        ], 200);
        return;

    case 'clear':
        $cartModel->clear($currentUserId);
        json_response([
            'success' => true,
            'message' => 'Cart cleared.',
            'cart'    => $cartPayload($cartModel, $currentUserId)
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
