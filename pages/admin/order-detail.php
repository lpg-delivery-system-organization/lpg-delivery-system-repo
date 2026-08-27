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
require_once __DIR__ . '/../../classes/PayMongo.php';

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
        } elseif ($action === 'update_customer_info') {
            $customerName = sanitize_input($_POST['customer_name'] ?? '');
            $contactPhone = sanitize_input($_POST['contact_phone'] ?? '');
            $deliveryAddress = sanitize_input($_POST['delivery_address'] ?? '');

            $updated = $orderModel->updateCustomerInfo($targetId, $customerName, $contactPhone, $deliveryAddress);
            if ($updated) {
                json_response(['success' => true, 'message' => 'Customer details for Order #' . $targetId . ' updated successfully.']);
            } else {
                json_response(['success' => false, 'error' => 'Failed to update customer details.'], 500);
            }
        } elseif ($action === 'cancel_order') {
            $reason = trim($_POST['reason'] ?? 'Cancelled by administrator');
            $cancelled = $orderModel->cancel($targetId, $reason);
            if ($cancelled) {
                json_response(['success' => true, 'message' => 'Order #' . $targetId . ' cancelled and stock released.']);
            } else {
                json_response(['success' => false, 'error' => 'Failed to cancel order.'], 500);
            }
        } elseif ($action === 'approve_refund') {
            $result = $orderModel->approveRefund($targetId);
            if (!empty($result['refunded'])) {
                json_response(['success' => true, 'message' => 'Refund issued for Order #' . $targetId . '. The order was cancelled and stock released.']);
            } else {
                json_response(['success' => false, 'error' => ($result['error'] ?? 'Refund could not be completed.')], 400);
            }
        } elseif ($action === 'reject_refund') {
            $reason = trim($_POST['reason'] ?? 'Declined by administrator');
            $rejected = $orderModel->rejectRefund($targetId, $reason);
            if ($rejected) {
                json_response(['success' => true, 'message' => 'Refund request for Order #' . $targetId . ' rejected.']);
            } else {
                json_response(['success' => false, 'error' => 'Failed to reject refund request.'], 500);
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

    <?php if (($order['payment_method'] ?? '') === 'GCASH' && ($order['payment_status'] ?? '') === 'paid'): ?>
        <?php
        $aRefundStatus = strtolower((string)($order['refund_status'] ?? 'none'));
        $aRefundLabels = [
            'requested' => ['title' => 'Refund Requested', 'badge' => 'bg-warning-subtle text-warning-emphasis border-warning-subtle'],
            'refunded'  => ['title' => 'Refunded',         'badge' => 'bg-success-subtle text-success-emphasis border-success-subtle'],
            'failed'    => ['title' => 'Refund Failed',    'badge' => 'bg-danger-subtle text-danger-emphasis border-danger-subtle'],
            'rejected'  => ['title' => 'Refund Declined',  'badge' => 'bg-secondary-subtle text-secondary-emphasis border-secondary-subtle'],
        ];
        $aInfo = $aRefundLabels[$aRefundStatus] ?? null;
        ?>
        <?php if ($aRefundStatus === 'requested'): ?>
            <div class="alert alert-warning border-0 shadow-sm d-flex flex-wrap align-items-center gap-3 p-3 mb-4">
                <i class="bi bi-arrow-counterclockwise fs-3 text-warning"></i>
                <div class="flex-grow-1">
                    <strong class="d-block">Refund Requested</strong>
                    <span class="small">Customer requested a refund for Order #<?= (int)$order['id'] ?>. Approving issues a PayMongo refund, cancels the order, and restores stock.</span>
                    <?php if (!empty($order['refund_reason'])): ?>
                        <div class="small mt-1"><em>Reason:</em> <?= e($order['refund_reason']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($order['refund_requested_at'])): ?>
                        <div class="small text-muted">Requested on <?= e(format_date($order['refund_requested_at'], 'M d, Y h:i A')) ?></div>
                    <?php endif; ?>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-success btn-sm fw-semibold btn-admin-dispatch-action"
                            data-action="approve_refund" data-order-id="<?= (int)$order['id'] ?>"
                            data-csrf="<?= csrf_token() ?>"
                            data-confirm-title="Approve Refund #<?= (int)$order['id'] ?>"
                            data-confirm-msg="Issue a refund of <?= e(format_currency($order['total_amount'] ?? 0)) ?> back to the customer and cancel this order? This cannot be undone."
                            data-confirm-icon="bi-arrow-counterclockwise" data-confirm-color="text-success" data-confirm-btn="btn-success">
                        <i class="bi bi-check-circle me-1"></i>Approve & Refund
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm fw-semibold btn-admin-dispatch-action"
                            data-action="reject_refund" data-order-id="<?= (int)$order['id'] ?>"
                            data-csrf="<?= csrf_token() ?>"
                            data-confirm-title="Reject Refund #<?= (int)$order['id'] ?>"
                            data-confirm-msg="Decline this customer's refund request? The order will remain as-is."
                            data-confirm-icon="bi-x-circle" data-confirm-color="text-secondary" data-confirm-btn="btn-secondary">
                        <i class="bi bi-x-lg me-1"></i>Reject
                    </button>
                </div>
            </div>
        <?php elseif ($aInfo): ?>
            <div class="alert d-flex align-items-center gap-3 p-3 mb-4 border <?= e($aInfo['badge']) ?>">
                <i class="bi bi-arrow-counterclockwise fs-4"></i>
                <div class="flex-grow-1">
                    <strong class="d-block"><?= e($aInfo['title']) ?></strong>
                    <?php if ($aRefundStatus === 'refunded' && !empty($order['refund_reference'])): ?>
                        <span class="small">Refund ID: <code><?= e($order['refund_reference']) ?></code></span>
                    <?php endif; ?>
                    <?php if (!empty($order['refund_reason'])): ?>
                        <span class="small d-block">Reason: <?= e($order['refund_reason']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($order['refund_processed_at'])): ?>
                        <span class="small d-block">Processed on <?= e(format_date($order['refund_processed_at'], 'M d, Y h:i A')) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    <div class="row g-4 mb-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100 bg-white">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold text-dark mb-0"><i class="bi bi-person text-primary me-2"></i>Customer Information</h6>
                    <button type="button" class="btn btn-sm btn-light border btn-open-edit-customer-info"
                            data-customer-name="<?= e($order['customer_name'] ?? '') ?>"
                            data-phone="<?= e($order['contact_phone'] ?? '') ?>"
                            data-address="<?= e($order['delivery_address'] ?? '') ?>"
                            title="Edit customer details (walk-in)">
                        <i class="bi bi-pencil me-1"></i>Edit
                    </button>
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
                        <div class="app-product-icon rounded-3 bg-primary text-white flex-shrink-0 overflow-hidden" style="width: 64px; height: 64px;">
                            <?php $adminDetailImage = $order['product_image'] ?? ''; ?>
                            <?php if (!empty($adminDetailImage) && is_file(dirname(__DIR__, 2) . '/' . ltrim($adminDetailImage, '/'))): ?>
                                <img src="<?= e(asset($adminDetailImage)) ?>" alt="<?= e($order['product_name'] ?? 'LPG') ?>" style="width: 100%; height: 100%; object-fit: cover;">
                            <?php else: ?>
                                <i class="bi bi-fire fs-4"></i>
                            <?php endif; ?>
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

    <?php if (in_array($status, ['picked_up', 'out_for_delivery'], true)): ?>
        <!-- Live Rider GPS Tracking Map -->
        <div class="row g-4 mb-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm bg-white">
                    <div class="card-header bg-white py-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div class="d-flex align-items-center gap-2">
                            <span class="live-pulse-dot"></span>
                            <h6 class="fw-bold text-dark mb-0"><i class="bi bi-map text-primary me-2"></i>Live Rider Tracking</h6>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                                <?= $status === 'picked_up' ? 'Rider Picked Up Cylinder' : 'Out for Delivery' ?>
                            </span>
                        </div>
                        <a href="<?php if (!empty($order['delivery_latitude']) && !empty($order['delivery_longitude'])): ?>https://maps.google.com/?q=<?= e($order['delivery_latitude']) ?>,<?= e($order['delivery_longitude']) ?><?php else: ?>https://maps.google.com/?q=<?= urlencode($order['delivery_address'] ?? '') ?><?php endif; ?>" target="_blank" class="btn btn-sm btn-light border">
                            <i class="bi bi-box-arrow-up-right me-1"></i>Open Google Maps
                        </a>
                    </div>
                    <div class="card-body p-4">
                        <div id="adminLiveMap" class="admin-live-map mb-3"
                             data-order-id="<?= $orderId ?>"
                             data-address="<?= e($order['delivery_address'] ?? '') ?>"
                             data-lat="<?= e($order['delivery_latitude'] ?? '') ?>"
                             data-lng="<?= e($order['delivery_longitude'] ?? '') ?>"
                             data-customer-name="<?= e($order['customer_name'] ?? 'Customer') ?>"
                             data-rider-name="<?= e($order['rider_name'] ?? 'Delivery Rider') ?>"></div>
                        <div class="small text-muted">
                            <span class="fw-semibold text-primary"><i class="bi bi-geo-alt-fill me-1"></i>Status:</span>
                            <span id="adminMapStatusText">Acquiring rider GPS signal...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

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

<!-- Modal: Edit Customer Details (Walk-in) -->
<div class="modal fade" id="editCustomerInfoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form id="editCustomerInfoForm">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="update_customer_info">
                <input type="hidden" name="order_id" value="<?= $orderId ?>">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Edit Customer Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="alert alert-info small mb-3">
                        <i class="bi bi-info-circle me-1"></i>Use this for walk-in orders &mdash; update the customer's name, contact number, and delivery address.
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Customer Name <span class="text-danger">*</span></label>
                        <input type="text" name="customer_name" id="editCustName" class="form-control" maxlength="255" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Contact Phone <span class="text-danger">*</span></label>
                        <input type="tel" name="contact_phone" id="editCustPhone" class="form-control" maxlength="20" required>
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-semibold">Delivery Address <span class="text-danger">*</span></label>
                        <textarea name="delivery_address" id="editCustAddress" class="form-control" rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light border-top">
                    <button type="button" class="btn btn-light border px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4 fw-semibold">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
