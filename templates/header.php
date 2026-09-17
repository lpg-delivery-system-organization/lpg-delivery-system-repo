<?php
/**
 * Main Layout Header
 * LPG Delivery System v2
 *
 * Provides the top HTML shell, meta tags, CSRF header token, CDN styles, topbar, and sidebar integration.
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

$loggedIn = is_logged_in();
$userRole = current_user_role();
$userName = $_SESSION['user_name'] ?? 'User';
$userEmail = $_SESSION['user_email'] ?? '';
$userInitial = strtoupper(substr($userName, 0, 1));
$appName = defined('APP_NAME') ? APP_NAME : 'LPG Delivery System';
$pageTitle = isset($page_title) && $page_title !== '' ? $page_title . ' - ' . $appName : $appName;

// Determine brand link based on authentication role
$brandUrl = url('index.php');
if ($loggedIn) {
    if ($userRole === 'admin') {
        $brandUrl = url('pages/admin/dashboard.php');
    } elseif ($userRole === 'rider') {
        $brandUrl = url('pages/rider/deliveries.php');
    } else {
        $brandUrl = url('pages/customer/shop.php');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="csrf-token" content="<?= csrf_token() ?>">
    <meta name="base-url" content="<?= url('/') ?>">
    <?php if ($loggedIn): ?>
    <meta name="user-id" content="<?= current_user_id() ?>">
    <?php endif; ?>
    <title><?= e($pageTitle) ?></title>

    <!-- Google Fonts (Inter) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Bootstrap 5.3.3 CSS CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">

    <!-- Bootstrap Icons 1.11.3 CDN -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <!-- Leaflet Map CSS CDN -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">

    <!-- Animate.css CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">

    <!-- AOS.js CSS CDN (Animate On Scroll) -->
    <link rel="stylesheet" href="https://unpkg.com/aos@2.3.4/dist/aos.css">

    <!-- Prevent flash of wrong theme -->
    <script>
    (function(){var s=localStorage.getItem('app-theme');if(s==='dark')document.documentElement.setAttribute('data-theme','dark');})();
    </script>

    <!-- Custom Application CSS -->
    <link rel="stylesheet" href="<?= asset_v('assets/css/app.css') ?>">
</head>
<body class="app-body d-flex flex-column min-vh-100">

    <!-- Post-Login Splash Screen -->
    <?php if (!empty($_GET['splash'])): ?>
    <div id="appSplash" class="app-splash">
        <div class="app-splash-content">
            <div class="app-splash-logo">
                <i class="bi bi-fire"></i>
            </div>
            <div class="app-splash-text"><?= e($appName) ?></div>
            <div class="app-splash-bar">
                <div class="app-splash-bar-fill"></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Top Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top app-navbar shadow-sm py-2">
        <div class="container-fluid px-3">
            <div class="d-flex align-items-center">
                <?php if ($loggedIn): ?>
                <button class="btn btn-outline-light border-0 me-2 d-lg-none" id="sidebarToggle" type="button" aria-label="Toggle sidebar">
                    <i class="bi bi-list fs-4"></i>
                </button>
                <?php endif; ?>
                <a class="navbar-brand d-flex align-items-center gap-2 fw-bold text-white fs-5" href="<?= e($brandUrl) ?>">
                    <span class="app-logo-badge d-inline-flex align-items-center justify-content-center" style="background: linear-gradient(135deg, #0d9488, #14b8a6); border-radius: 1.25rem; box-shadow: 0 4px 12px rgba(13, 148, 136, 0.3); padding: 0.35rem;">
                        <i class="bi bi-fire" style="color: #fbbf24;"></i>
                    </span>
                    <span class="d-none d-sm-inline"><?= e($appName) ?></span>
                </a>
            </div>

            <div class="d-flex align-items-center gap-2">
                <?php if ($loggedIn): ?>
                    <!-- Theme Toggle -->
                    <button type="button" class="theme-toggle-btn" id="themeToggle" title="Toggle dark mode" aria-label="Toggle dark mode">
                        <i class="bi bi-moon-fill"></i>
                    </button>

                    <!-- Logout (top right) -->
                    <a href="<?= url('logout.php') ?>" class="btn btn-outline-danger btn-sm px-3 fw-semibold" title="Logout">
                        <i class="bi bi-box-arrow-right me-1"></i>Logout
                    </a>
                <?php else: ?>
                    <a href="<?= url('index.php') ?>" class="btn btn-outline-light btn-sm px-3">Login</a>
                    <a href="<?= url('index.php?tab=register') ?>" class="btn btn-primary btn-sm px-3">Register</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- Main Layout Container -->
    <div class="app-layout d-flex flex-grow-1">
        <?php if ($loggedIn): ?>
            <?php require_once __DIR__ . '/sidebar.php'; ?>
        <?php endif; ?>

        <div class="app-main-wrapper flex-grow-1 d-flex flex-column">
            <main class="app-main flex-grow-1 p-3 p-md-4">
                <!-- Session Flash Notification Area -->
                <?php require_once __DIR__ . '/components/alert.php'; ?>
