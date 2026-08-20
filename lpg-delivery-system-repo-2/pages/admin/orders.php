<?php
/**
 * Admin Order Management & Dispatch Portal
 * LPG Delivery System v2
 *
 * Full order management interface:
 * - Status filtering (all, pending, approved, ready_for_delivery, picked_up, out_for_delivery, delivered, cancelled)
 * - Search by customer name, order ID, or contact number
 * - Order approval and cancellation workflows with transactional inventory adjustments
 * - Concurrency-safe delivery rider assignment populated with active riders
 * - Direct status progression conforming to the state machine
 * - Supports synchronous POST workflows with CSRF & Flash as well as AJAX responses
 */

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/middleware.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../classes/Product.php';
require_once __DIR__ . '/../../classes/Order.php';

// Enforce admin role access
require_role('admin');

$db = Database::connect();
$orderModel = new Order($db);
$userModel = new User($db);
$productModel = new Product($db);

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        if (is_ajax()) {
            json_response(['success' => false, 'error' => 'Security token invalid or expired.'], 403);
        }
        set_flash('error', 'Security token invalid or expired. Please try again.');
        redirect('/pages/admin/orders.php');
        return;
    }

    $action = sanitize_input($_POST['action'] ?? '');
    $orderId = (int)($_POST['order_id'] ?? 0);

    try {
        switch ($action) {
            case 'approve_order':
                if ($orderId <= 0) {
                    throw new InvalidArgumentException('Invalid order ID.');
                }
                $order = $orderModel->findById($orderId);
                if (!$order) {
                    throw new InvalidArgumentException("Order #{$orderId} not found.");
                }
                if ($order['status'] !== 'pending') {
                    throw new InvalidArgumentException("Order #{$orderId} is already {$order['status']}.");
                }

                $orderModel->updateStatus($orderId, 'approved');

                if (is_ajax()) {
                    json_response(['success' => true, 'message' => "Order #{$orderId} has been approved successfully.", 'order_id' => $orderId, 'status' => 'approved']);
                }
                set_flash('success', "Order #{$orderId} has been approved successfully.");
                break;

            case 'assign_rider':
                if ($orderId <= 0) {
                    throw new InvalidArgumentException('Invalid order ID.');
                }
                $riderId = (int)($_POST['rider_id'] ?? 0);
                if ($riderId <= 0) {
                    throw new InvalidArgumentException('Please select a valid delivery rider.');
                }

                $rider = $userModel->findById($riderId);
                if (!$rider || $rider['role'] !== 'rider') {
                    throw new InvalidArgumentException('Selected rider account was not found.');
                }
                if ($rider['status'] !== 'active') {
                    throw new InvalidArgumentException('Selected rider account is currently inactive or suspended.');
                }

                $targetStatus = sanitize_input($_POST['status'] ?? 'picked_up');
                if (!in_array($targetStatus, ['ready_for_delivery', 'picked_up', 'out_for_delivery'], true)) {
                    $targetStatus = 'picked_up';
                }

                $order = $orderModel->findById($orderId);
                if (!$order) {
                    throw new InvalidArgumentException("Order #{$orderId} not found.");
                }

                $assigned = $orderModel->assignRider($orderId, $riderId, $targetStatus);
                if (!$assigned) {
                    // Fallback for re-assigning if already assigned
                    $stmt = $db->prepare("UPDATE orders SET rider_id = ?, status = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$riderId, $targetStatus, $orderId]);
                }

                if (is_ajax()) {
                    json_response([
                        'success' => true,
                        'message' => "Order #{$orderId} assigned to {$rider['full_name']} successfully.",
                        'order_id' => $orderId,
                        'rider_id' => $riderId,
                        'rider_name' => $rider['full_name'],
                        'status' => $targetStatus
                    ]);
                }
                set_flash('success', "Order #{$orderId} assigned to {$rider['full_name']} successfully.");
                break;

            case 'update_status':
                if ($orderId <= 0) {
                    throw new InvalidArgumentException('Invalid order ID.');
                }
                $newStatus = sanitize_input($_POST['status'] ?? '');
                $order = $orderModel->findById($orderId);
                if (!$order) {
                    throw new InvalidArgumentException("Order #{$orderId} not found.");
                }

                if ($newStatus === 'cancelled') {
                    $reason = sanitize_input($_POST['reason'] ?? 'Cancelled by Admin');
                    $orderModel->cancel($orderId, $reason);
                } else {
                    $orderModel->updateStatus($orderId, $newStatus);
                }

                $statusLabel = ucfirst(str_replace('_', ' ', $newStatus));
                if (is_ajax()) {
                    json_response(['success' => true, 'message' => "Order #{$orderId} status updated to {$statusLabel}.", 'order_id' => $orderId, 'status' => $newStatus]);
                }
                set_flash('success', "Order #{$orderId} status updated to {$statusLabel}.");
                break;

            case 'cancel_order':
                if ($orderId <= 0) {
                    throw new InvalidArgumentException('Invalid order ID.');
                }
                $reason = sanitize_input($_POST['reason'] ?? 'Cancelled by Admin via Management Portal');
                $orderModel->cancel($orderId, $reason);

                if (is_ajax()) {
                    json_response(['success' => true, 'message' => "Order #{$orderId} has been cancelled and stock restored.", 'order_id' => $orderId, 'status' => 'cancelled']);
                }
                set_flash('success', "Order #{$orderId} has been cancelled and product stock restored.");
                break;

            default:
                throw new InvalidArgumentException('Unknown management action requested.');
        }
    } catch (Throwable $e) {
        if (is_ajax()) {
            json_response(['success' => false, 'error' => $e->getMessage()], 400);
        }
        set_flash('error', $e->getMessage());
    }

    redirect('/pages/admin/orders.php');
    return;
}

