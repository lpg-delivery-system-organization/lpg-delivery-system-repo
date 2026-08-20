<?php
/**
 * Rider Available Orders Portal
 * LPG Delivery System v2
 *
 * Displays unassigned orders ready for pickup and delivery (status 'approved' or 'ready_for_delivery').
 * Provides concurrency-safe 1-click order claiming that atomically locks the order
 * and assigns it to the logged-in rider.
 */

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/middleware.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../classes/Product.php';
require_once __DIR__ . '/../../classes/Order.php';

// Enforce rider role access
require_role('rider');

$db = Database::connect();
$orderModel = new Order($db);
$userModel = new User($db);

$riderId = (int)current_user_id();

// Handle Order Claiming POST Action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        if (is_ajax()) {
            json_response(['success' => false, 'error' => 'Security token invalid or expired.'], 403);
        }
        set_flash('error', 'Security token invalid or expired. Please try again.');
        redirect('/pages/rider/available.php');
        return;
    }

    $action = sanitize_input($_POST['action'] ?? '');
    $orderId = (int)($_POST['order_id'] ?? 0);

    if ($orderId <= 0) {
        if (is_ajax()) {
            json_response(['success' => false, 'error' => 'Invalid order ID.'], 400);
        }
        set_flash('error', 'Invalid order ID specified.');
        redirect('/pages/rider/available.php');
        return;
    }

    if ($action === 'claim_order') {
        try {
            // Atomic concurrency-safe assignment (assignRider transitions to 'picked_up' by default)
            $assigned = $orderModel->assignRider($orderId, $riderId, 'picked_up');

            if ($assigned) {
                $successMsg = "Order #{$orderId} has been successfully claimed and added to your active deliveries!";
                if (is_ajax()) {
                    json_response([
                        'success'  => true,
                        'message'  => $successMsg,
                        'order_id' => $orderId,
                        'redirect' => url('pages/rider/deliveries.php')
                    ]);
                }
                set_flash('success', $successMsg);
                redirect('/pages/rider/deliveries.php');
                return;
            } else {
                $errorMsg = "Order #{$orderId} could not be claimed. It may have already been claimed by another rider or its status has changed.";
                if (is_ajax()) {
                    json_response(['success' => false, 'error' => $errorMsg], 409);
                }
                set_flash('error', $errorMsg);
                redirect('/pages/rider/available.php');
                return;
            }
        } catch (Throwable $e) {
            if (is_ajax()) {
                json_response(['success' => false, 'error' => $e->getMessage()], 400);
            }
            set_flash('error', 'Failed to claim delivery: ' . $e->getMessage());
            redirect('/pages/rider/available.php');
            return;
        }
    } else {
        if (is_ajax()) {
            json_response(['success' => false, 'error' => 'Unknown action requested.'], 400);
        }
        set_flash('error', 'Invalid action requested.');
        redirect('/pages/rider/available.php');
        return;
    }
}

// Retrieve unassigned orders ready for delivery
$availableOrders = $orderModel->getAvailableForRider();
$countAvailable = count($availableOrders);

// Calculate total COD potential
$totalCodValue = 0.0;
$totalItemsCount = 0;
foreach ($availableOrders as $ord) {
    if (($ord['payment_method'] ?? 'cod') === 'cod') {
        $totalCodValue += (float)($ord['total_amount'] ?? 0);
    }
    $totalItemsCount += (int)($ord['quantity'] ?? 1);
}

$page_title = 'Available Orders';
$current_page = 'available';
$page_js = 'rider.js';

require_once __DIR__ . '/../../templates/header.php';
require_once __DIR__ . '/../../templates/components/order-card.php';
?>

