<?php
/**
 * User Logout Controller
 * LPG Delivery System v2
 */

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';

// Terminate user session and remove session cookies
logout_user();

// Set flash notification and redirect to sign in page
set_flash('info', 'You have been successfully signed out.');
redirect('/index.php?logged_out=1');
