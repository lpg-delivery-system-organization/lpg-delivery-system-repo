<?php
/**
 * Global Helper Functions
 * LPG Delivery System v2
 */

// Load application configuration constants if not already loaded
if (file_exists(__DIR__ . '/../config/app.php')) {
    require_once __DIR__ . '/../config/app.php';
}

if (!defined('BASE_URL')) {
    define('BASE_URL', '/lpg-delivery-system-repo');
}
if (!defined('UPLOAD_PATH')) {
    define('UPLOAD_PATH', dirname(__DIR__) . '/uploads');
}

/**
 * Malaysia / Singapore Standard Time (UTC+8).
 *
 * The application is deployed for Malaysia/Singapore users, so every rendered
 * timestamp (order history, receipts, payment confirmations) must be expressed
 * in this zone regardless of the web server's host timezone. Without this, PHP
 * falls back to the php.ini default (commonly UTC), which silently shifts every
 * displayed time by 8 hours.
 *
 * The MySQL connection timezone is pinned to the same offset in Database.php so
 * NOW()/current_timestamp() agree with PHP on any host. Malaysia
 * (Asia/Kuala_Lumpur) and Singapore (Asia/Singapore) share the identical UTC+8
 * offset with no DST.
 */
if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'Asia/Singapore');
}
date_default_timezone_set(APP_TIMEZONE);

/**
 * Government ID rejection life-cycle defaults.
 *
 * Used when config/app.php is unavailable (CLI tools, bare includes). A
 * rejected applicant has ID_REJECTION_GRACE_HOURS to re-upload before the
 * account is purged, and receives a final reminder once the remaining time
 * falls within ID_REJECTION_REMINDER_LEAD_HOURS.
 */
if (!defined('ID_REJECTION_GRACE_HOURS')) {
    define('ID_REJECTION_GRACE_HOURS', 24);
}
if (!defined('ID_REJECTION_REMINDER_LEAD_HOURS')) {
    define('ID_REJECTION_REMINDER_LEAD_HOURS', 4);
}

/**
 * Delivery coverage defaults.
 *
 * Used when config/app.php is unavailable (CLI tools, bare includes). Keep
 * these identical to config/app.php — they define the circular 3 km delivery
 * zone centered on the store and are the single source of truth for both the
 * on-screen coverage circle and the server-side distance validation.
 *
 * Store: 21 5th Street, corner 6th Avenue, Grace Park East, Caloocan.
 * (Cross-verified: BMF Gas Corporation listing + Philippine DOE LTO record.)
 */
if (!defined('STORE_LATITUDE')) {
    define('STORE_LATITUDE', 14.645962);
}
if (!defined('STORE_LONGITUDE')) {
    define('STORE_LONGITUDE', 120.988134);
}
if (!defined('DELIVERY_RADIUS_METERS')) {
    define('DELIVERY_RADIUS_METERS', 3000);
}

// Shared delivery-coverage (geofence) logic — the same class the order
// submission path uses, so pages and models measure distance identically.
require_once __DIR__ . '/../classes/DeliveryCoverage.php';

/**
 * Straight-line (great-circle) distance in meters between two coordinates.
 *
 * Thin wrapper over DeliveryCoverage::distanceMeters() so procedural page
 * code has a plain function available in the existing helper style.
 *
 * @param float $lat1
 * @param float $lon1
 * @param float $lat2
 * @param float $lon2
 * @return float
 */
function calculate_distance_meters(float $lat1, float $lon1, float $lat2, float $lon2): float {
    return DeliveryCoverage::distanceMeters($lat1, $lon1, $lat2, $lon2);
}

/**
 * Evaluate a customer location against the 3 km store delivery zone.
 *
 * @param mixed $lat
 * @param mixed $lng
 * @return array See DeliveryCoverage::evaluate()
 */
function delivery_coverage_check($lat, $lng): array {
    return DeliveryCoverage::evaluate($lat, $lng);
}

/**
 * Escape HTML special characters for secure output (XSS mitigation)
 *
 * @param string|null $value
 * @return string
 */
function e(?string $value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Generate an application-relative or absolute URL
 *
 * @param string $path
 * @return string
 */
function url(string $path = ''): string {
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        return $path;
    }

    $baseUrl = defined('BASE_URL') ? BASE_URL : '/lpg-delivery-system-repo';

    // Adaptively detect if running under virtualhost root or subfolder
    if (isset($_SERVER['SCRIPT_NAME']) && isset($_SERVER['HTTP_HOST'])) {
        $scriptPath = $_SERVER['SCRIPT_NAME'];
        if (str_starts_with($scriptPath, '/lpg-delivery-system-repo')) {
            $baseUrl = '/lpg-delivery-system-repo';
        } elseif (str_starts_with($scriptPath, '/pages/')) {
            $baseUrl = '';
        } elseif ($scriptPath === '/index.php' || $scriptPath === '/register.php' || $scriptPath === '/forgot-password.php' || $scriptPath === '/logout.php') {
            $baseUrl = '';
        }
    }

    $trimmedBase = rtrim($baseUrl, '/');

    if ($path === '/' || $path === '') {
        return $trimmedBase !== '' ? $trimmedBase . '/' : '/';
    }

    $trimmedPath = ltrim($path, '/');
    return ($trimmedBase !== '' ? $trimmedBase . '/' : '/') . $trimmedPath;
}

/**
 * Generate an absolute URL (scheme + host) for a given path.
 *
 * Unlike url(), this always returns a fully-qualified URL so it can be used
 * in contexts where a scheme/host is required (e.g. PayMongo `success_url`
 * and `cancel_url`, or external redirects). Falls back to http://localhost
 * when the request host is unavailable.
 *
 * @param string $path
 * @return string
 */
function absolute_url(string $path = ''): string {
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        return $path;
    }

    // Detect the scheme (behind a proxy use X-Forwarded-Proto when available).
    $scheme = 'http';
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off') {
        $scheme = 'https';
    } elseif (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $forwarded = strtolower(trim($_SERVER['HTTP_X_FORWARDED_PROTO']));
        $scheme = ($forwarded === 'https') ? 'https' : 'http';
    }

    // Detect the host (behind a proxy use X-Forwarded-Host when available).
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';

    $relative = url($path);
    return $scheme . '://' . $host . $relative;
}

