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

    <!-- Custom Application CSS -->
    <link rel="stylesheet" href="<?= asset_v('assets/css/app.css') ?>">
</head>
<body class="app-body d-flex flex-column min-vh-100">

    <!-- Top Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top app-navbar shadow-sm py-2">
        <div class="container-fluid px-3">
            <div class="d-flex align-items-center">
                <?php if ($loggedIn): ?>
                    <button class="btn btn-outline-secondary text-white border-0 me-2 d-md-none" id="sidebarToggle" type="button" aria-label="Toggle navigation">
                        <i class="bi bi-list fs-4"></i>
                    </button>
                <?php endif; ?>
                <a class="navbar-brand d-flex align-items-center gap-2 fw-bold text-white fs-5" href="<?= e($brandUrl) ?>">
                    <span class="app-logo-badge bg-primary text-white rounded p-1 d-inline-flex align-items-center justify-content-center">
                        <i class="bi bi-fire text-warning"></i>
                    </span>
                    <span><?= e($appName) ?></span>
                </a>
            </div>

            <div class="d-flex align-items-center gap-3">
                <?php if ($loggedIn): ?>
                    <div class="d-none d-sm-flex align-items-center gap-2">
                        <span class="badge bg-secondary text-uppercase px-2 py-1"><?= e($userRole) ?></span>
                    </div>

                    <!-- User Profile Dropdown -->
                    <div class="dropdown">
                        <a href="#" class="d-flex align-items-center text-white text-decoration-none dropdown-toggle app-user-dropdown" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <?php $userAvatar = $_SESSION['user']['profile_picture'] ?? null; ?>
                            <?php if ($userAvatar && is_file(dirname(__DIR__) . '/' . ltrim($userAvatar, '/'))): ?>
                                <img src="<?= e(url($userAvatar)) ?>" alt="Your profile picture" class="rounded-circle me-2 navbar-avatar-img" style="object-fit: cover;">
                            <?php else: ?>
                                <div class="user-avatar-circle me-2">
                                    <?= e($userInitial) ?>
                                </div>
                            <?php endif; ?>
                            <span class="d-none d-md-inline fw-semibold small text-white"><?= e($userName) ?></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2" aria-labelledby="userDropdown">
                            <li class="px-3 py-2 border-bottom">
                                <div class="fw-bold small text-dark"><?= e($userName) ?></div>
                                <div class="text-muted extra-small"><?= e($userEmail) ?></div>
                            </li>
                            <?php if ($userRole === 'customer'): ?>
                                <li><a class="dropdown-item py-2" href="<?= url('pages/customer/dashboard.php') ?>"><i class="bi bi-speedometer2 me-2 text-primary"></i>Dashboard</a></li>
                                <li><a class="dropdown-item py-2" href="<?= url('pages/customer/shop.php') ?>"><i class="bi bi-shop me-2 text-primary"></i>Shop Products</a></li>
                                <li><a class="dropdown-item py-2" href="<?= url('pages/customer/orders.php') ?>"><i class="bi bi-receipt me-2 text-primary"></i>My Orders</a></li>
                                <li><a class="dropdown-item py-2" href="<?= url('pages/customer/profile.php') ?>"><i class="bi bi-person me-2 text-primary"></i>My Profile</a></li>
                            <?php elseif ($userRole === 'rider'): ?>
                                <li><a class="dropdown-item py-2" href="<?= url('pages/rider/profile.php') ?>"><i class="bi bi-person me-2 text-primary"></i>My Profile</a></li>
                                <li><a class="dropdown-item py-2" href="<?= url('pages/rider/deliveries.php') ?>"><i class="bi bi-truck me-2 text-primary"></i>My Deliveries</a></li>
                            <?php elseif ($userRole === 'admin'): ?>
                                <li><a class="dropdown-item py-2" href="<?= url('pages/admin/dashboard.php') ?>"><i class="bi bi-speedometer2 me-2 text-primary"></i>Dashboard</a></li>
                            <?php endif; ?>
                            <li><hr class="dropdown-divider my-1"></li>
                            <li>
                                <a class="dropdown-item text-danger py-2" href="<?= url('logout.php') ?>">
                                    <i class="bi bi-box-arrow-right me-2"></i>Logout
                                </a>
                            </li>
                        </ul>
                    </div>
                <?php else: ?>
                    <a href="<?= url('index.php') ?>" class="btn btn-outline-light btn-sm px-3">Login</a>
                    <a href="<?= url('register.php') ?>" class="btn btn-primary btn-sm px-3">Register</a>
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
