<?php
/**
 * Register Redirect
 * LPG Delivery System v2
 *
 * All auth logic is now handled by index.php (combined login/register).
 * This file simply redirects to keep old bookmarked links working.
 */

require_once __DIR__ . '/includes/helpers.php';

redirect('/index.php?tab=register');
