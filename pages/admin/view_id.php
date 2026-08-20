<?php
/**
 * Admin Secure Valid ID Proxy Viewer
 * LPG Delivery System v2
 *
 * Securely streams uploaded government ID documents (JPG, PNG, WebP, PDF)
 * strictly to authenticated administrators with path traversal mitigation.
 */

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/middleware.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/User.php';

// Enforce admin role guard
require_role('admin');

$db = Database::connect();
$userModel = new User($db);

$userId = (int)($_GET['user_id'] ?? $_GET['id'] ?? 0);

if ($userId <= 0) {
    if (!empty($GLOBALS['TEST_MODE'])) {
        $GLOBALS['LAST_HTTP_CODE'] = 400;
        return;
    }
    http_response_code(400);
    die('Invalid user ID specified.');
}

$user = $userModel->findById($userId);

if (!$user || empty($user['valid_id_path'])) {
    if (!empty($GLOBALS['TEST_MODE'])) {
        $GLOBALS['LAST_HTTP_CODE'] = 404;
        return;
    }
    http_response_code(404);
    die('Valid ID document not found for this user.');
}

$relativePath = ltrim($user['valid_id_path'], '/\\');

// Determine base upload directory
$baseUploadDir = defined('UPLOAD_PATH') ? realpath(UPLOAD_PATH) : realpath(dirname(__DIR__, 2) . '/uploads');
if (!$baseUploadDir) {
    $baseUploadDir = dirname(__DIR__, 2) . '/uploads';
}

$fullPath = dirname(__DIR__, 2) . '/' . $relativePath;
$realPath = realpath($fullPath);

// Security Check: Path Traversal Protection
if (!$realPath || !file_exists($realPath) || !str_starts_with($realPath, $baseUploadDir)) {
    if (!empty($GLOBALS['TEST_MODE'])) {
        $GLOBALS['LAST_HTTP_CODE'] = 404;
        return;
    }
    http_response_code(404);
    die('Requested ID document file is missing or path is unauthorized.');
}

// Detect MIME type
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = $finfo ? finfo_file($finfo, $realPath) : 'application/octet-stream';
if ($finfo) {
    finfo_close($finfo);
}

if (!empty($GLOBALS['TEST_MODE'])) {
    $GLOBALS['LAST_HTTP_CODE'] = 200;
    $GLOBALS['LAST_SERVED_FILE'] = [
        'path'      => $realPath,
        'mime_type' => $mimeType,
        'size'      => filesize($realPath),
        'user_id'   => $userId
    ];
    return;
}

// Send streaming response
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($realPath));
header('Content-Disposition: inline; filename="' . basename($realPath) . '"');
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');

readfile($realPath);
exit;
