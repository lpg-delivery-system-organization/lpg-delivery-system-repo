<?php
/**
 * Admin Dashboard Portal
 * LPG Delivery System v2
 *
 * Operational dashboard displaying high-level business metrics:
 * Total Orders, Pending Orders, In-Transit Deliveries, Completed Deliveries, and Total Revenue.
 * Displays recent 10 orders table with quick dispatch actions and inventory status alerts.
 */

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/middleware.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../classes/Product.php';
require_once __DIR__ . '/../../classes/Order.php';

// Enforce admin role guard
require_role('admin');

$db = Database::connect();
$orderModel = new Order($db);
$userModel = new User($db);
$productModel = new Product($db);

// Retrieve aggregated operational stats
$dashboardStats = $orderModel->getDashboardStats();

$totalOrders      = (int)($dashboardStats['total_orders'] ?? 0);
$pendingOrders    = (int)($dashboardStats['pending_orders'] ?? 0);
$inTransitOrders  = (int)($dashboardStats['in_transit_orders'] ?? 0);
$deliveredOrders  = (int)($dashboardStats['delivered_orders'] ?? 0);
$cancelledOrders  = (int)($dashboardStats['cancelled_orders'] ?? 0);
$totalRevenue     = (float)($dashboardStats['total_revenue'] ?? 0.0);
$recentOrders     = $dashboardStats['recent_orders'] ?? [];

// Retrieve secondary catalog and user stats
$allProducts = $productModel->getAll();
$totalProducts = count($allProducts);
$activeProducts = 0;
$lowStockProducts = [];

foreach ($allProducts as $p) {
    if ($p['status'] === 'active') {
        $activeProducts++;
    }
    if ((int)$p['stock'] <= 5) {
        $lowStockProducts[] = $p;
    }
}

$totalCustomers = $userModel->countByRole('customer');
$totalRiders = $userModel->countByRole('rider');
$activeRidersList = array_filter($userModel->getAllByRole('rider'), function ($r) {
    return ($r['status'] ?? '') === 'active';
});

$page_title = 'Admin Dashboard';
$current_page = 'dashboard';
$page_js = 'admin.js';

require_once __DIR__ . '/../../templates/header.php';
require_once __DIR__ . '/../../templates/components/order-card.php';
?>

