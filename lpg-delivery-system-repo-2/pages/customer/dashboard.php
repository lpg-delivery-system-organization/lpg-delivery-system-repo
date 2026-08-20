<?php
/**
 * Customer Dashboard Portal
 * LPG Delivery System v2
 *
 * Overview showing welcome summary, live order status metrics, active delivery notices,
 * quick reorder shortcuts, and recent order history.
 */

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/middleware.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../classes/Product.php';
require_once __DIR__ . '/../../classes/Order.php';

// Enforce customer access guard
require_role('customer');

$db = Database::connect();
$orderModel = new Order($db);
$userModel = new User($db);
$productModel = new Product($db);

$customerId = (int)current_user_id();
$customer = $userModel->findById($customerId);

// Retrieve all customer orders
$allOrders = $orderModel->getByCustomer($customerId);

// Calculate metrics
$activeCount = 0;
$deliveredCount = 0;
$cancelledCount = 0;
$totalSpent = 0.0;
$latestActiveOrder = null;

$activeStatuses = ['pending', 'approved', 'ready_for_delivery', 'picked_up', 'out_for_delivery'];

foreach ($allOrders as $ord) {
    $status = $ord['status'] ?? 'pending';
    if (in_array($status, $activeStatuses, true)) {
        $activeCount++;
        if ($latestActiveOrder === null) {
            $latestActiveOrder = $ord;
        }
    } elseif ($status === 'delivered') {
        $deliveredCount++;
        $totalSpent += (float)($ord['total_amount'] ?? 0);
    } elseif ($status === 'cancelled') {
        $cancelledCount++;
    }
}

$recentOrders = array_slice($allOrders, 0, 5);

$page_title = 'Customer Dashboard';
$current_page = 'dashboard';
$page_js = 'customer.js';

require_once __DIR__ . '/../../templates/header.php';
require_once __DIR__ . '/../../templates/components/order-card.php';
?>

