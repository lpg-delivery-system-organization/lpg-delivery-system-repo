<?php
/**
 * Admin Order Detail & Dispatch Control Page
 * LPG Delivery System v2
 */

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/middleware.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/Order.php';
require_once __DIR__ . '/../../classes/Product.php';
require_once __DIR__ . '/../../classes/User.php';

require_role('admin');

$orderId = (int)($_GET['id'] ?? 0);

if (!$orderId) {
    set_flash('error', 'Invalid order ID specified.');
    redirect('/pages/admin/orders.php');
    return;
}

$db = Database::connect();
$orderModel = new Order($db);
$userModel = new User($db);

// Handle AJAX Dispatch Mutations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'error' => 'Security token expired or invalid.'], 403);
    }
    $targetId = (int)($_POST['order_id'] ?? 0);
    $action = $_POST['action'];

    try {
        if ($action === 'approve_order') {
            $updated = $orderModel->updateStatus($targetId, 'approved');
            if ($updated) {
                json_response(['success' => true, 'message' => 'Order #' . $targetId . ' approved successfully.']);
            } else {
                json_response(['success' => false, 'error' => 'Failed to approve order.'], 500);
            }
        } elseif ($action === 'assign_rider') {
            $riderId = (int)($_POST['rider_id'] ?? 0);
            $initialStatus = trim($_POST['initial_status'] ?? 'ready_for_delivery');
            
            $rider = $userModel->findById($riderId);
            if (!$rider || $rider['role'] !== 'rider' || $rider['status'] !== 'active') {
                json_response(['success' => false, 'error' => 'Invalid or inactive rider selected.'], 400);
            }

            $assigned = $orderModel->assignRider($targetId, $riderId, $initialStatus);
            if ($assigned) {
                json_response(['success' => true, 'message' => 'Rider assigned to Order #' . $targetId . ' successfully.']);
            } else {
                // Fallback direct update
                $stmt = $db->prepare("UPDATE orders SET rider_id = ?, status = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$riderId, $initialStatus, $targetId]);
                json_response(['success' => true, 'message' => 'Rider assigned to Order #' . $targetId . ' successfully.']);
            }
        } elseif ($action === 'update_status') {
            $newStatus = trim($_POST['status'] ?? '');
            $updated = $orderModel->updateStatus($targetId, $newStatus);
            if ($updated) {
                json_response(['success' => true, 'message' => 'Order status updated to ' . str_replace('_', ' ', strtoupper($newStatus)) . '.']);
            } else {
                json_response(['success' => false, 'error' => 'Failed to transition order status.'], 500);
            }
        } elseif ($action === 'cancel_order') {
            $reason = trim($_POST['reason'] ?? 'Cancelled by administrator');
            $cancelled = $orderModel->cancel($targetId, $reason);
            if ($cancelled) {
                json_response(['success' => true, 'message' => 'Order #' . $targetId . ' cancelled and stock released.']);
            } else {
                json_response(['success' => false, 'error' => 'Failed to cancel order.'], 500);
            }
        }
    } catch (Throwable $e) {
        json_response(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

$order = $orderModel->findById($orderId);

if (!$order) {
    set_flash('error', 'Order not found.');
    redirect('/pages/admin/orders.php');
    return;
}

$status = $order['status'] ?? 'pending';
$isPending = ($status === 'pending');
$isApproved = ($status === 'approved');
$isReady = ($status === 'ready_for_delivery');
$isCancelled = ($status === 'cancelled');
$isDelivered = ($status === 'delivered');

// Fetch active riders for assignment dropdown
$allRiders = $userModel->getAllByRole('rider');
$activeRiders = array_values(array_filter($allRiders, function ($r) {
    return ($r['status'] ?? '') === 'active';
}));

$allowedTransitions = Order::TRANSITIONS[$status] ?? [];

$page_title = 'Order #' . $orderId . ' Control';
$current_page = 'orders';
$page_js = 'admin.js';
require_once __DIR__ . '/../../templates/header.php';
require_once __DIR__ . '/../../templates/components/order-card.php';
?>

<div class="container-fluid px-0">
    <!-- Breadcrumb & Top Bar -->
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4 pb-2 border-bottom">
        <div class="d-flex align-items-center gap-3">
            <a href="<?= url('pages/admin/orders.php') ?>" class="btn btn-light border px-3">
                <i class="bi bi-arrow-left me-1"></i>Back to Order Dispatch
            </a>
            <div>
                <h3 class="fw-bold text-dark mb-0">Order #<?= $orderId ?> Control</h3>
                <small class="text-muted">Placed: <?= e(format_date($order['created_at'], 'M d, Y h:i A')) ?></small>
            </div>
        </div>
        <div>
            <?= get_order_status_badge($status) ?>
        </div>
    </div>

    <!-- 3-Column Info Cards -->
    <div class="row g-4 mb-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100 bg-white">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="fw-bold text-dark mb-0"><i class="bi bi-person text-primary me-2"></i>Customer Information</h6>
                </div>
                <div class="card-body p-4">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div class="user-avatar-circle bg-primary" style="width: 44px; height: 44px; font-size: 1.1rem;">
                            <?= e(strtoupper(substr($order['customer_name'] ?? 'C', 0, 1))) ?>
                        </div>
                        <div>
                            <strong class="text-dark fs-6 d-block"><?= e($order['customer_name'] ?? 'Customer') ?></strong>
                            <span class="text-muted small">Customer ID: #<?= (int)($order['customer_id'] ?? 0) ?></span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="text-muted extra-small text-uppercase fw-semibold d-block">Phone Number</label>
                        <div class="fw-semibold text-dark mt-1">
                            <i class="bi bi-telephone me-1 text-primary"></i><?= e($order['contact_phone'] ?? 'N/A') ?>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="text-muted extra-small text-uppercase fw-semibold d-block">Delivery Address</label>
                        <div class="fw-semibold text-dark mt-1 p-2 bg-light rounded border small">
                            <i class="bi bi-geo-alt text-danger me-1"></i><?= e($order['delivery_address'] ?? 'N/A') ?>
                        </div>
                    </div>

                    <?php if (!empty($order['notes'])): ?>
                        <div class="mb-0">
                            <label class="text-muted extra-small text-uppercase fw-semibold d-block">Delivery Notes</label>
                            <div class="p-2 bg-light rounded text-muted small mt-1 border">
                                <?= e($order['notes']) ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100 bg-white">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="fw-bold text-dark mb-0"><i class="bi bi-box-seam text-primary me-2"></i>Product & Pricing</h6>
                </div>
                <div class="card-body p-4">
                    <div class="d-flex align-items-center gap-3 p-3 bg-light rounded-3 mb-3 border">
                        <div class="app-product-icon rounded-3 bg-primary text-white flex-shrink-0">
                            <i class="bi bi-fire fs-4"></i>
                        </div>
                        <div>
                            <h6 class="fw-bold text-dark mb-0"><?= e($order['product_name'] ?? 'LPG Cylinder') ?></h6>
                            <small class="text-muted"><?= e($order['brand'] ?? $order['product_brand'] ?? 'Brand') ?> &bull; <?= e($order['weight'] ?? $order['product_weight'] ?? '11kg') ?></small>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span class="text-muted">Quantity:</span>
                        <strong class="text-dark"><?= (int)$order['quantity'] ?> unit(s)</strong>
                    </div>

                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span class="text-muted">Unit Price:</span>
                        <span class="fw-semibold text-dark"><?= e(format_currency($order['unit_price'] ?? 0)) ?></span>
                    </div>

                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span class="text-muted">Delivery Fee:</span>
                        <span class="fw-semibold text-success">FREE</span>
                    </div>

                    <div class="d-flex justify-content-between py-3">
                        <span class="fw-bold text-dark fs-6">Total Amount:</span>
                        <span class="fw-bold text-primary fs-5"><?= e(format_currency($order['total_amount'] ?? 0)) ?></span>
                    </div>

                    <div class="p-2 rounded text-center <?= ($order['payment_method'] ?? '') === 'GCASH' ? 'bg-primary-subtle text-primary border border-primary' : 'bg-success-subtle text-success border border-success' ?>">
                        <small class="fw-bold">Payment: <?= e($order['payment_method'] ?? 'COD') ?></small>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100 bg-white">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="fw-bold text-dark mb-0"><i class="bi bi-truck text-primary me-2"></i>Rider & Fulfillment</h6>
                </div>
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label class="text-muted extra-small text-uppercase fw-semibold d-block">Assigned Rider</label>
                        <div class="fw-semibold text-dark mt-1">
                            <?php if (!empty($order['rider_name'])): ?>
                                <div class="d-flex align-items-center gap-2 p-2 bg-light rounded border">
                                    <div class="user-avatar-circle bg-primary" style="width: 32px; height: 32px; font-size: 0.8rem;">
                                        <?= e(strtoupper(substr($order['rider_name'], 0, 1))) ?>
                                    </div>
                                    <div>
                                        <strong class="d-block text-dark small"><?= e($order['rider_name']) ?></strong>
                                        <span class="text-muted extra-small"><?= e($order['rider_phone'] ?? 'No phone') ?></span>
                                    </div>
                                </div>
                            <?php else: ?>
                                <span class="badge bg-secondary-subtle text-secondary py-2 px-3">Unassigned</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="text-muted extra-small text-uppercase fw-semibold d-block">Timeline Timestamps</label>
                        <div class="small mt-1">
                            <div class="d-flex justify-content-between py-1">
                                <span class="text-muted">Order Placed:</span>
                                <span class="fw-medium"><?= e(format_date($order['created_at'], 'M d, Y h:i A')) ?></span>
                            </div>
                            <?php if (!empty($order['delivered_at'])): ?>
                                <div class="d-flex justify-content-between py-1 text-success">
                                    <span class="fw-bold">Delivered:</span>
                                    <span class="fw-bold"><?= e(format_date($order['delivered_at'], 'M d, Y h:i A')) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($isCancelled): ?>
                        <div class="alert alert-danger p-2 small mb-0">
                            <strong>Cancelled:</strong> <?= e($order['cancel_reason'] ?? 'No reason') ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Administrative Action Panel -->
    <div class="row g-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm bg-white">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="fw-bold text-dark mb-0"><i class="bi bi-gear-wide-connected text-primary me-2"></i>Order Management Actions</h6>
                </div>
                <div class="card-body p-4 d-flex flex-wrap gap-2 align-items-center">
                    <?php if ($isPending): ?>
                        <button type="button" class="btn btn-success px-4 fw-semibold shadow-sm btn-admin-dispatch-action"
                                data-action="approve_order"
                                data-order-id="<?= $orderId ?>"
                                data-csrf="<?= csrf_token() ?>"
                                data-confirm-title="Approve Order #<?= $orderId ?>"
                                data-confirm-msg="Are you sure you want to approve this order for fulfillment?"
                                data-confirm-icon="bi-check-circle"
                                data-confirm-color="text-success"
                                data-confirm-btn="btn-success">
                            <i class="bi bi-check-lg me-1"></i>Approve Order
                        </button>
                    <?php endif; ?>

                    <?php if ($isApproved || $isReady || $isPending): ?>
                        <button type="button" class="btn btn-primary px-4 fw-semibold shadow-sm btn-open-admin-assign-modal"
                                data-order-id="<?= $orderId ?>"
                                data-customer="<?= e($order['customer_name'] ?? '') ?>"
                                data-product="<?= e($order['product_name'] ?? '') ?>">
                            <i class="bi bi-truck me-1"></i>Assign Delivery Rider
                        </button>
                    <?php endif; ?>

                    <?php if (!empty($allowedTransitions)): ?>
                        <div class="dropdown">
                            <button class="btn btn-light border dropdown-toggle px-3 fw-semibold" type="button" data-bs-toggle="dropdown">
                                <i class="bi bi-arrow-repeat me-1"></i>Change Status
                            </button>
                            <ul class="dropdown-menu shadow border-0">
                                <li class="dropdown-header small">Transition To:</li>
                                <?php foreach ($allowedTransitions as $nextSt): ?>
                                    <li>
                                        <a class="dropdown-item small btn-admin-dispatch-action" href="#"
                                           data-action="update_status"
                                           data-status="<?= e($nextSt) ?>"
                                           data-order-id="<?= $orderId ?>"
                                           data-csrf="<?= csrf_token() ?>"
                                           data-confirm-title="Transition to <?= strtoupper(str_replace('_', ' ', $nextSt)) ?>"
                                           data-confirm-msg="Are you sure you want to change order status to <?= strtoupper(str_replace('_', ' ', $nextSt)) ?>?"
                                           data-confirm-icon="bi-arrow-right-circle"
                                           data-confirm-color="text-primary"
                                           data-confirm-btn="btn-primary">
                                            <i class="bi bi-arrow-right me-2 text-primary"></i><?= ucwords(str_replace('_', ' ', $nextSt)) ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if (!$isCancelled && !$isDelivered): ?>
                        <button type="button" class="btn btn-outline-danger px-4 fw-semibold ms-auto btn-open-admin-cancel-modal"
                                data-order-id="<?= $orderId ?>">
                            <i class="bi bi-x-circle me-1"></i>Cancel Order
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Assign Rider -->
<div class="modal fade" id="adminAssignRiderModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form id="adminAssignRiderForm">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="assign_rider">
                <input type="hidden" name="order_id" id="assignModalOrderId" value="<?= $orderId ?>">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="bi bi-truck me-2"></i>Assign Delivery Rider</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Select Active Rider <span class="text-danger">*</span></label>
                        <select name="rider_id" id="assignModalRiderSelect" class="form-select" required>
                            <option value="">-- Choose an active delivery rider --</option>
                            <?php foreach ($activeRiders as $r): ?>
                                <option value="<?= (int)$r['id'] ?>" <?= ((int)($order['rider_id'] ?? 0) === (int)$r['id']) ? 'selected' : '' ?>>
                                    <?= e($r['full_name']) ?> (<?= e($r['phone'] ?? 'No phone') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Initial Delivery Status</label>
                        <select name="initial_status" class="form-select">
                            <option value="ready_for_delivery" selected>Ready for Delivery</option>
                            <option value="picked_up">Picked Up</option>
                            <option value="out_for_delivery">Out for Delivery</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer bg-light border-top">
                    <button type="button" class="btn btn-light border px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4 fw-semibold">Save Rider Assignment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Cancel Order -->
<div class="modal fade" id="adminCancelOrderModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form id="adminCancelOrderForm">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="cancel_order">
                <input type="hidden" name="order_id" id="adminCancelModalOrderId" value="<?= $orderId ?>">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title fw-bold"><i class="bi bi-exclamation-triangle me-2"></i>Cancel Order</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="alert alert-warning small mb-3">
                        <i class="bi bi-info-circle me-1"></i>Cancelling this order will release the reserved LPG cylinder quantity back to available inventory.
                    </div>
                    <label class="form-label fw-semibold">Reason for Cancellation <span class="text-danger">*</span></label>
                    <textarea name="reason" class="form-control" rows="3" placeholder="State reason for order cancellation..." required></textarea>
                </div>
                <div class="modal-footer bg-light border-top">
                    <button type="button" class="btn btn-light border px-3" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-danger px-4 fw-semibold">Confirm Cancellation</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