/**
 * Generate asset URL
 *
 * @param string $path
 * @return string
 */
function asset(string $path): string {
    return url($path);
}

/**
 * Generate asset URL with automatic cache-busting based on file modification time.
 *
 * @param string $path
 * @return string
 */
function asset_v(string $path): string {
    $url = asset($path);
    $file = dirname(__DIR__) . '/' . ltrim($path, '/');
    if (is_file($file)) {
        $url .= '?v=' . filemtime($file);
    }
    return $url;
}

/**
 * Redirect to a specified path or URL
 *
 * @param string $path
 * @return void
 */
function redirect(string $path): void {
    $target = (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) ? $path : url($path);

    if (!empty($GLOBALS['TEST_MODE'])) {
        $GLOBALS['LAST_REDIRECT'] = $target;
        $GLOBALS['LAST_HTTP_CODE'] = 302;
        return;
    }

    if (!headers_sent()) {
        header('Location: ' . $target);
    } else {
        echo '<script>window.location.href=' . json_encode($target) . ';</script>';
    }
    exit;
}

/**
 * Redirect an authenticated user to their role-appropriate portal / dashboard
 *
 * @param string|null $role
 * @return void
 */
function redirect_by_role(?string $role = null, bool $splash = false): void {
    if ($role === null) {
        $role = function_exists('current_user_role') ? current_user_role() : ($_SESSION['user_role'] ?? 'customer');
    }

    $qs = $splash ? '?splash=1' : '';

    if ($role === 'admin') {
        redirect('/pages/admin/dashboard.php' . $qs);
    } elseif ($role === 'rider') {
        redirect('/pages/rider/deliveries.php' . $qs);
    } else {
        redirect('/pages/customer/shop.php' . $qs);
    }
}

/**
 * Set a flash notification message in the session
 *
 * @param string $type ('success', 'error', 'warning', 'info')
 * @param string $message
 * @return void
 */
function set_flash(string $type, string $message): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        if (function_exists('init_session')) {
            init_session();
        } elseif (!headers_sent()) {
            @session_start();
        }
    }
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

/**
 * Retrieve and clear the flash notification message from the session
 *
 * @return array|null Associative array with 'type' and 'message' keys, or null if no flash exists
 */
function get_flash(): ?array {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        if (function_exists('init_session')) {
            init_session();
        } elseif (!headers_sent()) {
            @session_start();
        }
    }

    if (!isset($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return is_array($flash) ? $flash : null;
}

/**
 * Check if a flash notification message exists in the session
 *
 * @return bool
 */
function has_flash(): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        if (function_exists('init_session')) {
            init_session();
        } elseif (!headers_sent()) {
            @session_start();
        }
    }
    return isset($_SESSION['flash']) && is_array($_SESSION['flash']);
}

/**
 * Validate and securely store an uploaded government/valid ID file
 *
 * Checks:
 * - PHP file upload error codes
 * - Max file size (5MB)
 * - Strict MIME type inspection with finfo (JPEG, PNG, WebP, PDF)
 * - Generates secure randomized hexadecimal filename
 * - Moves file into uploads/ids/ directory
 *
 * @param array $file Upload file entry from $_FILES['valid_id']
 * @param string|null $destinationDir Custom destination directory (defaults to UPLOAD_PATH/ids)
 * @return array ['success' => bool, 'filename' => string, 'filepath' => string, 'relative_path' => string, 'mime_type' => string, 'size' => int] OR ['success' => false, 'error' => string]
 */
function validate_id_upload(array $file, ?string $destinationDir = null): array {
    // 1. Verify expected structure
    if (!isset($file['error']) || !isset($file['tmp_name']) || !isset($file['size'])) {
        return [
            'success' => false,
            'error' => 'Invalid file upload payload structure.'
        ];
    }

    // 2. Check upload error code
    switch ($file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            return [
                'success' => false,
                'error' => 'No file was uploaded. Please select a valid ID document.'
            ];
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return [
                'success' => false,
                'error' => 'Uploaded file exceeds the maximum allowed size limit of 5 MB.'
            ];
        case UPLOAD_ERR_PARTIAL:
            return [
                'success' => false,
                'error' => 'The file was only partially uploaded. Please try again.'
            ];
        default:
            return [
                'success' => false,
                'error' => 'An unexpected file upload error occurred (code ' . $file['error'] . ').'
            ];
    }

    // 3. Check file size (max 5 MB)
    $maxSize = 5 * 1024 * 1024; // 5 MB
    if ($file['size'] > $maxSize) {
        return [
            'success' => false,
            'error' => 'File size (' . round($file['size'] / (1024 * 1024), 2) . ' MB) exceeds the maximum limit of 5 MB.'
        ];
    }

    if ($file['size'] <= 0) {
        return [
            'success' => false,
            'error' => 'Uploaded file is empty.'
        ];
    }

    // 4. Check temporary file existence and readability
    if (!file_exists($file['tmp_name']) || !is_readable($file['tmp_name'])) {
        return [
            'success' => false,
            'error' => 'Temporary uploaded file not found or is unreadable.'
        ];
    }

    // 5. Strict MIME type verification using finfo
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if (!$finfo) {
        return [
            'success' => false,
            'error' => 'Failed to initialize MIME inspection service.'
        ];
    }

    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf'
    ];

    if (!array_key_exists($mimeType, $allowedMimes)) {
        return [
            'success' => false,
            'error' => 'Invalid file format (' . $mimeType . '). Only JPG, PNG, WebP, and PDF files are allowed.'
        ];
    }

    $extension = $allowedMimes[$mimeType];

    // 6. Generate randomized unique filename
    $randomHex = bin2hex(random_bytes(16));
    $filename = 'id_' . $randomHex . '.' . $extension;

    // 7. Determine destination directory
    $targetDir = $destinationDir ?? (defined('UPLOAD_PATH') ? UPLOAD_PATH . '/ids' : dirname(__DIR__) . '/uploads/ids');

    if (!is_dir($targetDir)) {
        if (!mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            return [
                'success' => false,
                'error' => 'Unable to create upload directory.'
            ];
        }
    }

    $destinationPath = rtrim($targetDir, '/') . '/' . $filename;

    // 8. Move uploaded file (or copy if simulated in test mode)
    $moved = false;
    if (is_uploaded_file($file['tmp_name'])) {
        $moved = move_uploaded_file($file['tmp_name'], $destinationPath);
    } else {
        $moved = copy($file['tmp_name'], $destinationPath);
    }

    if (!$moved) {
        return [
            'success' => false,
            'error' => 'Failed to move uploaded file to destination.'
        ];
    }

    @chmod($destinationPath, 0644);

    return [
        'success' => true,
        'filename' => $filename,
        'filepath' => $destinationPath,
        'relative_path' => 'uploads/ids/' . $filename,
        'mime_type' => $mimeType,
        'size' => (int)$file['size']
    ];
}

