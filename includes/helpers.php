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
 * Format currency in Philippine Peso (PHP)
 *
 * @param float|int|string $amount
 * @return string
 */
function format_currency($amount): string {
    return '₱' . number_format((float)$amount, 2);
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
