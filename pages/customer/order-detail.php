<?php
/**
 * Customer Order Detail Page
 * LPG Delivery System v2
 */

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/middleware.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/Order.php';
require_once __DIR__ . '/../../classes/Product.php';
require_once __DIR__ . '/../../classes/User.php';

require_role('customer');

$customerId = (int)current_user_id();
$orderId = (int)($_GET['id'] ?? 0);

if (!$orderId) {
    set_flash('error', 'Invalid order ID specified.');
    redirect('/pages/customer/orders.php');
    return;
}

$db = Database::connect();
$orderModel = new Order($db);

// Handle AJAX Cancel Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_order') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'error' => 'Invalid or expired CSRF token.'], 403);
    }
    $targetId = (int)($_POST['order_id'] ?? 0);
    $order = $orderModel->findById($targetId);

    if (!$order || (int)$order['customer_id'] !== $customerId) {
        json_response(['success' => false, 'error' => 'Unauthorized or order not found.'], 403);
    }

    if ($order['status'] !== 'pending') {
        json_response(['success' => false, 'error' => 'Only pending orders can be cancelled.'], 400);
    }

    try {
        $success = $orderModel->cancel($targetId, 'Cancelled by customer via order details');
        if ($success) {
            json_response(['success' => true, 'message' => 'Order #' . $targetId . ' has been successfully cancelled.']);
        } else {
            json_response(['success' => false, 'error' => 'Failed to cancel order. Please try again.'], 500);
        }
    } catch (Throwable $e) {
        json_response(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

$order = $orderModel->findById($orderId);

if (!$order || (int)$order['customer_id'] !== $customerId) {
    set_flash('error', 'Order not found or access unauthorized.');
    redirect('/pages/customer/orders.php');
    return;
}

$status = $order['status'] ?? 'pending';
$isCancelled = ($status === 'cancelled');
$isDelivered = ($status === 'delivered');
$isPending = ($status === 'pending');
$isInTransit = in_array($status, ['approved', 'ready_for_delivery', 'picked_up', 'out_for_delivery'], true);

$steps = [
    'pending'            => ['label' => 'Order Placed', 'icon' => '1'],
    'approved'           => ['label' => 'Approved',     'icon' => '2'],
    'ready_for_delivery' => ['label' => 'Ready',        'icon' => '3'],
    'picked_up'          => ['label' => 'Picked Up',    'icon' => '4'],
    'out_for_delivery'   => ['label' => 'Out for Delivery', 'icon' => '5'],
    'delivered'          => ['label' => 'Delivered',    'icon' => '✓']
];
$stepOrder = array_keys($steps);
$currentIndex = array_search($status, $stepOrder, true);
if ($currentIndex === false) $currentIndex = 0;

$page_title = 'Order #' . $orderId . ' Details';
$current_page = 'orders';
$page_js = 'customer.js';
require_once __DIR__ . '/../../templates/header.php';
require_once __DIR__ . '/../../templates/components/order-card.php';
?>

<div class="container-fluid px-0">
    <!-- Breadcrumb & Top Bar -->
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4 pb-2 border-bottom" data-aos="fade-down">
        <div class="d-flex align-items-center gap-3">
            <a href="<?= url('pages/customer/orders.php') ?>" class="btn btn-light border px-3">
                <i class="bi bi-arrow-left me-1"></i>Back to My Orders
            </a>
            <div>
                <h3 class="fw-bold text-dark mb-0">Order #<?= $orderId ?></h3>
                <small class="text-muted">Placed on <?= e(format_date($order['created_at'], 'M d, Y h:i A')) ?></small>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <?= get_order_status_badge($status) ?>
            <?php if (!empty($order['rider_id']) && in_array($status, ['picked_up', 'out_for_delivery', 'ready_for_delivery', 'delivered'], true)): ?>
                <button type="button" class="btn btn-outline-primary position-relative" id="btnOpenChat" data-bs-toggle="offcanvas" data-bs-target="#chatPanel">
                    <i class="bi bi-chat-dots-fill me-1"></i>Chat with Rider
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none" id="chatUnreadBadge">0</span>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-4" data-aos="fade-up">
        <!-- 6-Step Visual Timeline (Always Visible) -->
        <?php if (!$isCancelled): ?>
            <div class="col-12">
                <div class="card border-0 shadow-sm p-4 bg-white">
                    <h6 class="fw-bold text-dark mb-3">Order Status Progression</h6>
                    <div class="app-order-timeline m-0">
                        <?php foreach ($stepOrder as $idx => $stepKey): ?>
                            <?php
                            $isCompleted = ($idx <= $currentIndex);
                            $isActive = ($idx === $currentIndex);
                            $stepClass = $isCompleted ? ($isActive ? 'active' : 'completed') : '';
                            ?>
                            <div class="timeline-step <?= $stepClass ?>">
                                <div class="timeline-step-icon"><?= $steps[$stepKey]['icon'] ?></div>
                                <div class="timeline-step-label"><?= $steps[$stepKey]['label'] ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="col-12">
                <div class="alert alert-danger border-0 shadow-sm d-flex align-items-center gap-3 mb-0 p-3">
                    <i class="bi bi-x-circle-fill fs-3 text-danger"></i>
                    <div>
                        <strong class="d-block">This order has been cancelled</strong>
                        <span class="small">Cancellation reason: <?= e($order['cancel_reason'] ?? 'No reason specified.') ?></span>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Left Column: Item & Payment Details -->
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100 bg-white">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="fw-bold text-dark mb-0"><i class="bi bi-box-seam text-primary me-2"></i>Ordered Item & Payment</h6>
                </div>
                <div class="card-body p-4">
                    <div class="d-flex align-items-center gap-3 p-3 bg-light rounded-3 mb-4">
                        <div class="app-product-icon rounded-3 bg-primary text-white flex-shrink-0">
                            <i class="bi bi-fire fs-4"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h5 class="fw-bold text-dark mb-1"><?= e($order['product_name'] ?? 'LPG Cylinder') ?></h5>
                            <div class="d-flex gap-2">
                                <span class="badge bg-secondary-subtle text-secondary"><?= e($order['brand'] ?? $order['product_brand'] ?? 'Standard') ?></span>
                                <span class="badge bg-secondary-subtle text-secondary"><?= e($order['weight'] ?? $order['product_weight'] ?? '11kg') ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="table-responsive mb-3">
                        <table class="table align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Item</th>
                                    <th class="text-center">Quantity</th>
                                    <th class="text-end">Unit Price</th>
                                    <th class="text-end">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="fw-semibold text-dark"><?= e($order['product_name'] ?? 'LPG Cylinder') ?></td>
                                    <td class="text-center"><?= (int)$order['quantity'] ?></td>
                                    <td class="text-end"><?= e(format_currency($order['unit_price'] ?? 0)) ?></td>
                                    <td class="text-end fw-bold"><?= e(format_currency($order['total_amount'] ?? 0)) ?></td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="3" class="text-end text-muted">Delivery Fee:</td>
                                    <td class="text-end fw-semibold text-success">FREE</td>
                                </tr>
                                <tr class="table-light">
                                    <td colspan="3" class="text-end fw-bold fs-6 text-dark">Total Amount:</td>
                                    <td class="text-end fw-bold fs-5 text-primary"><?= e(format_currency($order['total_amount'] ?? 0)) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <div class="p-3 rounded-3 border d-flex justify-content-between align-items-center <?= ($order['payment_method'] ?? '') === 'GCASH' ? 'bg-primary-subtle border-primary-subtle' : 'bg-warning-subtle border-warning-subtle' ?>">
                        <div>
                            <span class="text-muted extra-small text-uppercase fw-semibold d-block">Payment Method</span>
                            <strong class="text-dark fs-6"><?= ($order['payment_method'] ?? '') === 'GCASH' ? 'GCash (Digital Payment)' : 'Cash on Delivery (COD)' ?></strong>
                        </div>
                        <span class="badge <?= ($order['payment_method'] ?? '') === 'GCASH' ? 'bg-primary text-white' : 'bg-warning text-dark' ?> px-3 py-2 fs-6">
                            <?= e($order['payment_method'] ?? 'COD') ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column: Delivery Information & Actions -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100 bg-white d-flex flex-column">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="fw-bold text-dark mb-0"><i class="bi bi-geo-alt text-danger me-2"></i>Delivery Information</h6>
                </div>
                <div class="card-body p-4 flex-grow-1">
                    <div class="mb-3">
                        <label class="text-muted extra-small text-uppercase fw-semibold d-block">Delivery Address</label>
                        <div class="fw-semibold text-dark fs-6 mt-1"><?= e($order['delivery_address'] ?? 'No address provided') ?></div>
                    </div>

                    <div class="mb-3">
                        <label class="text-muted extra-small text-uppercase fw-semibold d-block mb-1">Location Pin</label>
                        <div class="pin-location-map rounded-3 border"
                             id="customerPinMapAddress"
                             data-address="<?= e($order['delivery_address'] ?? '') ?>"></div>
                    </div>

                    <div class="mb-3">
                        <label class="text-muted extra-small text-uppercase fw-semibold d-block">Contact Phone</label>
                        <div class="fw-semibold text-dark mt-1">
                            <i class="bi bi-telephone me-1 text-primary"></i><?= e($order['contact_phone'] ?? 'N/A') ?>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="text-muted extra-small text-uppercase fw-semibold d-block">Assigned Delivery Rider</label>
                        <div class="fw-semibold text-dark mt-1">
                            <?php if (!empty($order['rider_name'])): ?>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="user-avatar-circle bg-primary" style="width: 28px; height: 28px; font-size: 0.75rem;">
                                        <?= e(strtoupper(substr($order['rider_name'], 0, 1))) ?>
                                    </div>
                                    <span><?= e($order['rider_name']) ?></span>
                                    <?php if (!empty($order['rider_phone'])): ?>
                                        <a href="tel:<?= e($order['rider_phone']) ?>" class="btn btn-sm btn-outline-primary py-0 px-2 ms-auto">
                                            <i class="bi bi-telephone-fill me-1"></i>Call
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span class="text-muted fst-italic">Awaiting Rider Assignment</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($order['notes'])): ?>
                        <div class="mb-3">
                            <label class="text-muted extra-small text-uppercase fw-semibold d-block">Special Delivery Notes</label>
                            <div class="p-2 bg-light rounded text-muted small mt-1 border">
                                <?= e($order['notes']) ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="card-footer bg-white p-3 border-top d-flex gap-2 justify-content-end">
                    <?php if ($isPending): ?>
                        <button type="button" class="btn btn-outline-danger px-4 fw-semibold btn-cancel-order-detail"
                                data-order-id="<?= $orderId ?>"
                                data-csrf="<?= csrf_token() ?>">
                            <i class="bi bi-x-circle me-1"></i>Cancel Order
                        </button>
                    <?php endif; ?>

                    <?php if ($isDelivered || $isCancelled): ?>
                        <a href="<?= url('pages/customer/shop.php?reorder_product_id=' . (int)($order['product_id'] ?? 0) . '&qty=' . (int)$order['quantity']) ?>" 
                           class="btn btn-primary px-4 fw-semibold shadow-sm">
                            <i class="bi bi-arrow-repeat me-1"></i>Reorder Product
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Live Animated Tracking Map for In-Transit Orders -->
        <?php if ($isInTransit): ?>
            <div class="col-12">
                <div class="card border-0 shadow-sm bg-white">
                    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                        <h6 class="fw-bold text-dark mb-0">
                            <span class="live-pulse-dot me-2"></span>Live Delivery Tracking Map
                        </h6>
                        <span class="badge bg-primary-subtle text-primary">GPS En Route</span>
                    </div>
                    <div class="card-body p-4">
                        <div id="detailMap_<?= $orderId ?>" class="order-detail-map mb-3"
                             data-order-id="<?= $orderId ?>"
                             data-address="<?= e($order['delivery_address'] ?? 'Manila') ?>"
                             data-lat="<?= e($order['delivery_latitude'] ?? '') ?>"
                             data-lng="<?= e($order['delivery_longitude'] ?? '') ?>"
                             data-status="<?= e($status) ?>"
                             data-rider-name="<?= e($order['rider_name'] ?? 'Delivery Rider') ?>"></div>
                        <div class="d-flex align-items-center justify-content-between small text-muted">
                            <div id="detailMapStatus_<?= $orderId ?>" class="fw-semibold text-primary">
                                <i class="bi bi-truck me-1"></i>Rider is preparing for dispatch...
                            </div>
                            <span class="extra-small">Simulated Real-Time Tracking</span>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Chat Offcanvas Panel -->
<div class="offcanvas offcanvas-end chat-offcanvas" tabindex="-1" id="chatPanel" aria-labelledby="chatPanelLabel">
    <div class="offcanvas-header chat-header border-bottom">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-chat-dots-fill text-primary fs-5"></i>
            <div>
                <h6 class="offcanvas-title fw-bold mb-0" id="chatPanelLabel">Chat with Rider</h6>
                <small class="text-muted" id="chatPartnerName"><?= e($order['rider_name'] ?? 'Rider') ?></small>
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