/**
 * Validate and securely store an uploaded profile picture (image only)
 *
 * Checks:
 * - PHP file upload error codes
 * - Max file size (5MB)
 * - Strict MIME type inspection with finfo (JPEG, PNG, WebP only)
 * - Generates secure randomized hexadecimal filename
 * - Moves file into uploads/avatars/ directory
 *
 * @param array $file Upload file entry from $_FILES['profile_picture']
 * @return array ['success' => bool, 'filename' => string, 'filepath' => string, 'relative_path' => string, 'mime_type' => string, 'size' => int] OR ['success' => false, 'error' => string]
 */
function validate_profile_picture_upload(array $file): array {
    // 1. Verify expected structure
    if (!isset($file['error']) || !isset($file['tmp_name']) || !isset($file['size'])) {
        return [
            'success' => false,
            'error' => 'Invalid file upload payload structure.'
        ];
    }

    // 2. Check upload error code
    switch ($file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            return [
                'success' => false,
                'error' => 'No file was uploaded. Please select a profile picture.'
            ];
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return [
                'success' => false,
                'error' => 'Uploaded image exceeds the maximum allowed size limit of 5 MB.'
            ];
        case UPLOAD_ERR_PARTIAL:
            return [
                'success' => false,
                'error' => 'The image was only partially uploaded. Please try again.'
            ];
        default:
            return [
                'success' => false,
                'error' => 'An unexpected file upload error occurred (code ' . $file['error'] . ').'
            ];
    }

    // 3. Check file size (max 5 MB)
    $maxSize = 5 * 1024 * 1024; // 5 MB
    if ($file['size'] > $maxSize) {
        return [
            'success' => false,
            'error' => 'Image size (' . round($file['size'] / (1024 * 1024), 2) . ' MB) exceeds the maximum limit of 5 MB.'
        ];
    }

    if ($file['size'] <= 0) {
        return [
            'success' => false,
            'error' => 'Uploaded image is empty.'
        ];
    }

    // 4. Check temporary file existence and readability
    if (!file_exists($file['tmp_name']) || !is_readable($file['tmp_name'])) {
        return [
            'success' => false,
            'error' => 'Temporary uploaded file not found or is unreadable.'
        ];
    }

    // 5. Strict MIME type verification using finfo (images only)
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if (!$finfo) {
        return [
            'success' => false,
            'error' => 'Failed to initialize MIME inspection service.'
        ];
    }

    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    ];

    if (!array_key_exists($mimeType, $allowedMimes)) {
        return [
            'success' => false,
            'error' => 'Invalid image format (' . $mimeType . '). Only JPG, PNG, and WebP images are allowed.'
        ];
    }

    // 6. Verify it is a valid image via getimagesize()
    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false) {
        return [
            'success' => false,
            'error' => 'The uploaded file is not a valid image.'
        ];
    }

    $extension = $allowedMimes[$mimeType];

    // 7. Generate randomized unique filename
    $randomHex = bin2hex(random_bytes(16));
    $filename = 'avatar_' . $randomHex . '.' . $extension;

    // 8. Determine destination directory
    $targetDir = defined('UPLOAD_PATH') ? UPLOAD_PATH . '/avatars' : dirname(__DIR__) . '/uploads/avatars';

    if (!is_dir($targetDir)) {
        if (!mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            return [
                'success' => false,
                'error' => 'Unable to create upload directory.'
            ];
        }
    }

    $destinationPath = rtrim($targetDir, '/') . '/' . $filename;

    // 9. Move uploaded file (or copy if simulated in test mode)
    $moved = false;
    if (is_uploaded_file($file['tmp_name'])) {
        $moved = move_uploaded_file($file['tmp_name'], $destinationPath);
    } else {
        $moved = copy($file['tmp_name'], $destinationPath);
    }

    if (!$moved) {
        return [
            'success' => false,
            'error' => 'Failed to move uploaded file to destination.'
        ];
    }

    @chmod($destinationPath, 0644);

    return [
        'success' => true,
        'filename' => $filename,
        'filepath' => $destinationPath,
        'relative_path' => 'uploads/avatars/' . $filename,
        'mime_type' => $mimeType,
        'size' => (int)$file['size']
    ];
}

/**
 * Safely delete an old avatar file inside the uploads/avatars directory
 *
 * Only deletes files whose relative path is confirmed to be within
 * uploads/avatars/ to prevent arbitrary file deletion.
 *
 * @param string|null $relativePath e.g. uploads/avatars/avatar_xxx.jpg
 * @return void
 */