<div class="container-fluid px-0" id="riderAvailableContainer">
    <!-- Header Section -->
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <h3 class="fw-bold mb-1 d-flex align-items-center gap-2">
                <i class="bi bi-inbox text-primary"></i>Available Delivery Orders
            </h3>
            <p class="text-muted small mb-0">Claim ready LPG orders for immediate pickup and delivery to customers.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= url('pages/rider/deliveries.php') ?>" class="btn btn-outline-secondary btn-sm px-3 align-self-center">
                <i class="bi bi-truck me-1"></i>My Deliveries
            </a>
            <a href="<?= url('pages/rider/available.php') ?>" class="btn btn-light btn-sm px-3 border shadow-sm align-self-center">
                <i class="bi bi-arrow-clockwise me-1"></i>Refresh
            </a>
        </div>
    </div>

    <!-- Summary Metrics -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-md-4">
            <div class="card app-stat-card border-0 shadow-sm p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted extra-small text-uppercase fw-semibold">Available for Claiming</span>
                        <h3 class="fw-bold my-1 text-primary"><?= $countAvailable ?></h3>
                        <span class="extra-small text-muted"><?= $totalItemsCount ?> total LPG cylinder(s)</span>
                    </div>
                    <div class="stat-icon-wrapper bg-primary-subtle text-primary">
                        <i class="bi bi-inbox"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-md-4">
            <div class="card app-stat-card border-0 shadow-sm p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted extra-small text-uppercase fw-semibold">Total COD Value</span>
                        <h3 class="fw-bold my-1 text-warning-emphasis"><?= e(format_currency($totalCodValue)) ?></h3>
                        <span class="extra-small text-muted">Cash to collect upon delivery</span>
                    </div>
                    <div class="stat-icon-wrapper bg-warning-subtle text-warning-emphasis">
                        <i class="bi bi-cash-stack"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-sm-12 col-md-4">
            <div class="card app-stat-card border-0 shadow-sm p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted extra-small text-uppercase fw-semibold">Claiming Rule</span>
                        <h6 class="fw-bold my-1 text-dark">First-Come, First-Served</h6>
                        <span class="extra-small text-muted">Claiming instantly reserves the delivery</span>
                    </div>
                    <div class="stat-icon-wrapper bg-info-subtle text-info">
                        <i class="bi bi-shield-check"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Search & Filter Bar -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-3">
            <div class="row g-3 align-items-center justify-content-between">
                <div class="col-md-6 col-lg-5">
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" class="form-control" id="availableOrderSearch" placeholder="Search by customer, address, or Order ID...">
                    </div>
                </div>
                <div class="col-md-4 col-lg-3">
                    <select class="form-select" id="availablePaymentFilter">
                        <option value="all">All Payment Methods</option>
                        <option value="COD">Cash on Delivery (COD)</option>
                        <option value="GCASH">GCash Prepaid</option>
                    </select>
                </div>
                <div class="col-md-2 col-lg-4 text-md-end text-muted small">
                    Showing <strong id="availableVisibleCount"><?= $countAvailable ?></strong> orders
                </div>
            </div>
        </div>
    </div>

    <!-- Available Orders List -->
    <?php if (empty($availableOrders)): ?>
        <div class="card border-0 shadow-sm p-5 text-center my-4">
            <div class="mb-3 text-muted">
                <i class="bi bi-check2-circle fs-1 text-success opacity-75"></i>
            </div>
            <h5 class="fw-bold">All caught up!</h5>
            <p class="text-muted small mb-3">There are no orders currently waiting for rider pickup. Check back shortly for incoming orders.</p>
            <div>
                <a href="<?= url('pages/rider/deliveries.php') ?>" class="btn btn-primary px-4">
                    <i class="bi bi-truck me-1"></i>View My Active Deliveries
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-4" id="availableOrdersList">
            <?php foreach ($availableOrders as $order): ?>
                <?php
                $orderId = (int)$order['id'];
                $status = (string)($order['status'] ?? 'approved');
                $customerName = (string)($order['customer_name'] ?? 'Customer');
                $contactPhone = (string)($order['contact_phone'] ?? $order['customer_phone'] ?? '');
                $deliveryAddress = (string)($order['delivery_address'] ?? '');
                $productName = (string)($order['product_name'] ?? 'LPG Cylinder');
                $brand = (string)($order['product_brand'] ?? $order['brand'] ?? '');
                $weight = (string)($order['product_weight'] ?? $order['weight'] ?? '');
                $quantity = (int)($order['quantity'] ?? 1);
                $unitPrice = (float)($order['unit_price'] ?? 0);
                $totalAmount = (float)($order['total_amount'] ?? 0);
                $paymentMethod = strtoupper((string)($order['payment_method'] ?? 'COD'));
                $notes = (string)($order['notes'] ?? '');
                $createdAt = (string)($order['created_at'] ?? '');
                ?>
                <div class="col-lg-6 available-order-item"
                     data-order-id="<?= $orderId ?>"
                     data-customer="<?= e(strtolower($customerName)) ?>"
                     data-address="<?= e(strtolower($deliveryAddress)) ?>"
                     data-phone="<?= e($contactPhone) ?>"
                     data-payment="<?= e($paymentMethod) ?>">

                    <div class="card app-order-card app-clickable-card shadow-sm border-0 h-100 d-flex flex-column" data-href="<?= url('pages/rider/order-detail.php?id=' . $orderId) ?>">
                        <!-- Card Header -->
                        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center py-3 border-bottom gap-2">
                            <div class="d-flex align-items-center gap-2">
                                <a href="<?= url('pages/rider/order-detail.php?id=' . $orderId) ?>" class="fw-bold fs-5 text-primary text-decoration-none">
                                    #<?= $orderId ?> <i class="bi bi-box-arrow-up-right fs-6 ms-1"></i>
                                </a>
                                <span class="text-muted small">&bull;</span>
                                <small class="text-muted"><i class="bi bi-clock me-1"></i>Placed <?= e(format_date($createdAt)) ?></small>
                            </div>
                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 extra-small text-uppercase fw-semibold">
                                <i class="bi bi-check-circle me-1"></i>Ready for Claim
                            </span>
                        </div>

                        <!-- Card Body -->
                        <div class="card-body p-3 p-md-4 flex-grow-1">
                            <!-- Product Summary -->
                            <div class="d-flex align-items-start gap-3 mb-3 p-3 bg-light rounded-3 border">
                                <div class="app-product-icon p-2 bg-white rounded border text-center flex-shrink-0">
                                    <i class="bi bi-fire fs-3 text-warning"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <h6 class="mb-1 fw-bold text-dark"><?= e($productName) ?></h6>
                                    <p class="text-muted small mb-1">
                                        <?php if ($brand): ?><span class="badge bg-secondary-subtle text-secondary me-1"><?= e($brand) ?></span><?php endif; ?>
                                        <?php if ($weight): ?><span class="badge bg-info-subtle text-info-emphasis me-1"><?= e($weight) ?></span><?php endif; ?>
                                    </p>
                                    <div class="small text-muted">
                                        <span>Quantity: <strong class="text-dark"><?= $quantity ?></strong></span>
                                        <span class="mx-2">&bull;</span>
                                        <span>Unit Price: <strong class="text-dark"><?= e(format_currency($unitPrice)) ?></strong></span>
                                    </div>
                                </div>
                            </div>

                            <!-- Customer & Delivery Information -->
                            <div class="small mb-3">
                                <div class="mb-2">
                                    <span class="fw-semibold text-dark"><i class="bi bi-person text-primary me-2"></i>Customer:</span>
                                    <span class="text-dark"><?= e($customerName) ?></span>
                                </div>
                                <div class="mb-2">
                                    <span class="fw-semibold text-dark"><i class="bi bi-telephone text-primary me-2"></i>Contact Phone:</span>
                                    <?php if ($contactPhone): ?>
                                        <a href="tel:<?= e($contactPhone) ?>" class="text-decoration-none fw-medium text-dark">
                                            <?= e($contactPhone) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">None</span>
                                    <?php endif; ?>
                                </div>
                                <div class="mb-2">
                                    <div class="d-flex align-items-start gap-2">
                                        <i class="bi bi-geo-alt text-danger mt-1 flex-shrink-0"></i>
                                        <div>
                                            <span class="fw-semibold text-dark">Delivery Address:</span>
                                            <span class="text-dark d-block"><?= e($deliveryAddress ?: 'No address specified') ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex gap-2 pt-1">
                                    <button type="button" 
                                            class="btn btn-light btn-sm border px-2 extra-small btn-copy-address" 
                                            data-address="<?= e($deliveryAddress) ?>">
                                        <i class="bi bi-clipboard me-1"></i>Copy Address
                                    </button>
                                    <a href="https://maps.google.com/?q=<?= urlencode($deliveryAddress) ?>" 
                                       target="_blank" 
                                       rel="noopener noreferrer" 
                                       class="btn btn-light btn-sm border px-2 extra-small text-decoration-none">
                                        <i class="bi bi-map me-1 text-primary"></i>Map
                                    </a>
                                </div>
                            </div>

                            <!-- Payment Details Box -->
                            <div class="p-2 rounded mb-2 <?= $paymentMethod === 'COD' ? 'bg-warning-subtle border border-warning' : 'bg-primary-subtle border border-primary' ?>">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="bi <?= $paymentMethod === 'COD' ? 'bi-cash-coin fs-4 text-warning-emphasis' : 'bi-check-circle-fill fs-4 text-primary' ?>"></i>
                                        <div>
                                            <strong class="d-block text-dark small">
                                                <?= $paymentMethod === 'COD' ? 'Cash on Delivery (COD)' : 'GCash Prepaid' ?>
                                            </strong>
                                            <span class="extra-small text-muted">
                                                <?= $paymentMethod === 'COD' ? 'Collect payment from customer' : 'Payment received online' ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="text-end">
                                        <span class="extra-small text-muted d-block"><?= $paymentMethod === 'COD' ? 'Collect Amount' : 'Paid Amount' ?></span>
                                        <span class="fw-bold fs-6 <?= $paymentMethod === 'COD' ? 'text-danger' : 'text-primary' ?>">
                                            <?= e(format_currency($totalAmount)) ?>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <?php if ($notes): ?>
                                <div class="mt-2 p-2 bg-light rounded border text-muted extra-small">
                                    <i class="bi bi-chat-left-dots text-warning me-1"></i>
                                    <strong>Notes:</strong> <?= e($notes) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Card Footer: Claim Action Button -->
                        <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center py-3 border-top gap-2 mt-auto">
                            <div>
                                <span class="text-muted extra-small d-block">Total Value</span>
                                <span class="fs-5 fw-bold text-dark"><?= e(format_currency($totalAmount)) ?></span>
                            </div>

                            <form action="<?= url('pages/rider/available.php') ?>" method="POST" class="m-0 form-claim-delivery">
                                <?= csrf_input() ?>
                                <input type="hidden" name="action" value="claim_order">
                                <input type="hidden" name="order_id" value="<?= $orderId ?>">
                                <button type="submit" class="btn btn-primary px-4 fw-semibold shadow-sm btn-claim-order">
                                    <i class="bi bi-plus-circle me-1"></i>Claim Delivery
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- No matching search alert -->
        <div id="noAvailableFilteredAlert" class="alert alert-light border text-center p-4 shadow-sm my-4 d-none">
            <i class="bi bi-search fs-3 text-muted d-block mb-2"></i>
            <strong>No available orders match your search criteria.</strong>
        </div>
    <?php endif; ?>
</div>

<?php
require_once __DIR__ . '/../../templates/footer.php';
?>
