<?php
/**
 * Application Configuration
 * LPG Delivery System v2
 */

$baseUrl = ''; // dito yung url
$appName = 'Elevate LPG';
$uploadPath = dirname(__DIR__) . '/uploads';
$showDemoCredentials = true;

if (!defined('BASE_URL')) {
    define('BASE_URL', $baseUrl);
}
if (!defined('APP_NAME')) {
    define('APP_NAME', $appName);
}
if (!defined('UPLOAD_PATH')) {
    define('UPLOAD_PATH', $uploadPath);
}

if (!defined('UPLOAD_PATH')) {
    define('UPLOAD_PATH', $uploadPath);
}

if (!defined('SHOW_DEMO_CREDENTIALS')) {
    define('SHOW_DEMO_CREDENTIALS', $showDemoCredentials);
}

// Government ID rejection life-cycle. A rejected applicant gets this many
// hours to re-upload before the account is automatically purged; a final
// reminder is sent once the remaining time drops to the lead window.
$idRejectionGraceHours = 24;
$idRejectionReminderLeadHours = 4;

if (!defined('ID_REJECTION_GRACE_HOURS')) {
    define('ID_REJECTION_GRACE_HOURS', $idRejectionGraceHours);
}
if (!defined('ID_REJECTION_REMINDER_LEAD_HOURS')) {
    define('ID_REJECTION_REMINDER_LEAD_HOURS', $idRejectionReminderLeadHours);
}

// Delivery coverage (3 km radius from the store).
//
// Coordinates below were cross-verified for the address
// "21 5th Street, corner 6th Avenue, Grace Park East, Caloocan"
// (BMF Gas Corporation + matching Philippine DOE LTO record).
//
// VERIFY THE PIN BEFORE GOING LIVE: open Google Maps, right-click the exact
// storefront, click the coordinates to copy them, and paste them here.
$storeLatitude = 14.645962;
$storeLongitude = 120.988134;
$deliveryRadiusMeters = 3000;

if (!defined('STORE_LATITUDE')) {
    define('STORE_LATITUDE', $storeLatitude);
}
if (!defined('STORE_LONGITUDE')) {
    define('STORE_LONGITUDE', $storeLongitude);
}
if (!defined('DELIVERY_RADIUS_METERS')) {
    define('DELIVERY_RADIUS_METERS', $deliveryRadiusMeters);
}

return [
    'app_name' => $appName,
    'base_url' => $baseUrl,
    'upload_path' => $uploadPath,
    'session_lifetime' => 1800, // 30 minutes
    'max_login_attempts' => 5,
    'rate_limit_lockout' => 900, // 15 minutes
    'id_rejection_grace_hours' => $idRejectionGraceHours,
    'id_rejection_reminder_lead_hours' => $idRejectionReminderLeadHours,
    'store_latitude' => $storeLatitude,
    'store_longitude' => $storeLongitude,
    'delivery_radius_meters' => $deliveryRadiusMeters,
    'allowed_id_mime_types' => [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf'
    ],
    'max_upload_size' => 5 * 1024 * 1024 // 5 MB
];