function delete_old_avatar(?string $relativePath): void {
    if (empty($relativePath)) {
        return;
    }

    $normalized = str_replace('\\', '/', ltrim($relativePath, '/'));
    if (!str_starts_with($normalized, 'uploads/avatars/') || strpos($normalized, '..') !== false) {
        return;
    }

    $absolutePath = dirname(__DIR__) . '/' . $normalized;
    if (is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

/**
 * Validate and securely store an uploaded product image (image only)
 *
 * Mirrors validate_profile_picture_upload() but targets the uploads/products/
 * directory so catalog images can be uploaded directly by an administrator
 * instead of pasting an image URL.
 *
 * Checks:
 * - PHP file upload error codes
 * - Max file size (5MB)
 * - Strict MIME type inspection with finfo (JPEG, PNG, WebP only)
 * - Valid image verification via getimagesize()
 * - Generates a secure randomized hexadecimal filename
 * - Moves the file into uploads/products/ (or $destinationDir when supplied)
 *
 * @param array       $file           Upload file entry from $_FILES['image_file']
 * @param string|null $destinationDir Optional absolute destination directory
 * @return array ['success' => bool, 'filename' => string, 'filepath' => string, 'relative_path' => string, 'mime_type' => string, 'size' => int] OR ['success' => false, 'error' => string]
 */
function validate_product_image_upload(array $file, ?string $destinationDir = null): array {
    // 1. Verify expected structure
    if (!isset($file['error']) || !isset($file['tmp_name']) || !isset($file['size'])) {
        return [
            'success' => false,
            'error' => 'Invalid file upload payload structure.'
        ];
    }

    // 2. Check upload error code
    switch ($file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            return [
                'success' => false,
                'error' => 'No file was uploaded. Please select a product image.'
            ];
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return [
                'success' => false,
                'error' => 'Uploaded image exceeds the maximum allowed size limit of 5 MB.'
            ];
        case UPLOAD_ERR_PARTIAL:
            return [
                'success' => false,
                'error' => 'The image was only partially uploaded. Please try again.'
            ];
        default:
            return [
                'success' => false,
                'error' => 'An unexpected file upload error occurred (code ' . $file['error'] . ').'
            ];
    }

    // 3. Check file size (max 5 MB)
    $maxSize = 5 * 1024 * 1024; // 5 MB
    if ($file['size'] > $maxSize) {
        return [
            'success' => false,
            'error' => 'Image size (' . round($file['size'] / (1024 * 1024), 2) . ' MB) exceeds the maximum limit of 5 MB.'
        ];
    }

    if ($file['size'] <= 0) {
        return [
            'success' => false,
            'error' => 'Uploaded image is empty.'
        ];
    }

    // 4. Check temporary file existence and readability
    if (!file_exists($file['tmp_name']) || !is_readable($file['tmp_name'])) {
        return [
            'success' => false,
            'error' => 'Temporary uploaded file not found or is unreadable.'
        ];
    }

    // 5. Strict MIME type verification using finfo (images only)
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if (!$finfo) {
        return [
            'success' => false,
            'error' => 'Failed to initialize MIME inspection service.'
        ];
    }

    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    ];

    if (!array_key_exists($mimeType, $allowedMimes)) {
        return [
            'success' => false,
            'error' => 'Invalid image format (' . $mimeType . '). Only JPG, PNG, and WebP images are allowed.'
        ];
    }

    // 6. Verify it is a valid image via getimagesize()
    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false) {
        return [
            'success' => false,
            'error' => 'The uploaded file is not a valid image.'
        ];
    }

    $extension = $allowedMimes[$mimeType];

    // 7. Generate randomized unique filename
    $randomHex = bin2hex(random_bytes(16));
    $filename = 'product_' . $randomHex . '.' . $extension;

    // 8. Determine destination directory
    $targetDir = $destinationDir ?? (defined('UPLOAD_PATH') ? UPLOAD_PATH . '/products' : dirname(__DIR__) . '/uploads/products');

    if (!is_dir($targetDir)) {
        if (!mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            return [
                'success' => false,
                'error' => 'Unable to create upload directory.'
            ];
        }
    }

    $destinationPath = rtrim($targetDir, '/') . '/' . $filename;

    // 9. Move uploaded file (or copy if simulated in test mode)
    $moved = false;
    if (is_uploaded_file($file['tmp_name'])) {
        $moved = move_uploaded_file($file['tmp_name'], $destinationPath);
    } else {
        $moved = copy($file['tmp_name'], $destinationPath);
    }

    if (!$moved) {
        return [
            'success' => false,
            'error' => 'Failed to move uploaded file to destination.'
        ];
    }

    @chmod($destinationPath, 0644);

    return [
        'success' => true,
        'filename' => $filename,
        'filepath' => $destinationPath,
        'relative_path' => 'uploads/products/' . $filename,
        'mime_type' => $mimeType,
        'size' => (int)$file['size']
    ];
}

/**
 * Safely delete an old product image file inside the uploads/products directory
 *
 * Only deletes files whose relative path is confirmed to be within
 * uploads/products/ to prevent arbitrary file deletion.
 *
 * @param string|null $relativePath e.g. uploads/products/product_xxx.jpg
 * @return void
 */
