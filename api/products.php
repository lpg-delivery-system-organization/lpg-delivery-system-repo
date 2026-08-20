<?php
/**
 * Products AJAX API Endpoint
 * LPG Delivery System v2
 *
 * Supported Actions (Admin Only):
 * - update_stock:  Updates product inventory count (non-negative integer)
 * - update_price:  Updates product unit price (positive float)
 * - toggle_status: Toggles product state between active and inactive
 * - get_product:   Retrieves product record by ID
 */

// Ensure JSON response header
if (!headers_sent()) {
    header('Content-Type: application/json; charset=UTF-8');
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Product.php';
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
if (empty($input['product_id']) && !empty($_GET['product_id'])) {
    $input['product_id'] = $_GET['product_id'];
}

$action = trim((string)($input['action'] ?? ''));

// 4. CSRF Verification (bypassed only for read-only GET requests)
$isReadOnly = ($_SERVER['REQUEST_METHOD'] === 'GET' && in_array($action, ['get_product', 'details', 'get'], true));
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
$productModel = new Product($db);

// 6. Handle Actions
switch ($action) {
    case 'update_stock':
        $productId = (int)($input['product_id'] ?? 0);
        $stockRaw = $input['stock'] ?? null;

        if ($productId <= 0) {
            json_response([
                'success' => false,
                'message' => 'Product ID is required.',
                'error'   => 'Bad Request'
            ], 400);
            return;
        }

        if ($stockRaw === null || !is_numeric($stockRaw) || (int)$stockRaw < 0 || (int)$stockRaw != $stockRaw) {
            json_response([
                'success' => false,
                'message' => 'Stock must be a non-negative integer.',
                'error'   => 'Bad Request'
            ], 400);
            return;
        }

        $stock = (int)$stockRaw;

        $product = $productModel->findById($productId);
        if (!$product) {
            json_response([
                'success' => false,
                'message' => "Product #{$productId} not found.",
                'error'   => 'Not Found'
            ], 404);
            return;
        }

        try {
            $updated = $productModel->updateStock($productId, $stock);
            if (!$updated) {
                json_response([
                    'success' => false,
                    'message' => 'Failed to update product stock.',
                    'error'   => 'Internal Error'
                ], 500);
                return;
            }

            $freshProduct = $productModel->findById($productId);
            json_response([
                'success' => true,
                'message' => "Stock for '{$product['name']}' updated to {$stock} units.",
                'data'    => $freshProduct
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

    case 'update_price':
        $productId = (int)($input['product_id'] ?? 0);
        $priceRaw = $input['price'] ?? null;

        if ($productId <= 0) {
            json_response([
                'success' => false,
                'message' => 'Product ID is required.',
                'error'   => 'Bad Request'
            ], 400);
            return;
        }

        if ($priceRaw === null || !is_numeric($priceRaw) || (float)$priceRaw <= 0) {
            json_response([
                'success' => false,
                'message' => 'Price must be a positive number greater than zero.',
                'error'   => 'Bad Request'
            ], 400);
            return;
        }

        $price = round((float)$priceRaw, 2);

        $product = $productModel->findById($productId);
        if (!$product) {
            json_response([
                'success' => false,
                'message' => "Product #{$productId} not found.",
                'error'   => 'Not Found'
            ], 404);
            return;
        }

        try {
            $updated = $productModel->updatePrice($productId, $price);
            if (!$updated) {
                json_response([
                    'success' => false,
                    'message' => 'Failed to update product price.',
                    'error'   => 'Internal Error'
                ], 500);
                return;
            }

            $freshProduct = $productModel->findById($productId);
            json_response([
                'success' => true,
                'message' => "Price for '{$product['name']}' updated to " . format_currency($price) . ".",
                'data'    => $freshProduct
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

    case 'toggle_status':
        $productId = (int)($input['product_id'] ?? 0);

        if ($productId <= 0) {
            json_response([
                'success' => false,
                'message' => 'Product ID is required.',
                'error'   => 'Bad Request'
            ], 400);
            return;
        }

        $product = $productModel->findById($productId);
        if (!$product) {
            json_response([
                'success' => false,
                'message' => "Product #{$productId} not found.",
                'error'   => 'Not Found'
            ], 404);
            return;
        }

        try {
            $toggled = $productModel->toggleStatus($productId);
            if (!$toggled) {
                json_response([
                    'success' => false,
                    'message' => 'Failed to toggle product status.',
                    'error'   => 'Internal Error'
                ], 500);
                return;
            }

            $freshProduct = $productModel->findById($productId);
            json_response([
                'success' => true,
                'message' => "Product '{$product['name']}' status changed to '{$freshProduct['status']}'.",
                'data'    => $freshProduct
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

    case 'get_product':
    case 'details':
        $productId = (int)($input['product_id'] ?? 0);

        if ($productId <= 0) {
            json_response([
                'success' => false,
                'message' => 'Product ID is required.',
                'error'   => 'Bad Request'
            ], 400);
            return;
        }

        $product = $productModel->findById($productId);
        if (!$product) {
            json_response([
                'success' => false,
                'message' => "Product #{$productId} not found.",
                'error'   => 'Not Found'
            ], 404);
            return;
        }

        json_response([
            'success' => true,
            'message' => 'Product retrieved successfully.',
            'data'    => $product
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
