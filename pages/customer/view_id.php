<?php
/**
 * Customer Secure Valid ID Self-Viewer
 * LPG Delivery System v2
 *
 * Streams the logged-in customer's own uploaded government ID document so the
 * applicant can confirm what they submitted. Access is scoped to the current
 * session user (no arbitrary user_id), with the same path-traversal mitigation
 * used by the admin proxy.
 */

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/middleware.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/User.php';

require_role('customer');

$db = Database::connect();
$userModel = new User($db);

$userId = (int)current_user_id();
$user = $userModel->findById($userId);

if (!$user || empty($user['valid_id_path'])) {
    if (!empty($GLOBALS['TEST_MODE'])) {
        $GLOBALS['LAST_HTTP_CODE'] = 404;
        return;
    }
    http_response_code(404);
    die('No ID document is on file for your account.');
}

$relativePath = ltrim($user['valid_id_path'], '/\\');

$baseUploadDir = defined('UPLOAD_PATH') ? realpath(UPLOAD_PATH) : realpath(dirname(__DIR__, 2) . '/uploads');
if (!$baseUploadDir) {
    $baseUploadDir = dirname(__DIR__, 2) . '/uploads';
}

$fullPath = dirname(__DIR__, 2) . '/' . $relativePath;
$realPath = realpath($fullPath);

if (!$realPath || !file_exists($realPath) || !str_starts_with($realPath, $baseUploadDir)) {
    if (!empty($GLOBALS['TEST_MODE'])) {
        $GLOBALS['LAST_HTTP_CODE'] = 404;
        return;
    }
    http_response_code(404);
    die('Requested ID document file is missing or path is unauthorized.');
}

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

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($realPath));
header('Content-Disposition: inline; filename="' . basename($realPath) . '"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

readfile($realPath);
exit;