<div class="container-fluid px-0">
    <!-- Welcome Header Card -->
    <div class="card border-0 shadow-sm mb-4 bg-primary text-white rounded-3 overflow-hidden position-relative">
        <div class="card-body p-4 p-lg-5 position-relative" style="z-index: 2;">
            <div class="row align-items-center g-3">
                <div class="col-lg-8">
                    <span class="badge bg-white text-primary px-3 py-1 mb-2 fw-semibold">Customer Portal</span>
                    <h2 class="fw-bold mb-2">Welcome back, <?= e($customer['full_name'] ?? $_SESSION['user_name'] ?? 'Customer') ?>!</h2>
                    <p class="mb-3 text-white-50 fs-6">
                        Manage your LPG cylinder orders, track real-time delivery status, and reorder with ease.
                    </p>
                    <div class="d-flex flex-wrap gap-2 text-white small">
                        <span class="me-3"><i class="bi bi-geo-alt me-1 text-warning"></i><?= e($customer['address'] ?? 'No address set') ?></span>
                        <span><i class="bi bi-telephone me-1 text-warning"></i><?= e($customer['phone'] ?? 'No phone set') ?></span>
                    </div>
                </div>
                <div class="col-lg-4 text-lg-end">
                    <a href="<?= url('pages/customer/shop.php') ?>" class="btn btn-warning btn-lg fw-bold shadow-sm px-4 text-dark">
                        <i class="bi bi-bag-plus-fill me-2"></i>Shop LPG Now
                    </a>
                </div>
            </div>
        </div>
        <div class="position-absolute end-0 bottom-0 opacity-10 d-none d-md-block" style="z-index: 1; transform: translate(10%, 20%); pointer-events: none;">
            <i class="bi bi-fire" style="font-size: 15rem; color: #fff;"></i>
        </div>
    </div>

    <!-- Active Delivery Banner (if order is in transit / pending) -->
    <?php if ($latestActiveOrder): ?>
        <div class="alert alert-info border-info-subtle shadow-sm d-flex flex-wrap align-items-center justify-content-between p-3 mb-4 rounded-3" role="alert">
            <div class="d-flex align-items-center gap-3 mb-2 mb-md-0">
                <div class="p-2 bg-info bg-opacity-25 rounded-circle text-info">
                    <i class="bi bi-truck fs-4"></i>
                </div>
                <div>
                    <div class="fw-bold text-dark">
                        Order #<?= e((string)$latestActiveOrder['id']) ?> is in progress: <?= get_order_status_badge($latestActiveOrder['status']) ?>
                    </div>
                    <div class="small text-muted">
                        <?= e($latestActiveOrder['product_name'] ?? 'LPG Cylinder') ?> (<?= e((string)$latestActiveOrder['quantity']) ?>x) &bull; Placed <?= e(format_date($latestActiveOrder['created_at'])) ?>
                    </div>
                </div>
            </div>
            <a href="<?= url('pages/customer/orders.php') ?>" class="btn btn-sm btn-info text-white fw-semibold px-3">
                <i class="bi bi-eye me-1"></i>Track Order
            </a>
        </div>
    <?php endif; ?>

    <!-- Status Metrics Grid -->
    <div class="row g-3 mb-4">
        <!-- Active Orders -->
        <div class="col-sm-6 col-xl-3">
            <div class="card app-stat-card border-0 shadow-sm h-100 p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">Active Orders</span>
                        <h3 class="fw-bold my-1 text-primary"><?= (int)$activeCount ?></h3>
                        <span class="extra-small text-muted">Pending & in-transit</span>
                    </div>
                    <div class="stat-icon-wrapper bg-primary-subtle text-primary">
                        <i class="bi bi-hourglass-split"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Delivered Orders -->
        <div class="col-sm-6 col-xl-3">
            <div class="card app-stat-card border-0 shadow-sm h-100 p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">Delivered</span>
                        <h3 class="fw-bold my-1 text-success"><?= (int)$deliveredCount ?></h3>
                        <span class="extra-small text-muted">Completed orders</span>
                    </div>
                    <div class="stat-icon-wrapper bg-success-subtle text-success">
                        <i class="bi bi-check2-circle"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Total Spent -->
        <div class="col-sm-6 col-xl-3">
            <div class="card app-stat-card border-0 shadow-sm h-100 p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">Total Spent</span>
                        <h3 class="fw-bold my-1 text-dark"><?= e(format_currency($totalSpent)) ?></h3>
                        <span class="extra-small text-muted">On delivered orders</span>
                    </div>
                    <div class="stat-icon-wrapper bg-warning-subtle text-warning">
                        <i class="bi bi-cash-stack"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Total Orders Placed -->
        <div class="col-sm-6 col-xl-3">
            <div class="card app-stat-card border-0 shadow-sm h-100 p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">All Orders</span>
                        <h3 class="fw-bold my-1 text-secondary"><?= count($allOrders) ?></h3>
                        <span class="extra-small text-muted">Lifetime transactions</span>
                    </div>
                    <div class="stat-icon-wrapper bg-secondary-subtle text-secondary">
                        <i class="bi bi-receipt"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Area: Recent Orders & Quick Actions -->
    <div class="row g-4">
        <!-- Recent Orders Table -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm h-100 rounded-3">
                <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
                    <h5 class="card-title mb-0 fw-bold d-flex align-items-center gap-2">
                        <i class="bi bi-clock-history text-primary"></i>Recent Orders
                    </h5>
                    <a href="<?= url('pages/customer/orders.php') ?>" class="btn btn-outline-primary btn-sm">
                        View All (<?= count($allOrders) ?>) <i class="bi bi-arrow-right ms-1"></i>
                    </a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($recentOrders)): ?>
                        <div class="text-center py-5 px-3">
                            <div class="mb-3 text-muted">
                                <i class="bi bi-cart-x fs-1 opacity-50"></i>
                            </div>
                            <h6 class="fw-bold">No orders placed yet</h6>
                            <p class="text-muted small mb-3">Browse our top-quality LPG cylinder catalog and place your first order today.</p>
                            <a href="<?= url('pages/customer/shop.php') ?>" class="btn btn-primary btn-sm px-4">
                                <i class="bi bi-bag-plus me-1"></i>Start Shopping
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th class="ps-4">Order</th>
                                        <th>Product</th>
                                        <th>Date</th>
                                        <th>Total</th>
                                        <th>Status</th>
                                        <th class="text-end pe-4">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentOrders as $order): ?>
                                        <tr>
                                            <td class="ps-4 fw-bold text-primary">#<?= e((string)$order['id']) ?></td>
                                            <td>
                                                <div class="fw-semibold text-dark"><?= e($order['product_name'] ?? 'LPG Cylinder') ?></div>
                                                <small class="text-muted">
                                                    <?= e((string)$order['quantity']) ?> unit(s) &bull; <?= e(strtoupper($order['payment_method'] ?? 'COD')) ?>
                                                </small>
                                            </td>
                                            <td class="text-muted small"><?= e(format_date($order['created_at'], 'M d, Y')) ?></td>
                                            <td class="fw-bold text-dark"><?= e(format_currency($order['total_amount'])) ?></td>
                                            <td><?= get_order_status_badge($order['status']) ?></td>
                                            <td class="text-end pe-4">
                                                <a href="<?= url('pages/customer/orders.php') ?>" class="btn btn-light btn-sm p-1 px-2" title="View details">
                                                    <i class="bi bi-chevron-right"></i>
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

        <!-- Sidebar / Quick Actions & Account Summary -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-3 mb-4">
                <div class="card-header bg-white py-3 border-bottom">
                    <h5 class="card-title mb-0 fw-bold d-flex align-items-center gap-2">
                        <i class="bi bi-lightning-charge text-warning"></i>Quick Actions
                    </h5>
                </div>
                <div class="card-body p-3">
                    <div class="d-grid gap-2">
                        <a href="<?= url('pages/customer/shop.php') ?>" class="btn btn-primary d-flex align-items-center justify-content-between p-3 text-decoration-none">
                            <span class="d-flex align-items-center gap-2">
                                <i class="bi bi-shop fs-4"></i>
                                <span class="text-start">
                                    <span class="d-block fw-bold">Order New LPG</span>
                                    <small class="opacity-75">Browse cylinder catalog</small>
                                </span>
                            </span>
                            <i class="bi bi-arrow-right fs-5"></i>
                        </a>

                        <a href="<?= url('pages/customer/orders.php') ?>" class="btn btn-outline-secondary d-flex align-items-center justify-content-between p-3 text-decoration-none text-dark">
                            <span class="d-flex align-items-center gap-2">
                                <i class="bi bi-receipt fs-4 text-primary"></i>
                                <span class="text-start">
                                    <span class="d-block fw-bold">Order History</span>
                                    <small class="text-muted">Track deliveries & cancellations</small>
                                </span>
                            </span>
                            <i class="bi bi-chevron-right"></i>
                        </a>

                        <a href="<?= url('pages/customer/profile.php') ?>" class="btn btn-outline-secondary d-flex align-items-center justify-content-between p-3 text-decoration-none text-dark">
                            <span class="d-flex align-items-center gap-2">
                                <i class="bi bi-person-circle fs-4 text-primary"></i>
                                <span class="text-start">
                                    <span class="d-block fw-bold">Account Profile</span>
                                    <small class="text-muted">Update address & phone</small>
                                </span>
                            </span>
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Delivery Information Card -->
            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="card-title mb-0 fw-bold d-flex align-items-center gap-2">
                        <i class="bi bi-info-circle text-primary"></i>Delivery Info
                    </h6>
                </div>
                <div class="card-body p-3 small">
                    <ul class="list-unstyled mb-0 d-flex flex-column gap-2">
                        <li class="d-flex align-items-start gap-2">
                            <i class="bi bi-clock text-primary mt-1"></i>
                            <div><strong>Delivery Hours:</strong> 8:00 AM – 6:00 PM daily</div>
                        </li>
                        <li class="d-flex align-items-start gap-2">
                            <i class="bi bi-wallet2 text-success mt-1"></i>
                            <div><strong>Payment Options:</strong> Cash on Delivery (COD) and GCash</div>
                        </li>
                        <li class="d-flex align-items-start gap-2">
                            <i class="bi bi-shield-check text-info mt-1"></i>
                            <div><strong>Safety Certified:</strong> All cylinders undergo standard pressure & leak inspection.</div>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../../templates/footer.php';
?>
