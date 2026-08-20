<?php
/**
 * Application Configuration
 * LPG Delivery System v2
 */

$baseUrl = '/lpg-delivery-system-repo-2';
$appName = 'LPG Delivery System';
$uploadPath = dirname(__DIR__) . '/uploads';

if (!defined('BASE_URL')) {
    define('BASE_URL', $baseUrl);
}
if (!defined('APP_NAME')) {
    define('APP_NAME', $appName);
}
if (!defined('UPLOAD_PATH')) {
    define('UPLOAD_PATH', $uploadPath);
}

return [
    'app_name' => $appName,
    'base_url' => $baseUrl,
    'upload_path' => $uploadPath,
    'session_lifetime' => 1800, // 30 minutes
    'max_login_attempts' => 5,
    'rate_limit_lockout' => 900, // 15 minutes
    'allowed_id_mime_types' => [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf'
    ],
    'max_upload_size' => 5 * 1024 * 1024 // 5 MB
];
