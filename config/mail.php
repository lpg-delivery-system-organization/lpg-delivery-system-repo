<?php
/**
 * Mail / SMTP Configuration
 * LPG Delivery System v2
 */

return [
    'host' => 'smtp.gmail.com',
    'port' => 587,
    'encryption' => 'tls', // 'tls' or 'ssl'
    'username' => 'noreply@lpgdeliverysystem.com',
    'password' => '',
    'from_email' => 'noreply@lpgdeliverysystem.com',
    'from_name' => 'LPG Delivery System',
    'mock_mode' => true, // Fallback/mock mode for test and local development environments
];