// Retrieve all orders
$orders = $orderModel->getAll();

// Retrieve all active riders for assignment dropdown
$allRiders = $userModel->getAllByRole('rider');
$activeRiders = array_values(array_filter($allRiders, function ($r) {
    return ($r['status'] ?? '') === 'active';
}));

// Calculate status counts for filtering badges
$countAll = count($orders);
$countPending = 0;
$countApproved = 0;
$countReady = 0;
$countPickedUp = 0;
$countOutForDelivery = 0;
$countDelivered = 0;
$countCancelled = 0;

foreach ($orders as $o) {
    $st = $o['status'] ?? 'pending';
    switch ($st) {
        case 'pending':            $countPending++; break;
        case 'approved':           $countApproved++; break;
        case 'ready_for_delivery': $countReady++; break;
        case 'picked_up':          $countPickedUp++; break;
        case 'out_for_delivery':   $countOutForDelivery++; break;
        case 'delivered':          $countDelivered++; break;
        case 'cancelled':          $countCancelled++; break;
    }
}

$page_title = 'Order Management';
$current_page = 'orders';
$page_js = 'admin.js';

require_once __DIR__ . '/../../templates/header.php';
require_once __DIR__ . '/../../templates/components/order-card.php';
?>

<div class="container-fluid px-0" id="adminOrdersContainer">
    <!-- Header Section -->
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <h3 class="fw-bold mb-1 d-flex align-items-center gap-2">
                <i class="bi bi-box-seam text-primary"></i>Order Management & Dispatch
            </h3>
            <p class="text-muted small mb-0">Approve incoming customer orders, assign active delivery riders, and manage order lifecycles.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= url('pages/admin/dashboard.php') ?>" class="btn btn-outline-secondary btn-sm px-3">
                <i class="bi bi-arrow-left me-1"></i>Dashboard
            </a>
            <a href="<?= url('pages/admin/orders.php') ?>" class="btn btn-light btn-sm px-3 border shadow-sm">
                <i class="bi bi-arrow-clockwise me-1"></i>Refresh
            </a>
        </div>
    </div>

    <!-- Status Filter Tabs Bar -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-2 d-flex flex-wrap gap-2 align-items-center">
            <button type="button" class="btn btn-sm btn-primary active admin-order-filter-btn px-3" data-filter="all">
                All Orders <span class="badge bg-white text-primary ms-1"><?= $countAll ?></span>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary admin-order-filter-btn px-3" data-filter="pending">
                Pending <span class="badge bg-secondary ms-1"><?= $countPending ?></span>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary admin-order-filter-btn px-3" data-filter="approved">
                Approved <span class="badge bg-secondary ms-1"><?= $countApproved ?></span>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary admin-order-filter-btn px-3" data-filter="ready_for_delivery">
                Ready <span class="badge bg-secondary ms-1"><?= $countReady ?></span>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary admin-order-filter-btn px-3" data-filter="picked_up">
                Picked Up <span class="badge bg-secondary ms-1"><?= $countPickedUp ?></span>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary admin-order-filter-btn px-3" data-filter="out_for_delivery">
                Out for Delivery <span class="badge bg-secondary ms-1"><?= $countOutForDelivery ?></span>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary admin-order-filter-btn px-3" data-filter="delivered">
                Delivered <span class="badge bg-secondary ms-1"><?= $countDelivered ?></span>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary admin-order-filter-btn px-3" data-filter="cancelled">
                Cancelled <span class="badge bg-secondary ms-1"><?= $countCancelled ?></span>
            </button>
        </div>
    </div>

    <!-- Search and Filter Bar -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-3">
            <div class="row g-3 align-items-center">
                <div class="col-md-6 col-lg-5">
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" class="form-control border-start-0" id="orderSearchInput" placeholder="Search by Order ID, customer, address, or phone...">
                    </div>
                </div>
                <div class="col-md-3 col-lg-3">
                    <select class="form-select" id="orderRiderFilterSelect">
                        <option value="all">All Riders (<?= count($allRiders) ?>)</option>
                        <option value="unassigned">Unassigned Only</option>
                        <?php foreach ($allRiders as $r): ?>
                            <option value="<?= (int)$r['id'] ?>"><?= e($r['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 col-lg-4 text-md-end text-muted small">
                    Showing <strong id="visibleOrderCount"><?= $countAll ?></strong> of <?= $countAll ?> orders
                </div>
            </div>
        </div>
    </div>

    <!-- Orders Table -->
    <div class="card border-0 shadow-sm rounded-3">
        <div class="card-body p-0">
            <?php if (empty($orders)): ?>
                <div class="text-center py-5 px-3">
                    <div class="mb-3 text-muted">
                        <i class="bi bi-inbox fs-1 opacity-50"></i>
                    </div>
                    <h5 class="fw-bold">No orders found in database</h5>
                    <p class="text-muted small mb-0">Customer orders will appear here once submitted.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="adminOrdersTable">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4">Order ID</th>
                                <th>Customer Details</th>
                                <th>Product & Qty</th>
                                <th>Total & Payment</th>
                                <th>Assigned Rider</th>
                                <th>Status</th>
                                <th>Date Placed</th>
                                <th class="text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $order): ?>
                                <?php
                                $orderId = (int)$order['id'];
                                $status = (string)$order['status'];
                                $isPending = ($status === 'pending');
                                $isApproved = ($status === 'approved');
                                $isReady = ($status === 'ready_for_delivery');
                                $isDelivered = ($status === 'delivered');
                                $isCancelled = ($status === 'cancelled');
                                $paymentMethod = strtoupper((string)($order['payment_method'] ?? 'COD'));
                                $riderId = (int)($order['rider_id'] ?? 0);
                                $riderName = (string)($order['rider_name'] ?? '');

                                $allowedTransitions = Order::TRANSITIONS[$status] ?? [];
                                ?>
                                <tr class="admin-order-row"
                                    data-order-id="<?= $orderId ?>"
                                    data-status="<?= e($status) ?>"
                                    data-rider-id="<?= $riderId ?>"
                                    data-customer="<?= e($order['customer_name'] ?? '') ?>"
                                    data-phone="<?= e($order['contact_phone'] ?? '') ?>"
                                    data-address="<?= e($order['delivery_address'] ?? '') ?>"
                                    data-product="<?= e($order['product_name'] ?? '') ?>"
                                    data-brand="<?= e($order['product_brand'] ?? '') ?>"
                                    data-weight="<?= e($order['product_weight'] ?? '') ?>"
                                    data-qty="<?= (int)$order['quantity'] ?>"
                                    data-unit-price="<?= (float)$order['unit_price'] ?>"
                                    data-total="<?= (float)$order['total_amount'] ?>"
                                    data-payment="<?= e($paymentMethod) ?>"
                                    data-notes="<?= e($order['notes'] ?? '') ?>"
                                    data-created="<?= e(format_date($order['created_at'])) ?>"
                                    data-delivered="<?= e(format_date($order['delivered_at'] ?? '')) ?>">

                                    <!-- Order ID -->
                                    <td class="ps-4 fw-bold text-primary">#<?= $orderId ?></td>

                                    <!-- Customer Details -->
                                    <td>
                                        <div class="fw-semibold text-dark"><?= e($order['customer_name'] ?? 'Customer') ?></div>
                                        <div class="small text-muted text-truncate" style="max-width: 220px;" title="<?= e($order['delivery_address'] ?? '') ?>">
                                            <i class="bi bi-geo-alt me-1 text-danger"></i><?= e($order['delivery_address'] ?? '') ?>
                                        </div>
                                        <div class="extra-small text-muted">
                                            <i class="bi bi-telephone me-1 text-primary"></i><?= e($order['contact_phone'] ?? '') ?>
                                        </div>
                                    </td>

                                    <!-- Product & Qty -->
                                    <td>
                                        <div class="fw-medium text-dark"><?= e($order['product_name'] ?? 'LPG Cylinder') ?></div>
                                        <div class="small text-muted">
                                            <?php if (!empty($order['product_brand'])): ?>
                                                <span class="badge bg-light text-dark border me-1"><?= e($order['product_brand']) ?></span>
                                            <?php endif; ?>
                                            <span><strong><?= (int)$order['quantity'] ?></strong> unit(s)</span>
                                        </div>
                                    </td>

                                    <!-- Total & Payment -->
                                    <td>
                                        <div class="fw-bold text-dark"><?= e(format_currency($order['total_amount'])) ?></div>
                                        <span class="badge <?= $paymentMethod === 'GCASH' ? 'bg-primary-subtle text-primary' : 'bg-success-subtle text-success' ?> extra-small">
                                            <?= e($paymentMethod) ?>
                                        </span>
                                    </td>

                                    <!-- Assigned Rider -->
                                    <td>
                                        <?php if (!empty($riderName)): ?>
                                            <div class="fw-semibold text-dark"><i class="bi bi-truck me-1 text-info"></i><?= e($riderName) ?></div>
                                            <span class="badge bg-info-subtle text-info extra-small">Assigned</span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted border">Unassigned</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Status Badge -->
                                    <td>
                                        <?= get_order_status_badge($status) ?>
                                    </td>

                                    <!-- Date Placed -->
                                    <td class="text-muted small">
                                        <?= e(format_date($order['created_at'], 'M d, Y h:i A')) ?>
                                    </td>

                                    <!-- Actions -->
                                    <td class="text-end pe-4">
                                        <div class="d-inline-flex gap-1 align-items-center">
                                            <!-- View Details Button -->
                                            <button type="button" 
                                                    class="btn btn-outline-secondary btn-sm p-1 px-2 btn-view-order-details" 
                                                    title="View Full Details">
                                                <i class="bi bi-eye"></i>
                                            </button>

                                            <!-- Pending Quick Actions: Approve / Cancel -->
                                            <?php if ($isPending): ?>
                                                <form action="<?= url('pages/admin/orders.php') ?>" method="POST" class="d-inline">
                                                    <?= csrf_input() ?>
                                                    <input type="hidden" name="action" value="approve_order">
                                                    <input type="hidden" name="order_id" value="<?= $orderId ?>">
                                                    <button type="submit" class="btn btn-success btn-sm p-1 px-2 fw-semibold" title="Approve Order">
                                                        <i class="bi bi-check-lg me-1"></i>Approve
                                                    </button>
                                                </form>

                                                <button type="button" 
                                                        class="btn btn-outline-danger btn-sm p-1 px-2 btn-open-cancel-order" 
                                                        data-order-id="<?= $orderId ?>" 
                                                        title="Cancel Order">
                                                    <i class="bi bi-x-lg"></i>
                                                </button>
                                            <?php endif; ?>

                                            <!-- Approved/Ready Action: Assign Rider -->
                                            <?php if ($isApproved || $isReady): ?>
                                                <button type="button" 
                                                        class="btn btn-primary btn-sm p-1 px-2 fw-semibold btn-open-assign-rider" 
                                                        data-order-id="<?= $orderId ?>"
                                                        data-customer="<?= e($order['customer_name'] ?? '') ?>"
                                                        data-product="<?= e($order['product_name'] ?? '') ?>"
                                                        title="Assign Rider">
                                                    <i class="bi bi-truck me-1"></i>Assign Rider
                                                </button>
                                            <?php endif; ?>

                                            <!-- Direct Status Transition Dropdown (for non-delivered/non-cancelled) -->
                                            <?php if (!empty($allowedTransitions) && !$isPending): ?>
                                                <div class="dropdown d-inline">
                                                    <button class="btn btn-light btn-sm p-1 px-2 border dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Update Status">
                                                        <i class="bi bi-arrow-repeat"></i>
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
                                                        <li class="dropdown-header small">Transition Status</li>
                                                        <?php foreach ($allowedTransitions as $nextSt): ?>
                                                            <?php if ($nextSt === 'cancelled'): ?>
                                                                <li><hr class="dropdown-divider my-1"></li>
                                                                <li>
                                                                    <a class="dropdown-item text-danger small btn-open-cancel-order" href="#" data-order-id="<?= $orderId ?>">
                                                                        <i class="bi bi-x-circle me-1"></i>Cancel Order
                                                                    </a>
                                                                </li>
                                                            <?php else: ?>
                                                                <li>
                                                                    <form action="<?= url('pages/admin/orders.php') ?>" method="POST" class="m-0">
                                                                        <?= csrf_input() ?>
                                                                        <input type="hidden" name="action" value="update_status">
                                                                        <input type="hidden" name="order_id" value="<?= $orderId ?>">
                                                                        <input type="hidden" name="status" value="<?= e($nextSt) ?>">
                                                                        <button type="submit" class="dropdown-item small">
                                                                            <i class="bi bi-arrow-right-circle me-1 text-primary"></i>Move to <strong><?= ucfirst(str_replace('_', ' ', $nextSt)) ?></strong>
                                                                        </button>
                                                                    </form>
                                                                </li>
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- No Matching Filter Alert -->
    <div id="noAdminFilteredOrdersAlert" class="alert alert-light border text-center p-4 shadow-sm my-4 d-none">
        <i class="bi bi-search fs-3 text-muted d-block mb-2"></i>
        <strong>No orders match your filter and search criteria.</strong>
    </div>
</div>

<!-- Assign Delivery Rider Modal -->
<div class="modal fade" id="assignRiderModal" tabindex="-1" aria-labelledby="assignRiderModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form action="<?= url('pages/admin/orders.php') ?>" method="POST" id="assignRiderForm">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="assign_rider">
                <input type="hidden" name="order_id" id="assignModalOrderId" value="0">

                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold" id="assignRiderModalLabel">
                        <i class="bi bi-truck me-2"></i>Assign Delivery Rider
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="p-3 bg-light rounded mb-3 border">
                        <div class="fw-bold text-primary fs-6" id="assignModalOrderNumber">Order #</div>
                        <div class="small text-muted" id="assignModalSummary">Customer Details</div>
                    </div>

                    <div class="mb-3">
                        <label for="assignRiderSelect" class="form-label fw-semibold">Select Active Rider <span class="text-danger">*</span></label>
                        <select class="form-select" name="rider_id" id="assignRiderSelect" required>
                            <option value="">-- Choose an active rider --</option>
                            <?php if (empty($activeRiders)): ?>
                                <option value="" disabled>No active riders available in system</option>
                            <?php else: ?>
                                <?php foreach ($activeRiders as $r): ?>
                                    <option value="<?= (int)$r['id'] ?>"><?= e($r['full_name']) ?> (<?= e($r['phone']) ?>)</option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="assignStatusSelect" class="form-label fw-semibold">Initial Status After Assignment</label>
                        <select class="form-select" name="status" id="assignStatusSelect">
                            <option value="picked_up" selected>Picked Up (In-Transit)</option>
                            <option value="ready_for_delivery">Ready for Delivery</option>
                            <option value="out_for_delivery">Out for Delivery</option>
                        </select>
                        <div class="form-text small">Sets the new order state when the rider assumes custody.</div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4 fw-semibold" id="btnSubmitAssignRider">
                        <i class="bi bi-check2-circle me-1"></i>Confirm Assignment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Cancel Order Modal -->
<div class="modal fade" id="adminCancelOrderModal" tabindex="-1" aria-labelledby="adminCancelOrderModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form action="<?= url('pages/admin/orders.php') ?>" method="POST" id="adminCancelOrderForm">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="cancel_order">
                <input type="hidden" name="order_id" id="adminCancelOrderId" value="0">

                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title fw-bold" id="adminCancelOrderModalLabel">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>Cancel Order & Restock Inventory
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="mb-2">Are you sure you want to cancel <strong id="adminCancelOrderNumber" class="text-danger">Order</strong>?</p>
                    <p class="text-muted small mb-3">The reserved LPG cylinder(s) will be automatically returned to inventory.</p>

                    <div class="mb-3">
                        <label for="adminCancelReason" class="form-label fw-semibold">Cancellation Reason</label>
                        <input type="text" class="form-control" name="reason" id="adminCancelReason" placeholder="e.g. Customer requested cancellation, out of delivery zone">
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">Keep Order</button>
                    <button type="submit" class="btn btn-danger px-4 fw-semibold">Yes, Cancel Order</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Order Full Details Modal -->
<div class="modal fade" id="orderDetailsModal" tabindex="-1" aria-labelledby="orderDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-light border-bottom">
                <h5 class="modal-title fw-bold" id="orderDetailsModalLabel">
                    <i class="bi bi-receipt me-2 text-primary"></i>Order Details: <span id="detailOrderNumber" class="text-primary">#</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row g-4">
                    <div class="col-md-6">
                        <h6 class="text-muted text-uppercase fw-semibold small mb-2 border-bottom pb-1">Customer & Delivery</h6>
                        <div class="small d-flex flex-column gap-2">
                            <div><strong class="text-dark">Customer:</strong> <span id="detailCustomerName">-</span></div>
                            <div><strong class="text-dark">Phone:</strong> <span id="detailCustomerPhone">-</span></div>
                            <div><strong class="text-dark">Address:</strong> <span id="detailDeliveryAddress">-</span></div>
                            <div><strong class="text-dark">Notes / Instructions:</strong> <span id="detailNotes" class="fst-italic text-muted">None</span></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-muted text-uppercase fw-semibold small mb-2 border-bottom pb-1">Product & Payment</h6>
                        <div class="small d-flex flex-column gap-2">
                            <div><strong class="text-dark">Product:</strong> <span id="detailProductName">-</span></div>
                            <div><strong class="text-dark">Quantity:</strong> <span id="detailQuantity">-</span></div>
                            <div><strong class="text-dark">Unit Price:</strong> <span id="detailUnitPrice">-</span></div>
                            <div><strong class="text-dark">Total Amount:</strong> <span id="detailTotalAmount" class="fw-bold text-success">-</span></div>
                            <div><strong class="text-dark">Payment Method:</strong> <span id="detailPaymentMethod" class="badge bg-secondary">-</span></div>
                        </div>
                    </div>
                    <div class="col-12">
                        <h6 class="text-muted text-uppercase fw-semibold small mb-2 border-bottom pb-1">Fulfillment & Status</h6>
                        <div class="row small g-2">
                            <div class="col-sm-6"><strong class="text-dark">Status:</strong> <span id="detailStatusBadge">-</span></div>
                            <div class="col-sm-6"><strong class="text-dark">Assigned Rider:</strong> <span id="detailRiderName">-</span></div>
                            <div class="col-sm-6"><strong class="text-dark">Placed On:</strong> <span id="detailCreatedAt">-</span></div>
                            <div class="col-sm-6"><strong class="text-dark">Delivered On:</strong> <span id="detailDeliveredAt">-</span></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light border-top">
                <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../../templates/footer.php';
?>
