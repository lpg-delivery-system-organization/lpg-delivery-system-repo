<?php
/**
 * Role-Aware Sidebar Navigation Component
 * LPG Delivery System v2
 */

if (!function_exists('is_logged_in') || !is_logged_in()) {
    return;
}

$role = current_user_role();
$currentPage = $current_page ?? '';
$userName = $_SESSION['user_name'] ?? 'User';
$userEmail = $_SESSION['user_email'] ?? '';
$initial = strtoupper(substr($userName, 0, 1));

// Role menu definition
$menus = [
    'customer' => [
        [
            'key'   => 'dashboard',
            'label' => 'Dashboard',
            'url'   => url('pages/customer/dashboard.php'),
            'icon'  => 'bi-speedometer2'
        ],
        [
            'key'   => 'shop',
            'label' => 'Shop Products',
            'url'   => url('pages/customer/shop.php'),
            'icon'  => 'bi-shop'
        ],
        [
            'key'   => 'orders',
            'label' => 'My Orders',
            'url'   => url('pages/customer/orders.php'),
            'icon'  => 'bi-receipt'
        ],
        [
            'key'   => 'profile',
            'label' => 'My Profile',
            'url'   => url('pages/customer/profile.php'),
            'icon'  => 'bi-person-circle'
        ]
    ],
    'admin' => [
        [
            'key'   => 'dashboard',
            'label' => 'Dashboard',
            'url'   => url('pages/admin/dashboard.php'),
            'icon'  => 'bi-speedometer2'
        ],
        [
            'key'   => 'orders',
            'label' => 'Order Management',
            'url'   => url('pages/admin/orders.php'),
            'icon'  => 'bi-box-seam'
        ],
        [
            'key'   => 'inventory',
            'label' => 'Inventory',
            'url'   => url('pages/admin/inventory.php'),
            'icon'  => 'bi-tags'
        ],
        [
            'key'   => 'users',
            'label' => 'User Management',
            'url'   => url('pages/admin/users.php'),
            'icon'  => 'bi-people'
        ]
    ],
    'rider' => [
        [
            'key'   => 'deliveries',
            'label' => 'My Deliveries',
            'url'   => url('pages/rider/deliveries.php'),
            'icon'  => 'bi-truck'
        ],
        [
            'key'   => 'available',
            'label' => 'Available Orders',
            'url'   => url('pages/rider/available.php'),
            'icon'  => 'bi-inbox'
        ],
        [
            'key'   => 'profile',
            'label' => 'My Profile',
            'url'   => url('pages/rider/profile.php'),
            'icon'  => 'bi-person-circle'
        ]
    ]
];

$roleMenu = $menus[$role] ?? [];
?>

<!-- Mobile Sidebar Backdrop -->
<div class="sidebar-backdrop d-lg-none" id="sidebarBackdrop"></div>

<aside class="app-sidebar bg-white border-end shadow-sm" id="appSidebar">
    <div class="app-sidebar-inner d-flex flex-column h-100">
        <!-- Sidebar Navigation Heading -->
        <div class="px-3 py-3 border-bottom d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-2">
                <span class="badge text-uppercase px-2 py-1 fw-semibold" style="background: var(--app-gradient-primary); font-size: 0.7rem;"><?= e($role) ?></span>
                <span class="small text-muted fw-semibold sidebar-header-text">Portal</span>
            </div>
        </div>

        <!-- Navigation Links -->
        <div class="app-sidebar-body flex-grow-1 py-3 px-2 overflow-y-auto">
            <ul class="nav nav-pills flex-column gap-1">
                <?php foreach ($roleMenu as $item): ?>
                    <?php $isActive = ($currentPage === $item['key']); ?>
                    <li class="nav-item">
                        <a class="nav-link d-flex align-items-center gap-2 px-3 py-2 <?= $isActive ? 'active' : 'text-dark' ?>" 
                           href="<?= e($item['url']) ?>"
                           data-menu-key="<?= e($item['key']) ?>"
                           title="<?= e($item['label']) ?>">
                            <i class="bi <?= e($item['icon']) ?> fs-5"></i>
                            <span><?= e($item['label']) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- Sidebar User Footer (hidden by default, shown on scroll) -->
        <div class="app-sidebar-footer border-top p-3 bg-light" id="sidebarUserFooter" style="display: none;">
            <div class="d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center gap-2 overflow-hidden">
                    <?php $sidebarAvatar = $_SESSION['user']['profile_picture'] ?? null; ?>
                    <?php if ($sidebarAvatar && is_file(dirname(__DIR__) . '/' . ltrim($sidebarAvatar, '/'))): ?>
                        <img src="<?= e(url($sidebarAvatar)) ?>" alt="Your profile picture" class="rounded-circle flex-shrink-0" style="width: 38px; height: 38px; object-fit: cover;">
                    <?php else: ?>
                        <div class="user-avatar-circle flex-shrink-0">
                            <?= e($initial) ?>
                        </div>
                    <?php endif; ?>
                    <div class="overflow-hidden sidebar-footer-text">
                        <div class="fw-semibold text-truncate small text-dark"><?= e($userName) ?></div>
                        <div class="text-muted text-truncate extra-small"><?= e($userEmail) ?></div>
                    </div>
                </div>
                <a href="<?= url('logout.php') ?>" class="btn btn-outline-danger btn-sm p-1 px-2 flex-shrink-0" title="Logout" data-bs-toggle="tooltip" data-bs-placement="right">
                    <i class="bi bi-box-arrow-right"></i>
                </a>
            </div>
        </div>
    </div>
</aside>