function delete_old_product_image(?string $relativePath): void {
    if (empty($relativePath)) {
        return;
    }

    $normalized = str_replace('\\', '/', ltrim($relativePath, '/'));
    if (!str_starts_with($normalized, 'uploads/products/') || strpos($normalized, '..') !== false) {
        return;
    }

    $absolutePath = dirname(__DIR__) . '/' . $normalized;
    if (is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

/**
 * Format currency in Philippine Peso (PHP)
 *
 * @param float|int|string $amount
 * @return string
 */
function format_currency($amount): string {
    return '₱' . number_format((float)$amount, 2);
}

/**
 * Format a currency amount for contexts limited to the cp1252 core font set
 * (e.g. generated PDF receipts).
 *
 * The Philippine peso sign (U+20B1) does not exist in the WinAnsi/cp1252
 * encoding used by PDF core fonts, so it is emitted as the "PHP" prefix
 * instead. This matches how BIR-registered point-of-sale receipts print
 * peso amounts. Web pages should keep using format_currency() and the real
 * "₱" glyph.
 *
 * @param float|int|string $amount
 * @return string
 */
function format_php_amount($amount): string {
    return 'PHP ' . number_format((float)$amount, 2);
}

/**
 * Format a date string using the Philippine convention
 * (e.g. "September 30, 2026 03:45 PM").
 *
 * Complements format_date(), which is kept terse for tight UI rows. Narrow
 * columns should pass a shorter $format, such as 'M j, Y g:i A'
 * ("Sep 30, 2026 3:45 PM"), because the long month name can overflow.
 *
 * @param string|null $datetime
 * @param string $format
 * @return string
 */
function format_ph_datetime(?string $datetime, string $format = 'F j, Y g:i A'): string {
    if (empty($datetime)) {
        return 'N/A';
    }
    $ts = strtotime($datetime);
    if ($ts === false || $ts <= 0) {
        return 'N/A';
    }
    return date($format, $ts);
}

/**
 * Stream a generated PDF document to the browser.
 *
 * Honours the test harness by recording the response instead of emitting
 * headers, so suites can assert on the payload without a live HTTP request.
 *
 * @param string $bytes Raw PDF document
 * @param string $filename Suggested filename (e.g. "ORD-0000001042.pdf")
 * @param bool $download True to force a save dialog, false to preview inline
 * @return void
 */
function stream_pdf(string $bytes, string $filename, bool $download = false): void {
    // Never let a crafted filename inject extra response headers.
    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'receipt.pdf';

    if (!empty($GLOBALS['TEST_MODE'])) {
        $GLOBALS['LAST_HTTP_CODE'] = 200;
        $GLOBALS['LAST_PDF'] = [
            'filename'   => $safeName,
            'disposition'=> $download ? 'attachment' : 'inline',
            'size'       => strlen($bytes),
            'bytes'      => $bytes,
        ];
        return;
    }

    if (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($bytes));
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $safeName . '"');
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');

    echo $bytes;
    exit;
}

/**
 * Build a CSV document from an array of rows.
 *
 * Rows are arrays; a null row emits a blank line so report sections can be
 * visually separated in spreadsheet apps. Values are cast to string before
 * writing, and a UTF-8 BOM is prepended so Excel detects the encoding.
 *
 * @param array<int, array<int|null>|null> $rows
 * @return string Raw CSV document bytes
 */
function build_csv(array $rows): string {
    $out = fopen('php://temp', 'r+');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
    foreach ($rows as $row) {
        if ($row === null) {
            fwrite($out, "\r\n");
            continue;
        }
        fputcsv($out, array_map('strval', $row));
    }
    rewind($out);
    $csv = stream_get_contents($out) ?: '';
    fclose($out);
    return $csv;
}

/**
 * Stream a generated CSV document to the browser as a download.
 *
 * Follows the stream_pdf() contract: in the test harness the response is
 * recorded on $GLOBALS instead of emitting headers, so suites can assert on
 * the payload without a live HTTP request.
 *
 * @param string $csv      Raw CSV bytes from build_csv()
 * @param string $filename Suggested filename (e.g. "sales-report_daily_2026-10-06.csv")
 * @return void
 */
function stream_csv(string $csv, string $filename): void {
    // Never let a crafted filename inject extra response headers.
    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'report.csv';

    if (!empty($GLOBALS['TEST_MODE'])) {
        $GLOBALS['LAST_HTTP_CODE'] = 200;
        $GLOBALS['LAST_CSV'] = [
            'filename' => $safeName,
            'size'     => strlen($csv),
            'csv'      => $csv,
        ];
        return;
    }

    if (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Length: ' . strlen($csv));
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');

    echo $csv;
    exit;
}

/**
 * Convert a zero-based column index into its spreadsheet letter
 * (0 => A, 1 => B, 26 => AA).
 *
 * @param int $index Zero-based column index
 * @return string
 */
function xlsx_column_letter(int $index): string {
    $index++;
    $letters = '';
    while ($index > 0) {
        $rem     = ($index - 1) % 26;
        $letters = chr(65 + $rem) . $letters;
        $index   = intdiv($index - 1, 26);
    }
    return $letters;
}

/**
 * Escape a value for inclusion in XLSX XML.
 *
 * @param string $value
 * @return string
 */
function xlsx_escape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * The fixed style palette used by build_xlsx().
 *
 * Indexes: 0 default, 1 title, 2 header, 3 label, 4 text, 5 number,
 * 6 total, 7 bold, 8 centered, 9 currency, 10 total currency.
 *
 * @return string styles.xml document
 */
function build_xlsx_styles(): string {
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="&quot;₱&quot;#,##0.00"/></numFmts>'
        . '<fonts count="4">'
        . '<font><sz val="11"/><color theme="1"/><name val="Calibri"/><family val="2"/></font>'
        . '<font><b/><sz val="14"/><color theme="1"/><name val="Calibri"/><family val="2"/></font>'
        . '<font><b/><sz val="9"/><color theme="1"/><name val="Arial"/><family val="2"/></font>'
        . '<font><sz val="9"/><color theme="1"/><name val="Arial"/><family val="2"/></font>'
        . '</fonts>'
        . '<fills count="3">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFEFEFEF"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="2">'
        . '<border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border><left style="thin"><color indexed="64"/></left><right style="thin"><color indexed="64"/></right>'
        . '<top style="thin"><color indexed="64"/></top><bottom style="thin"><color indexed="64"/></bottom><diagonal/></border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="11">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="3" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="3" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="164" fontId="3" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
        . '<xf numFmtId="164" fontId="2" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
}

/**
 * Build a minimal, valid .xlsx workbook from a grid of cells.
 *
 * The grid is position-based like build_csv(): the row index (1-based) is the
 * spreadsheet row and the key within each row is the zero-based column index.
 * A cell may be a scalar (inline string or number) or an array shaped
 * ['v' => value, 's' => styleIndex, 't' => 's'|'n']. Null rows and empty cells
 * are skipped, except cells with a non-default style, which are emitted as
 * styled blanks so borders still render. Strings are written inline (no
 * sharedStrings part) so the archive stays self-contained.
 *
 * @param string $sheetName Worksheet name
 * @param array<int, array<int, mixed>|null> $rows Row => column => cell
 * @param array{cols?: array<int, array{min?: int, max?: int, width?: float}>, merges?: array<int, string>} $options
 * @return string Raw .xlsx bytes (empty string on failure)
 */
function build_xlsx(string $sheetName, array $rows, array $options = []): string {
    $sheetName = preg_replace('/[\[\]:*?\/\\\\]/', ' ', $sheetName) ?? 'Sheet1';
    $sheetName = mb_substr(trim($sheetName), 0, 31);
    if ($sheetName === '') {
        $sheetName = 'Sheet1';
    }

    $sheetRows = '';
    foreach ($rows as $rIdx => $row) {
        if (!is_array($row) || $row === []) {
            continue;
        }
        $rowNum = $rIdx + 1;
        $cellsXml = '';
        ksort($row);
        foreach ($row as $cIdx => $cell) {
            $cIdx  = (int)$cIdx;
            $style = 0;
            $type  = null;
            if (is_array($cell)) {
                $value = $cell['v'] ?? null;
                $style = (int)($cell['s'] ?? 0);
                $type  = $cell['t'] ?? null;
            } else {
                $value = $cell;
            }

            $ref   = xlsx_column_letter($cIdx) . $rowNum;
            $sAttr = $style > 0 ? ' s="' . $style . '"' : '';

            if ($value === null || $value === '') {
                if ($style > 0) {
                    $cellsXml .= '<c r="' . $ref . '"' . $sAttr . '/>';
                }
                continue;
            }

            if ($type === 's' || ($type === null && !is_int($value) && !is_float($value))) {
                $cellsXml .= '<c r="' . $ref . '"' . $sAttr . ' t="inlineStr"><is><t xml:space="preserve">'
                    . xlsx_escape((string)$value) . '</t></is></c>';
            } else {
                $num = is_int($value) ? (string)$value : number_format((float)$value, 2, '.', '');
                $cellsXml .= '<c r="' . $ref . '"' . $sAttr . '><v>' . xlsx_escape($num) . '</v></c>';
            }
        }
        if ($cellsXml === '') {
            continue;
        }
        $sheetRows .= '<row r="' . $rowNum . '">' . $cellsXml . '</row>';
    }

    $colsXml = '';
    if (!empty($options['cols']) && is_array($options['cols'])) {
        $colsXml = '<cols>';
        foreach ($options['cols'] as $col) {
            $min   = (int)($col['min'] ?? 1);
            $max   = (int)($col['max'] ?? $min);
            $width = rtrim(rtrim(number_format((float)($col['width'] ?? 8.43), 4, '.', ''), '0'), '.');
            $colsXml .= '<col min="' . $min . '" max="' . $max . '" width="' . $width . '" customWidth="1"/>';
        }
        $colsXml .= '</cols>';
    }

    $mergesXml = '';
    if (!empty($options['merges']) && is_array($options['merges'])) {
        $merges = array_values(array_filter(array_map('strval', $options['merges'])));
        if ($merges) {
            $mergesXml = '<mergeCells count="' . count($merges) . '">';
            foreach ($merges as $ref) {
                $mergesXml .= '<mergeCell ref="' . xlsx_escape($ref) . '"/>';
            }
            $mergesXml .= '</mergeCells>';
        }
    }

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . $colsXml
        . '<sheetData>' . $sheetRows . '</sheetData>'
        . $mergesXml
        . '</worksheet>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';

    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . xlsx_escape($sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    if ($tmp === false) {
        return '';
    }

    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        return '';
    }
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
    $zip->addFromString('xl/styles.xml', build_xlsx_styles());
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();

    $bytes = file_get_contents($tmp);
    @unlink($tmp);

    return $bytes === false ? '' : $bytes;
}

/**
 * Build the monthly / yearly "Sales and Remittance Report" workbook.
 *
 * Uses the generic build_xlsx() builder to lay out a period breakdown table
 * (one row per day or per month) followed by the five-row cylinder-size
 * summary, so the export matches the printed form for those report types.
 *
 * @param string $title        Report heading (e.g. "MONTHLY SALES AND REMITTANCE REPORT")
 * @param string $branch       Branch name shown under the heading
 * @param string $periodLabel  Period line (e.g. "PERIOD: June 2026")
 * @param string $periodHeader First-column header ("DATE" or "MONTH")
 * @param array<int, array{label: string, orders: int, units: int, gross: float, vat: float, net: float}> $bucketRows
 * @param array{rows?: array<int, array{size: string|float, qty: int, amount: float}>, qty?: int, amount?: float} $sizeSummary
 * @return string Raw .xlsx bytes
 */
function build_sales_breakdown_xlsx(
    string $title,
    string $branch,
    string $periodLabel,
    string $periodHeader,
    array $bucketRows,
    array $sizeSummary
): string {
    $rows = [];

    $rows[0] = [0 => ['v' => $title, 's' => 1]];
    $rows[1] = [0 => ['v' => 'BRANCH: ' . $branch, 's' => 3]];
    $rows[2] = [0 => ['v' => $periodLabel, 's' => 3]];
    $rows[3] = null; // spacer

    $r = 4;
    $rows[$r] = [
        0 => ['v' => $periodHeader, 's' => 2],
        1 => ['v' => 'ORDERS', 's' => 2],
        2 => ['v' => 'QTY', 's' => 2],
        3 => ['v' => 'GROSS SALES (VAT-INCLUSIVE)', 's' => 2],
        4 => ['v' => 'OUTPUT VAT', 's' => 2],
        5 => ['v' => 'NET SALES (VAT-EXCLUSIVE)', 's' => 2],
    ];
    $r++;

    $totals = ['orders' => 0, 'units' => 0, 'gross' => 0.0, 'vat' => 0.0, 'net' => 0.0];
    foreach ($bucketRows as $bucket) {
        $totals['orders'] += (int)$bucket['orders'];
        $totals['units']  += (int)$bucket['units'];
        $totals['gross']  += (float)$bucket['gross'];
        $totals['vat']    += (float)$bucket['vat'];
        $totals['net']    += (float)$bucket['net'];

        $rows[$r] = [
            0 => ['v' => (string)$bucket['label'], 's' => 4],
            1 => ['v' => (int)$bucket['orders'], 's' => 5],
            2 => ['v' => (int)$bucket['units'], 's' => 5],
            3 => ['v' => round((float)$bucket['gross'], 2), 's' => 9],
            4 => ['v' => round((float)$bucket['vat'], 2), 's' => 9],
            5 => ['v' => round((float)$bucket['net'], 2), 's' => 9],
        ];
        $r++;
    }

    $rows[$r] = [
        0 => ['v' => 'TOTAL', 's' => 6],
        1 => ['v' => $totals['orders'], 's' => 6],
        2 => ['v' => $totals['units'], 's' => 6],
        3 => ['v' => round($totals['gross'], 2), 's' => 10],
        4 => ['v' => round($totals['vat'], 2), 's' => 10],
        5 => ['v' => round($totals['net'], 2), 's' => 10],
    ];
    $r += 2; // blank spacer between the table and the cylinder summary

    // Cylinder-size summary block (mirrors the daily remittance form).
    $summaryTitleRow = $r;
    $rows[$r] = [
        0 => ['v' => 'SUMMARY OF SALES', 's' => 2],
        1 => ['v' => null, 's' => 2],
        2 => ['v' => null, 's' => 2],
    ];
    $r++;
    $rows[$r] = [
        0 => ['v' => 'CONTENT', 's' => 2],
        1 => ['v' => 'QTY', 's' => 2],
        2 => ['v' => 'AMOUNT', 's' => 2],
    ];
    $r++;
    foreach (($sizeSummary['rows'] ?? []) as $sizeRow) {
        $rows[$r] = [
            0 => ['v' => (string)$sizeRow['size'] . ' KG', 's' => 4],
            1 => ['v' => (int)$sizeRow['qty'], 's' => 5],
            2 => ['v' => round((float)$sizeRow['amount'], 2), 's' => 9],
        ];
        $r++;
    }
    $rows[$r] = [
        0 => ['v' => 'TOTAL', 's' => 6],
        1 => ['v' => (int)($sizeSummary['qty'] ?? 0), 's' => 6],
        2 => ['v' => round((float)($sizeSummary['amount'] ?? 0), 2), 's' => 10],
    ];

    return build_xlsx('Sales Report', $rows, [
        'cols' => [
            ['min' => 1, 'max' => 1, 'width' => 22],
            ['min' => 2, 'max' => 2, 'width' => 10],
            ['min' => 3, 'max' => 3, 'width' => 9],
            ['min' => 4, 'max' => 4, 'width' => 24],
            ['min' => 5, 'max' => 5, 'width' => 13],
            ['min' => 6, 'max' => 6, 'width' => 24],
        ],
        'merges' => [
            'A1:F1',
            'A' . ($summaryTitleRow + 1) . ':C' . ($summaryTitleRow + 1),
        ],
    ]);
}

/**
 * Stream a generated Excel workbook to the browser as a download.
 *
 * Follows the stream_csv() contract: in the test harness the response is
 * recorded on $GLOBALS instead of emitting headers, so suites can assert on
 * the payload without a live HTTP request.
 *
 * @param string $bytes    Raw .xlsx bytes from build_xlsx()
 * @param string $filename Suggested filename (e.g. "sales-report_daily_2026-10-06.xlsx")
 * @return void
 */
function stream_xlsx(string $bytes, string $filename): void {
    // Never let a crafted filename inject extra response headers.
    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'report.xlsx';

    if (!empty($GLOBALS['TEST_MODE'])) {
        $GLOBALS['LAST_HTTP_CODE'] = 200;
        $GLOBALS['LAST_XLSX'] = [
            'filename' => $safeName,
            'size'     => strlen($bytes),
            'xlsx'     => $bytes,
        ];
        return;
    }

    if (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Length: ' . strlen($bytes));
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');

    echo $bytes;
    exit;
}

/**
 * Fill a pre-styled .xlsx template with values, preserving every aspect of its
 * formatting exactly — cell styles, borders, merged ranges, column widths and
 * row heights all come straight from the template.
 *
 * Only cell values are rewritten; each targeted cell keeps its existing `s`
 * (style) attribute, so the exported workbook is visually identical to the
 * template. String values are written as inline strings, numeric values as
 * <v>, and a null/'' value blanks the cell while keeping its styling.
 *
 * Cells listed in $options['currency'] additionally receive a real numeric
 * Philippine-peso number format ("₱"#,##0.00): a custom numFmt is injected into
 * xl/styles.xml and each such cell is repointed at a clone of its own style, so
 * its font, fill, border and alignment are preserved.
 *
 * @param string $templatePath Absolute path to a .xlsx template
 * @param array<string, string|int|float|null> $cells Map of cell ref => value
 * @param array{currency?: array<int, string>} $options
 * @return string Raw .xlsx bytes (empty string on failure)
 */
function fill_xlsx_template(string $templatePath, array $cells, array $options = []): string {
    if (!is_file($templatePath)) {
        return '';
    }

    $tmp = tempnam(sys_get_temp_dir(), 'xlsxf');
    if ($tmp === false) {
        return '';
    }
    if (!@copy($templatePath, $tmp)) {
        @unlink($tmp);
        return '';
    }

    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
        @unlink($tmp);
        return '';
    }

    $sheetName  = 'xl/worksheets/sheet1.xml';
    $stylesName = 'xl/styles.xml';
    $sheetXml   = $zip->getFromName($sheetName);
    $stylesXml  = $zip->getFromName($stylesName);
    if ($sheetXml === false || $stylesXml === false) {
        $zip->close();
        @unlink($tmp);
        return '';
    }

    $ns  = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $doc = new DOMDocument();
    $doc->formatOutput    = false;
    $doc->preserveWhiteSpace = true;
    if (!@$doc->loadXML($sheetXml)) {
        $zip->close();
        @unlink($tmp);
        return '';
    }

    // Snapshot every <c> by ref before mutating, so the live NodeList can't
    // shift underneath us.
    $index = [];
    foreach ($doc->getElementsByTagNameNS($ns, 'c') as $node) {
        $ref = $node->getAttribute('r');
        if ($ref !== '') {
            $index[$ref] = $node;
        }
    }

    foreach ($cells as $ref => $value) {
        if (!isset($index[$ref])) {
            continue; // template has no such styled cell; leave it alone
        }
        $cell = $index[$ref];

        while ($cell->firstChild !== null) {
            $cell->removeChild($cell->firstChild);
        }
        $cell->removeAttribute('t');

        if ($value === null || $value === '') {
            continue; // blank value, style/borders preserved
        }

        if (is_int($value) || is_float($value)) {
            $num = is_int($value)
                ? (string)$value
                : rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.');
            if ($num === '' || $num === '-0') {
                $num = '0';
            }
            $v = $doc->createElementNS($ns, 'v');
            $v->appendChild($doc->createTextNode($num));
            $cell->appendChild($v);
            continue;
        }

        $cell->setAttribute('t', 'inlineStr');
        $is = $doc->createElementNS($ns, 'is');
        $t  = $doc->createElementNS($ns, 't');
        $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
        $t->appendChild($doc->createTextNode((string)$value));
        $is->appendChild($t);
        $cell->appendChild($is);
    }

    // Inject a real numeric peso number format for the requested money cells.
    $currency = array_values(array_filter(array_map('strval', (array)($options['currency'] ?? []))));
    if ($currency) {
        $sdoc = new DOMDocument();
        $sdoc->formatOutput       = false;
        $sdoc->preserveWhiteSpace = true;
        if (!@$sdoc->loadXML($stylesXml)) {
            $zip->close();
            @unlink($tmp);
            return '';
        }
        $styleSheet = $sdoc->documentElement;

        // Locate or create <numFmts> (schema requires it first in <styleSheet>).
        $numFmts = null;
        foreach ($styleSheet->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE && $child->localName === 'numFmts') {
                $numFmts = $child;
                break;
            }
        }
        if ($numFmts === null) {
            $numFmts = $sdoc->createElementNS($ns, 'numFmts');
            $styleSheet->insertBefore($numFmts, $styleSheet->firstChild);
        }

        // Pick a free custom numFmtId (builtin ids end at 163).
        $used = [];
        foreach ($numFmts->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE && $child->localName === 'numFmt') {
                $used[(int)$child->getAttribute('numFmtId')] = true;
            }
        }
        $numFmtId = 164;
        while (isset($used[$numFmtId])) {
            $numFmtId++;
        }
        $fmt = $sdoc->createElementNS($ns, 'numFmt');
        $fmt->setAttribute('numFmtId', (string)$numFmtId);
        $fmt->setAttribute('formatCode', '"₱"#,##0.00');
        $numFmts->appendChild($fmt);
        $numFmts->setAttribute('count', (string)$numFmts->getElementsByTagNameNS($ns, 'numFmt')->length);

        // Locate <cellXfs> and its direct <xf> children.
        $cellXfs = null;
        foreach ($styleSheet->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE && $child->localName === 'cellXfs') {
                $cellXfs = $child;
                break;
            }
        }
        if ($cellXfs === null) {
            $zip->close();
            @unlink($tmp);
            return '';
        }
        $xfs = [];
        foreach ($cellXfs->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE && $child->localName === 'xf') {
                $xfs[] = $child;
            }
        }

        // Repoint each money cell at a clone of its own style plus the peso
        // number format, caching one clone per distinct source style.
        $cache = [];
        foreach ($currency as $ref) {
            if (!isset($index[$ref])) {
                continue;
            }
            $cell = $index[$ref];
            $orig = (int)$cell->getAttribute('s');
            if (!isset($xfs[$orig])) {
                continue;
            }
            if (!isset($cache[$orig])) {
                $clone = $xfs[$orig]->cloneNode(true);
                $clone->setAttribute('numFmtId', (string)$numFmtId);
                $clone->setAttribute('applyNumberFormat', '1');
                $cellXfs->appendChild($clone);
                $cache[$orig] = count($xfs);
                $xfs[]        = $clone;
            }
            $cell->setAttribute('s', (string)$cache[$orig]);
        }
        $cellXfs->setAttribute('count', (string)count($xfs));

        $sdoc->encoding      = 'UTF-8';
        $sdoc->xmlStandalone = true;
        $zip->addFromString($stylesName, $sdoc->saveXML());
    }

    $doc->encoding      = 'UTF-8';
    $doc->xmlStandalone = true;
    $zip->addFromString($sheetName, $doc->saveXML());
    $zip->close();

    $bytes = file_get_contents($tmp);
    @unlink($tmp);

    return $bytes === false ? '' : $bytes;
}