<div class="container-fluid px-0" id="adminDashboardContainer">
    <!-- Header Banner -->
    <div class="card mb-4 rounded-3 overflow-hidden position-relative app-banner-dark text-white" data-aos="fade-down">
        <div class="card-body p-4 p-lg-5 position-relative" style="z-index: 2;">
            <div class="row align-items-center g-3">
                <div class="col-lg-8">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="badge bg-primary text-uppercase px-3 py-1 fw-semibold">Administration</span>
                        <span class="badge bg-success bg-opacity-75 text-white px-2 py-1"><i class="bi bi-shield-lock-fill me-1"></i>Secure Portal</span>
                    </div>
                    <h2 class="fw-bold mb-2">System Operations Control Center</h2>
                    <p class="mb-3 text-white-50 fs-6">
                        Monitor live LPG cylinder sales, dispatch pending orders to available delivery riders, manage catalog stock, and review accounts.
                    </p>
                    <div class="d-flex flex-wrap gap-3 text-white small">
                        <span><i class="bi bi-calendar-event me-1 text-primary"></i><?= date('F d, Y') ?></span>
                        <span><i class="bi bi-person-badge me-1 text-info"></i><?= e($_SESSION['user_name'] ?? 'Admin') ?> (<?= e($_SESSION['user_email'] ?? '') ?>)</span>
                        <span><i class="bi bi-truck me-1 text-warning"></i><?= count($activeRidersList) ?> Active Riders</span>
                    </div>
                </div>
                <div class="col-lg-4 text-lg-end">
                    <div class="d-flex flex-column flex-sm-row flex-lg-column gap-2 justify-content-lg-end">
                        <a href="<?= url('pages/admin/orders.php') ?>" class="btn btn-primary fw-bold shadow-sm px-4">
                            <i class="bi bi-box-seam me-2"></i>Dispatch Orders
                        </a>
                        <a href="<?= url('pages/admin/inventory.php') ?>" class="btn btn-outline-light btn-sm fw-semibold px-3">
                            <i class="bi bi-tags me-1"></i>Manage Inventory
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 5 Core Operational Statistics Cards -->
    <div class="row g-3 mb-4">
        <!-- Card 1: Total Revenue -->
        <div class="col-sm-6 col-xl" data-aos="fade-up" data-aos-delay="0">
            <div class="card app-stat-card border-0 shadow-sm h-100 p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">Total Revenue</span>
                        <h3 class="fw-bold my-1 text-success"><?= e(format_currency($totalRevenue)) ?></h3>
                        <span class="extra-small text-muted">From <?= (int)$deliveredOrders ?> delivered orders</span>
                    </div>
                    <div class="stat-icon-wrapper bg-success-subtle text-success">
                        <i class="bi bi-cash-stack"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Card 2: Pending Orders -->
        <div class="col-sm-6 col-xl" data-aos="fade-up" data-aos-delay="100">
            <div class="card app-stat-card border-0 shadow-sm h-100 p-3 <?= $pendingOrders > 0 ? 'border-start border-warning border-4' : '' ?>">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">Pending Orders</span>
                        <h3 class="fw-bold my-1 text-warning" data-counter-target="<?= (int)$pendingOrders ?>"><?= (int)$pendingOrders ?></h3>
                        <span class="extra-small text-muted">Awaiting approval</span>
                    </div>
                    <div class="stat-icon-wrapper bg-warning-subtle text-warning">
                        <i class="bi bi-clock-history"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Card 3: In-Transit Deliveries -->
        <div class="col-sm-6 col-xl" data-aos="fade-up" data-aos-delay="200">
            <div class="card app-stat-card border-0 shadow-sm h-100 p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">In-Transit</span>
                        <h3 class="fw-bold my-1 text-info" data-counter-target="<?= (int)$inTransitOrders ?>"><?= (int)$inTransitOrders ?></h3>
                        <span class="extra-small text-muted">Active deliveries</span>
                    </div>
                    <div class="stat-icon-wrapper bg-info-subtle text-info">
                        <i class="bi bi-truck"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Card 4: Completed Deliveries -->
        <div class="col-sm-6 col-xl" data-aos="fade-up" data-aos-delay="300">
            <div class="card app-stat-card border-0 shadow-sm h-100 p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">Delivered</span>
                        <h3 class="fw-bold my-1 text-primary" data-counter-target="<?= (int)$deliveredOrders ?>"><?= (int)$deliveredOrders ?></h3>
                        <span class="extra-small text-muted">Completed orders</span>
                    </div>
                    <div class="stat-icon-wrapper bg-primary-subtle text-primary">
                        <i class="bi bi-check2-circle"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Card 5: Total Orders -->
        <div class="col-sm-6 col-xl" data-aos="fade-up" data-aos-delay="400">
            <div class="card app-stat-card border-0 shadow-sm h-100 p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">Total Orders</span>
                        <h3 class="fw-bold my-1 text-dark" data-counter-target="<?= (int)$totalOrders ?>"><?= (int)$totalOrders ?></h3>
                        <span class="extra-small text-muted"><?= (int)$cancelledOrders ?> cancelled</span>
                    </div>
                    <div class="stat-icon-wrapper bg-secondary-subtle text-secondary">
                        <i class="bi bi-receipt"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Secondary Metric Badges & System Shortcuts -->
    <div class="row g-3 mb-4">
        <div class="col-md-4" data-aos="fade-up" data-aos-delay="0">
            <div class="card border-0 shadow-sm p-3 rounded-3 bg-light">
                <div class="d-flex align-items-center gap-3">
                    <div class="p-2 bg-primary bg-opacity-10 text-primary rounded-circle">
                        <i class="bi bi-people fs-4"></i>
                    </div>
                    <div>
                        <div class="fw-bold text-dark fs-5" data-counter-target="<?= (int)$totalCustomers ?>"><?= (int)$totalCustomers ?> Customers</div>
                        <div class="small text-muted">Registered user accounts</div>
                    </div>
                    <a href="<?= url('pages/admin/users.php?role=customer') ?>" class="btn btn-sm btn-outline-primary ms-auto">View</a>
                </div>
            </div>
        </div>
        <div class="col-md-4" data-aos="fade-up" data-aos-delay="100">
            <div class="card border-0 shadow-sm p-3 rounded-3 bg-light">
                <div class="d-flex align-items-center gap-3">
                    <div class="p-2 bg-warning bg-opacity-10 text-warning rounded-circle">
                        <i class="bi bi-truck fs-4"></i>
                    </div>
                    <div>
                        <div class="fw-bold text-dark fs-5" data-counter-target="<?= count($activeRidersList) ?>"><?= count($activeRidersList) ?> Active Riders</div>
                        <div class="small text-muted"><?= (int)$totalRiders ?> total enrolled</div>
                    </div>
                    <a href="<?= url('pages/admin/users.php?role=rider') ?>" class="btn btn-sm btn-outline-warning text-dark ms-auto">View</a>
                </div>
            </div>
        </div>
        <div class="col-md-4" data-aos="fade-up" data-aos-delay="200">
            <div class="card border-0 shadow-sm p-3 rounded-3 bg-light">
                <div class="d-flex align-items-center gap-3">
                    <div class="p-2 bg-info bg-opacity-10 text-info rounded-circle">
                        <i class="bi bi-tags fs-4"></i>
                    </div>
                    <div>
                        <div class="fw-bold text-dark fs-5" data-counter-target="<?= (int)$activeProducts ?>"><?= (int)$activeProducts ?> Active Products</div>
                        <div class="small text-muted"><?= (int)$totalProducts ?> items in catalog</div>
                    </div>
                    <a href="<?= url('pages/admin/inventory.php') ?>" class="btn btn-sm btn-outline-info ms-auto">Catalog</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Area: Recent Orders & Sidebar Info -->
    <div class="row g-4">
        <!-- Recent Orders Table (Top 10) -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-3 h-100">
                <div class="card-header bg-white py-3 border-bottom d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div>
                        <h5 class="card-title mb-0 fw-bold d-flex align-items-center gap-2">
                            <i class="bi bi-clock-history text-primary"></i>Recent Orders
                        </h5>
                        <small class="text-muted">Displaying latest 10 transactions across the platform</small>
                    </div>
                    <a href="<?= url('pages/admin/orders.php') ?>" class="btn btn-outline-primary btn-sm fw-semibold">
                        View All Orders (<?= $totalOrders ?>) <i class="bi bi-arrow-right ms-1"></i>
                    </a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($recentOrders)): ?>
                        <div class="text-center py-5 px-3">
                            <div class="mb-3 text-muted">
                                <i class="bi bi-box-seam fs-1 opacity-50"></i>
                            </div>
                            <h6 class="fw-bold">No orders placed yet</h6>
                            <p class="text-muted small mb-0">Customer orders will appear here in real-time.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-4">Order</th>
                                        <th>Customer</th>
                                        <th>Product</th>
                                        <th>Amount</th>
                                        <th>Payment</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                        <th class="text-end pe-4">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentOrders as $order): ?>
                                        <?php
                                        $orderId = (int)$order['id'];
                                        $status = (string)$order['status'];
                                        $paymentMethod = strtoupper((string)($order['payment_method'] ?? 'COD'));
                                        ?>
                                        <tr class="app-clickable-row" data-href="<?= url('pages/admin/order-detail.php?id=' . $orderId) ?>">
                                            <td class="ps-4 fw-bold text-primary">#<?= $orderId ?></td>
                                            <td>
                                                <div class="fw-semibold text-dark"><?= e($order['customer_name'] ?? 'Customer') ?></div>
                                                <?php if (!empty($order['rider_name'])): ?>
                                                    <small class="text-muted extra-small"><i class="bi bi-truck me-1"></i><?= e($order['rider_name']) ?></small>
                                                <?php else: ?>
                                                    <small class="text-muted extra-small fst-italic">Unassigned</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="fw-medium text-dark"><?= e($order['product_name'] ?? 'LPG Cylinder') ?></div>
                                                <small class="text-muted extra-small"><?= (int)$order['quantity'] ?> unit(s)</small>
                                            </td>
                                            <td class="fw-bold text-dark"><?= e(format_currency($order['total_amount'])) ?></td>
                                            <td>
                                                <span class="badge <?= $paymentMethod === 'GCASH' ? 'bg-primary-subtle text-primary' : 'bg-success-subtle text-success' ?>">
                                                    <?= e($paymentMethod) ?>
                                                </span>
                                            </td>
                                            <td><?= get_order_status_badge($status) ?></td>
                                            <td class="text-muted small"><?= e(format_date($order['created_at'], 'M d, Y')) ?></td>
                                            <td class="text-end pe-4">
                                                <a href="<?= url('pages/admin/order-detail.php?id=' . $orderId) ?>" class="btn btn-light btn-sm p-1 px-2" title="Manage Order">
                                                    <i class="bi bi-arrow-right"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Sidebar / Alerts & Quick Navigation -->
        <div class="col-lg-4">
            <!-- Low Stock Alert Card -->
            <div class="card border-0 shadow-sm rounded-3 mb-4">
                <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
                    <h6 class="card-title mb-0 fw-bold d-flex align-items-center gap-2">
                        <i class="bi bi-exclamation-triangle-fill text-warning"></i>Inventory Stock Alerts
                    </h6>
                    <span class="badge <?= count($lowStockProducts) > 0 ? 'bg-danger' : 'bg-success' ?> rounded-pill">
                        <?= count($lowStockProducts) ?>
                    </span>
                </div>
                <div class="card-body p-3">
                    <?php if (empty($lowStockProducts)): ?>
                        <div class="text-center py-3 text-muted">
                            <i class="bi bi-check-circle fs-3 text-success d-block mb-1"></i>
                            <span class="small">All product inventory levels are healthy (&gt; 5 units).</span>
                        </div>
                    <?php else: ?>
                        <ul class="list-group list-group-flush small">
                            <?php foreach ($lowStockProducts as $lp): ?>
                                <li class="list-group-item px-0 py-2 d-flex align-items-center justify-content-between">
                                    <div>
                                        <div class="fw-semibold text-dark"><?= e($lp['name']) ?></div>
                                        <span class="badge bg-secondary-subtle text-secondary extra-small"><?= e($lp['brand']) ?> (<?= e($lp['weight']) ?>)</span>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge <?= (int)$lp['stock'] === 0 ? 'bg-danger' : 'bg-warning text-dark' ?>">
                                            <?= (int)$lp['stock'] === 0 ? 'Out of Stock' : (int)$lp['stock'] . ' left' ?>
                                        </span>
                                        <div class="extra-small mt-1">
                                            <a href="<?= url('pages/admin/inventory.php') ?>" class="text-decoration-none text-primary">Restock</a>
                                        </div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Management Shortcuts -->
            <div class="card border-0 shadow-sm rounded-3 mb-4">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="card-title mb-0 fw-bold d-flex align-items-center gap-2">
                        <i class="bi bi-grid-fill text-primary"></i>Management Hub
                    </h6>
                </div>
                <div class="card-body p-3">
                    <div class="d-grid gap-2">
                        <a href="<?= url('pages/admin/orders.php') ?>" class="btn btn-outline-primary d-flex align-items-center justify-content-between p-3 text-start">
                            <div>
                                <div class="fw-bold"><i class="bi bi-box-seam me-2"></i>Order Dispatch</div>
                                <small class="text-muted">Approve, assign riders, and update delivery status</small>
                            </div>
                            <i class="bi bi-chevron-right"></i>
                        </a>
                        <a href="<?= url('pages/admin/inventory.php') ?>" class="btn btn-outline-secondary d-flex align-items-center justify-content-between p-3 text-start text-dark">
                            <div>
                                <div class="fw-bold"><i class="bi bi-tags me-2 text-info"></i>Product Inventory</div>
                                <small class="text-muted">Update stock, adjust prices, and add new products</small>
                            </div>
                            <i class="bi bi-chevron-right"></i>
                        </a>
                        <a href="<?= url('pages/admin/users.php') ?>" class="btn btn-outline-secondary d-flex align-items-center justify-content-between p-3 text-start text-dark">
                            <div>
                                <div class="fw-bold"><i class="bi bi-people me-2 text-warning"></i>User Accounts</div>
                                <small class="text-muted">Inspect customer valid IDs and toggle user statuses</small>
                            </div>
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../../templates/footer.php';
?>
