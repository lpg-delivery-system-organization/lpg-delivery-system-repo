<?php
/**
 * Rider Order Detail Page
 * LPG Delivery System v2
 */

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/middleware.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/Order.php';
require_once __DIR__ . '/../../classes/Product.php';
require_once __DIR__ . '/../../classes/User.php';

require_role('rider');

$riderId = (int)current_user_id();
$orderId = (int)($_GET['id'] ?? 0);

if (!$orderId) {
    set_flash('error', 'Invalid delivery order ID specified.');
    redirect('/pages/rider/deliveries.php');
    return;
}

$db = Database::connect();
$orderModel = new Order($db);

// Handle AJAX Status Transition or Claim Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'error' => 'Security token expired or invalid.'], 403);
    }
    $targetId = (int)($_POST['order_id'] ?? 0);
    $action = $_POST['action'];

    if ($action === 'claim_order') {
        try {
            $claimed = $orderModel->assignRider($targetId, $riderId, 'picked_up');
            if ($claimed) {
                json_response([
                    'success' => true,
                    'message' => 'Order #' . $targetId . ' successfully claimed!',
                    'redirect' => url('pages/rider/order-detail.php?id=' . $targetId)
                ]);
            } else {
                json_response(['success' => false, 'error' => 'Could not claim order. It may have already been claimed.'], 400);
            }
        } catch (Throwable $e) {
            json_response(['success' => false, 'error' => $e->getMessage()], 400);
        }
    } elseif ($action === 'update_status') {
        $targetStatus = trim($_POST['status'] ?? '');
        $order = $orderModel->findById($targetId);

        if (!$order || (int)($order['rider_id'] ?? 0) !== $riderId) {
            json_response(['success' => false, 'error' => 'Unauthorized or order not assigned to you.'], 403);
        }

        $validTransitions = [
            'ready_for_delivery' => 'picked_up',
            'picked_up'          => 'out_for_delivery',
            'out_for_delivery'   => 'delivered'
        ];

        $currentStatus = $order['status'];
        if (!isset($validTransitions[$currentStatus]) || $validTransitions[$currentStatus] !== $targetStatus) {
            json_response(['success' => false, 'error' => 'Invalid status progression.'], 400);
        }

        try {
            $updated = $orderModel->updateStatus($targetId, $targetStatus);
            if ($updated) {
                json_response([
                    'success' => true, 
                    'message' => 'Order status updated to ' . str_replace('_', ' ', strtoupper($targetStatus)) . '.',
                    'status' => $targetStatus
                ]);
            } else {
                json_response(['success' => false, 'error' => 'Failed to update order status.'], 500);
            }
        } catch (Throwable $e) {
            json_response(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}

$order = $orderModel->findById($orderId);

if (!$order) {
    set_flash('error', 'Delivery order not found.');
    redirect('/pages/rider/deliveries.php');
    return;
}

$isAssignedToMe = ((int)($order['rider_id'] ?? 0) === $riderId);
$isUnassigned = empty($order['rider_id']) && in_array($order['status'], ['approved', 'ready_for_delivery'], true);

if (!$isAssignedToMe && !$isUnassigned) {
    set_flash('error', 'You do not have permission to view this order.');
    redirect('/pages/rider/deliveries.php');
    return;
}

$status = $order['status'] ?? 'pending';
$isReady = ($status === 'ready_for_delivery');
$isPickedUp = ($status === 'picked_up');
$isOutForDelivery = ($status === 'out_for_delivery');
$isDelivered = ($status === 'delivered');
$isCod = (($order['payment_method'] ?? 'COD') === 'COD');

$page_title = 'Delivery #' . $orderId . ' Details';
$current_page = 'deliveries';
$page_js = 'rider.js';
require_once __DIR__ . '/../../templates/header.php';
require_once __DIR__ . '/../../templates/components/order-card.php';
?>

<div class="container-fluid px-0">
    <!-- Breadcrumb & Top Bar -->
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4 pb-2 border-bottom">
        <div class="d-flex align-items-center gap-3">
            <a href="<?= $isAssignedToMe ? url('pages/rider/deliveries.php') : url('pages/rider/available.php') ?>" class="btn btn-light border px-3">
                <i class="bi bi-arrow-left me-1"></i>Back to <?= $isAssignedToMe ? 'My Deliveries' : 'Available Orders' ?>
            </a>
            <div>
                <h3 class="fw-bold text-dark mb-0">Delivery #<?= $orderId ?></h3>
                <small class="text-muted">Order Date: <?= e(format_date($order['created_at'], 'M d, Y h:i A')) ?></small>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <?= get_order_status_badge($status) ?>
            <?php if ($isAssignedToMe && in_array($status, ['picked_up', 'out_for_delivery', 'ready_for_delivery', 'delivered'], true)): ?>
                <button type="button" class="btn btn-outline-primary position-relative" id="btnOpenChat" data-bs-toggle="offcanvas" data-bs-target="#chatPanel">
                    <i class="bi bi-chat-dots-fill me-1"></i>Chat
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none" id="chatUnreadBadge">0</span>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-4">
        <!-- Real GPS Leaflet Moving Map for Rider -->
        <div class="col-12">
            <div class="card border-0 shadow-sm bg-white">
                <div class="card-header bg-white py-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <span class="live-pulse-dot"></span>
                        <h6 class="fw-bold text-dark mb-0"><i class="bi bi-map text-primary me-2"></i>Live GPS Navigation & Delivery Route</h6>
                        <span id="broadcastStatus">
                            <?php if (in_array($status, ['picked_up', 'out_for_delivery'], true)): ?>
                                <span class="badge bg-secondary"><i class="bi bi-pause-circle me-1"></i>Broadcasting Paused</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <?php if (in_array($status, ['picked_up', 'out_for_delivery'], true)): ?>
                            <button type="button" class="btn btn-sm btn-outline-success" id="btnToggleBroadcast">
                                <i class="bi bi-broadcast me-1"></i>Start Broadcasting
                            </button>
                        <?php endif; ?>
                        <button type="button" class="btn btn-sm btn-outline-primary btn-center-gps" id="btnCenterGps">
                            <i class="bi bi-crosshair me-1"></i>Center on My Location
                        </button>
                        <a href="<?php if (!empty($order['delivery_latitude']) && !empty($order['delivery_longitude'])): ?>https://maps.google.com/?q=<?= e($order['delivery_latitude']) ?>,<?= e($order['delivery_longitude']) ?><?php else: ?>https://maps.google.com/?q=<?= urlencode($order['delivery_address'] ?? 'Manila') ?><?php endif; ?>" target="_blank" class="btn btn-sm btn-light border">
                            <i class="bi bi-box-arrow-up-right me-1"></i>Open Google Maps
                        </a>
                    </div>
                </div>
                <div class="card-body p-4">
                    <div id="riderDetailMap" class="order-detail-map mb-3"
                         data-order-id="<?= $orderId ?>"
                         data-address="<?= e($order['delivery_address'] ?? 'Manila') ?>"
                         data-lat="<?= e($order['delivery_latitude'] ?? '') ?>"
                         data-lng="<?= e($order['delivery_longitude'] ?? '') ?>"
                         data-status="<?= e($status) ?>"
                         data-customer-name="<?= e($order['customer_name'] ?? 'Customer') ?>"></div>
                    
                    <div class="row g-2 text-muted small" id="riderGpsInfoBox">
                        <div class="col-sm-6">
                            <span class="fw-semibold text-primary"><i class="bi bi-geo-alt-fill me-1 text-primary"></i>Rider Position:</span>
                            <span id="gpsRiderStatus">Acquiring GPS fix...</span>
                        </div>
                        <div class="col-sm-6 text-sm-end">
                            <span class="fw-semibold text-danger"><i class="bi bi-pin-map-fill me-1 text-danger"></i>Destination:</span>
                            <span><?= e($order['delivery_address'] ?? 'N/A') ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Left Column: Customer & Delivery Info -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100 bg-white">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="fw-bold text-dark mb-0"><i class="bi bi-person text-primary me-2"></i>Customer & Drop-off Location</h6>
                </div>
                <div class="card-body p-4">
                    <div class="d-flex align-items-center justify-content-between p-3 bg-light rounded-3 mb-3 border">
                        <div>
                            <span class="text-muted extra-small text-uppercase fw-semibold d-block">Customer Name</span>
                            <strong class="text-dark fs-6"><?= e($order['customer_name'] ?? 'Customer') ?></strong>
                        </div>
                        <?php if (!empty($order['contact_phone'])): ?>
                            <a href="tel:<?= e($order['contact_phone']) ?>" class="btn btn-primary px-3 fw-semibold">
                                <i class="bi bi-telephone-fill me-1"></i>Call Customer
                            </a>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label class="text-muted extra-small text-uppercase fw-semibold d-block">Phone Number</label>
                        <div class="fw-semibold text-dark mt-1"><?= e($order['contact_phone'] ?? 'N/A') ?></div>
                    </div>

                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <label class="text-muted extra-small text-uppercase fw-semibold">Delivery Address</label>
                            <button type="button" class="btn btn-sm btn-light border p-1 px-2 btn-copy-address" data-address="<?= e($order['delivery_address'] ?? '') ?>">
                                <i class="bi bi-clipboard me-1"></i>Copy Address
                            </button>
                        </div>
                        <div class="fw-semibold text-dark fs-6 mt-1 p-2 bg-light rounded border">
                            <i class="bi bi-geo-alt text-danger me-1"></i><?= e($order['delivery_address'] ?? 'N/A') ?>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="text-muted extra-small text-uppercase fw-semibold d-block mb-1">Exact Location Pin</label>
                        <div class="pin-location-map rounded-3 border"
                             id="riderPinMapAddress"
                             data-address="<?= e($order['delivery_address'] ?? '') ?>"></div>
                        <div class="d-flex gap-2 mt-2">
                            <a href="https://maps.google.com/maps?q=<?= urlencode($order['delivery_address'] ?? '') ?>&navigate=yes" target="_blank" class="btn btn-sm btn-success flex-grow-1 fw-semibold">
                                <i class="bi bi-sign-turn-right me-1"></i>Navigate (Google Maps)
                            </a>
                        </div>
                    </div>

                    <?php if (!empty($order['notes'])): ?>
                        <div class="mb-3">
                            <label class="text-muted extra-small text-uppercase fw-semibold d-block">Special Delivery Notes</label>
                            <div class="p-2 bg-light rounded text-muted small mt-1 border">
                                <i class="bi bi-info-circle text-primary me-1"></i><?= e($order['notes']) ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right Column: Package Details & Progression Actions -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100 bg-white d-flex flex-column">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="fw-bold text-dark mb-0"><i class="bi bi-box-seam text-primary me-2"></i>Order Items & Payment</h6>
                </div>
                <div class="card-body p-4 flex-grow-1">
                    <div class="d-flex align-items-center gap-3 p-3 bg-light rounded-3 mb-3 border">
                        <div class="app-product-icon rounded-3 bg-primary text-white flex-shrink-0 overflow-hidden" style="width: 64px; height: 64px;">
                            <?php $riderDetailImage = $order['product_image'] ?? ''; ?>
                            <?php if (!empty($riderDetailImage) && is_file(dirname(__DIR__, 2) . '/' . ltrim($riderDetailImage, '/'))): ?>
                                <img src="<?= e(asset($riderDetailImage)) ?>" alt="<?= e($order['product_name'] ?? 'LPG') ?>" style="width: 100%; height: 100%; object-fit: cover;">
                            <?php else: ?>
                                <i class="bi bi-fire fs-4"></i>
                            <?php endif; ?>
                        </div>
                        <div class="flex-grow-1">
                            <h6 class="fw-bold text-dark mb-0"><?= e($order['product_name'] ?? 'LPG Cylinder') ?></h6>
                            <small class="text-muted"><?= e($order['brand'] ?? $order['product_brand'] ?? 'Brand') ?> &bull; <?= e($order['weight'] ?? $order['product_weight'] ?? '11kg') ?></small>
                        </div>
                        <div class="text-end">
                            <span class="badge bg-dark fs-6"><?= (int)$order['quantity'] ?> unit(s)</span>
                        </div>
                    </div>

                    <!-- Payment Status Callout -->
                    <?php if ($isCod): ?>
                        <div class="alert alert-warning border border-warning-subtle p-3 mb-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <strong class="d-block text-danger"><i class="bi bi-cash-coin me-1"></i>Collect Cash on Delivery</strong>
                                    <small class="text-muted">Must collect payment upon handover</small>
                                </div>
                                <span class="fs-4 fw-bold text-danger"><?= e(format_currency($order['total_amount'] ?? 0)) ?></span>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info border border-info-subtle p-3 mb-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <strong class="d-block text-primary"><i class="bi bi-check-circle-fill me-1"></i>Prepaid via GCash</strong>
                                    <small class="text-muted">No cash collection required</small>
                                </div>
                                <span class="fs-5 fw-bold text-primary"><?= e(format_currency($order['total_amount'] ?? 0)) ?></span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Footer Status Progression Action -->
                <div class="card-footer bg-white p-3 border-top">
                    <?php if ($isUnassigned): ?>
                        <button type="button" class="btn btn-primary w-100 py-2 fw-bold shadow-sm btn-rider-action"
                                data-action="claim_order"
                                data-order-id="<?= $orderId ?>"
                                data-csrf="<?= csrf_token() ?>"
                                data-confirm-title="Claim Order #<?= $orderId ?>"
                                data-confirm-msg="Are you sure you want to claim this delivery order?"
                                data-confirm-icon="bi-box-arrow-in-down"
                                data-confirm-color="text-primary"
                                data-confirm-btn="btn-primary">
                            <i class="bi bi-plus-circle me-1"></i>Claim This Delivery
                        </button>
                    <?php elseif ($isReady): ?>
                        <button type="button" class="btn btn-primary w-100 py-2 fw-bold shadow-sm btn-rider-action"
                                data-action="update_status"
                                data-status="picked_up"
                                data-order-id="<?= $orderId ?>"
                                data-csrf="<?= csrf_token() ?>"
                                data-confirm-title="Pick Up Order #<?= $orderId ?>"
                                data-confirm-msg="Confirm you have picked up the LPG cylinder from the warehouse?"
                                data-confirm-icon="bi-box-seam"
                                data-confirm-color="text-primary"
                                data-confirm-btn="btn-primary">
                            <i class="bi bi-box-seam me-1"></i>Confirm Order Picked Up
                        </button>
                    <?php elseif ($isPickedUp): ?>
                        <button type="button" class="btn btn-info text-white w-100 py-2 fw-bold shadow-sm btn-rider-action"
                                data-action="update_status"
                                data-status="out_for_delivery"
                                data-order-id="<?= $orderId ?>"
                                data-csrf="<?= csrf_token() ?>"
                                data-confirm-title="Start Delivery #<?= $orderId ?>"
                                data-confirm-msg="Are you en route to the customer location?"
                                data-confirm-icon="bi-truck"
                                data-confirm-color="text-info"
                                data-confirm-btn="btn-info text-white">
                            <i class="bi bi-truck me-1"></i>Mark Out for Delivery
                        </button>
                    <?php elseif ($isOutForDelivery): ?>
                        <button type="button" class="btn btn-success w-100 py-2 fw-bold shadow-sm btn-rider-action"
                                data-action="update_status"
                                data-status="delivered"
                                data-order-id="<?= $orderId ?>"
                                data-csrf="<?= csrf_token() ?>"
                                data-confirm-title="Complete Delivery #<?= $orderId ?>"
                                data-confirm-msg="<?= $isCod ? 'Confirm that you have collected ₱' . number_format($order['total_amount'] ?? 0, 2) . ' cash and handed over the cylinder?' : 'Confirm that the LPG cylinder has been successfully handed over to the customer?' ?>"
                                data-confirm-icon="bi-check-circle-fill"
                                data-confirm-color="text-success"
                                data-confirm-btn="btn-success">
                            <i class="bi bi-check-circle-fill me-1"></i>Confirm Delivery Completed
                        </button>
                    <?php elseif ($isDelivered): ?>
                        <div class="alert alert-success border-0 mb-0 text-center fw-semibold py-2">
                            <i class="bi bi-check2-all me-1"></i>Delivery Successfully Completed
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Chat Offcanvas Panel -->
<div class="offcanvas offcanvas-end chat-offcanvas" tabindex="-1" id="chatPanel" aria-labelledby="chatPanelLabel">
    <div class="offcanvas-header chat-header border-bottom">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-chat-dots-fill text-primary fs-5"></i>
            <div>
                <h6 class="offcanvas-title fw-bold mb-0" id="chatPanelLabel">Chat with Customer</h6>
                <small class="text-muted" id="chatPartnerName"><?= e($order['customer_name'] ?? 'Customer') ?></small>
            </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body d-flex flex-column p-0">
        <div class="chat-messages flex-grow-1 p-3" id="chatMessages">
            <div class="text-center text-muted py-4" id="chatLoading">
                <div class="spinner-border spinner-border-sm text-primary mb-2" role="status"></div>
                <div class="small">Loading messages...</div>
            </div>
        </div>
        <div class="chat-input-area border-top p-3">
            <form id="chatForm" class="d-flex gap-2">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="order_id" value="<?= $orderId ?>">
                <input type="text" class="form-control" id="chatInput" placeholder="Type your message..." maxlength="2000" autocomplete="off">
                <button type="submit" class="btn btn-primary px-3" id="btnSendChat">
                    <i class="bi bi-send-fill"></i>
                </button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