/**
 * Build the official, human-readable receipt reference for an order
 * (e.g. "ORD-0000001042").
 *
 * @param int $orderId
 * @return string
 */
function order_receipt_number(int $orderId): string {
    return 'ORD-' . str_pad((string)max(0, $orderId), 10, '0', STR_PAD_LEFT);
}

/**
 * Split a VAT-inclusive Philippine peso amount into its 12% output VAT and
 * net components — BIR Form 2550Q basis: VAT = Gross x 12/112 = Net x 12%
 * (12% of the VAT-exclusive sales base).
 *
 * Example: 1120.00 → ['gross' => 1120.00, 'vat' => 120.00, 'net' => 1000.00]
 *
 * @param float $gross Gross amount that already includes 12% VAT
 * @return array{gross: float, vat: float, net: float}
 */
function sales_vat_split(float $gross): array {
    $gross = round($gross, 2);
    $vat   = round($gross * 12 / 112, 2);
    $net   = round($gross - $vat, 2);
    return ['gross' => $gross, 'vat' => $vat, 'net' => $net];
}

/**
 * Format date string safely
 *
 * @param string|null $datetime
 * @param string $format
 * @return string
 */
function format_date(?string $datetime, string $format = 'M d, Y h:i A'): string {
    if (empty($datetime)) {
        return 'N/A';
    }
    $ts = strtotime($datetime);
    if ($ts === false || $ts <= 0) {
        return 'N/A';
    }
    return date($format, $ts);
}

/**
 * Sanitize plain string input
 *
 * @param string|null $input
 * @return string
 */
function sanitize_input(?string $input): string {
    if ($input === null) {
        return '';
    }
    return trim(str_replace("\0", '', $input));
}

/**
 * Send JSON response and exit
 *
 * @param array $data
 * @param int $statusCode
 * @return void
 */
function json_response(array $data, int $statusCode = 200): void {
    if (!empty($GLOBALS['TEST_MODE'])) {
        $GLOBALS['LAST_HTTP_CODE'] = $statusCode;
        $GLOBALS['LAST_RESPONSE'] = $data;
        return;
    }

    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data);
    exit;
}
