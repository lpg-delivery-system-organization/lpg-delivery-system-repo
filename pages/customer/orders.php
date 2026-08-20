<?php
/**
 * Customer Order History & Tracking Page
 * LPG Delivery System v2
 *
 * Displays all customer orders with status progression timelines, item details,
 * rider assignment information, transactional cancellation for pending orders,
 * and 1-click reorder shortcuts.
 */

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/middleware.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../classes/Product.php';
require_once __DIR__ . '/../../classes/Order.php';

// Enforce customer role access
require_role('customer');

$db = Database::connect();
$orderModel = new Order($db);
$userModel = new User($db);

$customerId = (int)current_user_id();

// Handle Order Cancellation POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_order') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Security token expired or invalid. Please try again.');
        redirect('/pages/customer/orders.php');
        return;
    }

    $cancelOrderId = (int)($_POST['order_id'] ?? 0);
    $order = $orderModel->findById($cancelOrderId);

    if (!$order || (int)$order['customer_id'] !== $customerId) {
        set_flash('error', 'Order not found or unauthorized access.');
        redirect('/pages/customer/orders.php');
        return;
    }

    if ($order['status'] !== 'pending') {
        set_flash('error', "Order #{$cancelOrderId} cannot be cancelled because it is already {$order['status']}.");
        redirect('/pages/customer/orders.php');
        return;
    }

    try {
        $orderModel->cancel($cancelOrderId, 'Cancelled by customer via portal');
        set_flash('success', "Order #{$cancelOrderId} has been successfully cancelled. Stock has been restored.");
    } catch (Throwable $e) {
        set_flash('error', 'Failed to cancel order: ' . $e->getMessage());
    }

    redirect('/pages/customer/orders.php');
    return;
}

// Retrieve customer's order history
$orders = $orderModel->getByCustomer($customerId);

// Calculate status counts for filtering
$countAll = count($orders);
$countPending = 0;
$countInTransit = 0;
$countDelivered = 0;
$countCancelled = 0;

$inTransitStatuses = ['approved', 'ready_for_delivery', 'picked_up', 'out_for_delivery'];

foreach ($orders as $ord) {
    $st = $ord['status'] ?? 'pending';
    if ($st === 'pending') {
        $countPending++;
    } elseif (in_array($st, $inTransitStatuses, true)) {
        $countInTransit++;
    } elseif ($st === 'delivered') {
        $countDelivered++;
    } elseif ($st === 'cancelled') {
        $countCancelled++;
    }
}

$page_title = 'My Orders';
$current_page = 'orders';
$page_js = 'customer.js';

require_once __DIR__ . '/../../templates/header.php';
require_once __DIR__ . '/../../templates/components/order-card.php';
?>

<div class="container-fluid px-0" id="customerOrdersContainer">
    <!-- Header Section -->
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <h3 class="fw-bold mb-1 d-flex align-items-center gap-2">
                <i class="bi bi-receipt text-primary"></i>My Order History
            </h3>
            <p class="text-muted small mb-0">Track live delivery progress, view receipts, and manage your LPG requests.</p>
        </div>
        <a href="<?= url('pages/customer/shop.php') ?>" class="btn btn-primary shadow-sm px-4">
            <i class="bi bi-plus-lg me-1"></i>New Order
        </a>
    </div>

    <!-- Filter Buttons Bar -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-2 d-flex flex-wrap gap-2 align-items-center">
            <button type="button" class="btn btn-sm btn-primary active order-filter-btn px-3" data-filter="all">
                All Orders <span class="badge bg-white text-primary ms-1"><?= $countAll ?></span>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary order-filter-btn px-3" data-filter="pending">
                Pending <span class="badge bg-secondary ms-1"><?= $countPending ?></span>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary order-filter-btn px-3" data-filter="in_transit">
                In Transit <span class="badge bg-secondary ms-1"><?= $countInTransit ?></span>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary order-filter-btn px-3" data-filter="delivered">
                Delivered <span class="badge bg-secondary ms-1"><?= $countDelivered ?></span>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary order-filter-btn px-3" data-filter="cancelled">
                Cancelled <span class="badge bg-secondary ms-1"><?= $countCancelled ?></span>
            </button>
        </div>
    </div>

    <!-- Orders Listing -->
    <?php if (empty($orders)): ?>
        <div class="card border-0 shadow-sm p-5 text-center my-4">
            <div class="mb-3 text-muted">
                <i class="bi bi-receipt fs-1 opacity-50"></i>
            </div>
            <h5 class="fw-bold">No orders found</h5>
            <p class="text-muted small mb-3">You haven't placed any LPG orders yet.</p>
            <div>
                <a href="<?= url('pages/customer/shop.php') ?>" class="btn btn-primary px-4">
                    <i class="bi bi-shop me-1"></i>Browse Product Catalog
                </a>
            </div>
        </div>
    <?php else: ?>
        <div id="customerOrdersList">
            <?php foreach ($orders as $order): ?>
                <?php
                $orderId = (int)$order['id'];
                $status = (string)($order['status'] ?? 'pending');
                $isPending = ($status === 'pending');
                $isDelivered = ($status === 'delivered');
                $isCancelled = ($status === 'cancelled');
                $isInTransit = in_array($status, $inTransitStatuses, true);

                $productName = (string)($order['product_name'] ?? 'LPG Cylinder');
                $brand = (string)($order['brand'] ?? '');
                $weight = (string)($order['weight'] ?? '');
                $quantity = (int)($order['quantity'] ?? 1);
                $unitPrice = (float)($order['unit_price'] ?? 0);
                $totalAmount = (float)($order['total_amount'] ?? 0);
                $paymentMethod = strtoupper((string)($order['payment_method'] ?? 'COD'));
                $deliveryAddress = (string)($order['delivery_address'] ?? '');
                $contactPhone = (string)($order['contact_phone'] ?? '');
                $notes = (string)($order['notes'] ?? '');
                $createdAt = (string)($order['created_at'] ?? '');
                $deliveredAt = (string)($order['delivered_at'] ?? '');
                $riderName = (string)($order['rider_name'] ?? '');
                $riderPhone = (string)($order['rider_phone'] ?? '');
                $productId = (int)($order['product_id'] ?? 0);
                ?>
                <div class="card app-order-card shadow-sm border-0 mb-4 customer-order-item" 
                     data-order-id="<?= $orderId ?>" 
                     data-status="<?= e($status) ?>">
                    
                    <!-- Card Header -->
                    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center py-3 border-bottom">
                        <div class="d-flex align-items-center gap-2">
                            <span class="fw-bold fs-5 text-primary">#<?= $orderId ?></span>
                            <span class="text-muted small">&bull;</span>
                            <small class="text-muted"><i class="bi bi-clock me-1"></i>Placed on <?= e(format_date($createdAt)) ?></small>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <?= get_order_status_badge($status) ?>
                        </div>
                    </div>

                    <!-- Card Body -->
                    <div class="card-body p-3 p-md-4">
                        <!-- Delivery Stepper Progress Bar (for non-cancelled orders) -->
                        <?php if (!$isCancelled): ?>
                            <?php
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
                            ?>
                            <div class="app-order-timeline d-none d-md-flex mb-4">
                                <?php foreach ($stepOrder as $idx => $stepKey): ?>
                                    <?php
                                    $stepInfo = $steps[$stepKey];
                                    $isCompleted = ($idx <= $currentIndex);
                                    $isActive = ($idx === $currentIndex);
                                    $stepClass = $isCompleted ? ($isActive ? 'active' : 'completed') : '';
                                    ?>
                                    <div class="timeline-step <?= $stepClass ?>">
                                        <div class="timeline-step-icon">
                                            <?= $stepInfo['icon'] ?>
                                        </div>
                                        <div class="timeline-step-label"><?= $stepInfo['label'] ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div class="row g-4">
                            <!-- Product Details -->
                            <div class="col-lg-6">
                                <h6 class="text-muted text-uppercase fw-semibold small mb-3">Item Summary</h6>
                                <div class="d-flex align-items-start gap-3">
                                    <div class="app-product-icon p-2 bg-light rounded text-center">
                                        <i class="bi bi-fire fs-2 text-warning"></i>
                                    </div>
                                    <div>
                                        <h6 class="mb-1 fw-bold text-dark"><?= e($productName) ?></h6>
                                        <p class="text-muted small mb-2">
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
                            </div>

                            <!-- Delivery & Rider Details -->
                            <div class="col-lg-6">
                                <h6 class="text-muted text-uppercase fw-semibold small mb-3">Delivery Information</h6>
                                <div class="small d-flex flex-column gap-1">
                                    <div>
                                        <i class="bi bi-geo-alt text-danger me-2"></i>
                                        <span class="fw-semibold text-dark">Address:</span> <?= e($deliveryAddress ?: 'No address specified') ?>
                                    </div>
                                    <div>
                                        <i class="bi bi-telephone text-primary me-2"></i>
                                        <span class="fw-semibold text-dark">Contact:</span> <?= e($contactPhone ?: 'No phone provided') ?>
                                    </div>
                                    <div>
                                        <i class="bi bi-credit-card text-success me-2"></i>
                                        <span class="fw-semibold text-dark">Payment:</span>
                                        <span class="badge <?= $paymentMethod === 'GCASH' ? 'bg-primary' : 'bg-success' ?> ms-1"><?= e($paymentMethod) ?></span>
                                    </div>
                                    <?php if ($riderName): ?>
                                        <div class="p-2 bg-light rounded mt-1 border border-light-subtle">
                                            <i class="bi bi-truck text-info me-2"></i>
                                            <strong>Assigned Rider:</strong> <?= e($riderName) ?>
                                            <?php if ($riderPhone): ?>
                                                &bull; <a href="tel:<?= e($riderPhone) ?>" class="text-decoration-none"><i class="bi bi-telephone-fill ms-1"></i> <?= e($riderPhone) ?></a>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($isDelivered && $deliveredAt): ?>
                                        <div class="text-success fw-semibold mt-1">
                                            <i class="bi bi-check-circle-fill me-1"></i>Delivered on <?= e(format_date($deliveredAt)) ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($notes): ?>
                                        <div class="text-muted fst-italic mt-1">
                                            <i class="bi bi-chat-left-dots me-2"></i>Notes: <?= e($notes) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Live GPS Delivery Tracking Map (for In-Transit Orders) -->
                        <?php if ($isInTransit): ?>
                            <div class="mt-4 pt-3 border-top">
                                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="live-pulse-dot"></span>
                                        <strong class="text-dark small"><i class="bi bi-geo-alt-fill text-danger me-1"></i>Live Delivery Tracking</strong>
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle extra-small">
                                            <?= $status === 'picked_up' ? 'Rider Picked Up Cylinder' : 'Out for Delivery' ?>
                                        </span>
                                    </div>
                                    <div class="small text-muted">
                                        <span>Rider: <strong><?= e($riderName ?: 'Assigned Rider') ?></strong></span>
                                        <button type="button" class="btn btn-link btn-sm text-decoration-none p-0 ms-2 btn-toggle-order-map" data-target="#map-collapse-<?= $orderId ?>">
                                            <i class="bi bi-chevron-up"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="collapse show" id="map-collapse-<?= $orderId ?>">
                                    <div id="map-<?= $orderId ?>" 
                                         class="order-live-map shadow-sm"
                                         data-map-order-id="<?= $orderId ?>"
                                         data-rider-name="<?= e($riderName ?: 'Delivery Rider') ?>"
                                         data-customer-address="<?= e($deliveryAddress) ?>"
                                         data-status="<?= e($status) ?>">
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center mt-2 px-1 extra-small text-muted">
                                        <span><i class="bi bi-info-circle me-1"></i>Live simulated rider dispatch to <?= e($deliveryAddress) ?></span>
                                        <span id="map-status-text-<?= $orderId ?>" class="fw-semibold text-primary">🚴 Rider is en route...</span>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Card Footer -->
                    <div class="card-footer bg-light d-flex flex-wrap justify-content-between align-items-center py-3 border-top gap-2">
                        <div class="d-flex align-items-baseline gap-2">
                            <span class="text-muted small">Total Amount:</span>
                            <span class="fs-5 fw-bold text-dark"><?= e(format_currency($totalAmount)) ?></span>
                        </div>
                        <div class="d-flex gap-2 flex-wrap">
                            <?php if ($isInTransit): ?>
                                <button type="button" 
                                        class="btn btn-primary btn-sm px-3 btn-track-live-map shadow-sm" 
                                        data-order-id="<?= $orderId ?>"
                                        data-rider-name="<?= e($riderName ?: 'Pedro Reyes (Rider)') ?>"
                                        data-customer-address="<?= e($deliveryAddress ?: 'Delivery Address') ?>"
                                        data-status="<?= e($status) ?>"
                                        data-status-label="<?= $status === 'picked_up' ? 'Picked Up & En Route' : ($status === 'out_for_delivery' ? 'Out for Delivery' : 'Delivery in Progress') ?>">
                                    <i class="bi bi-geo-alt-fill me-1"></i>Track Live Map
                                </button>
                            <?php endif; ?>

                            <?php if ($isPending): ?>
                                <button type="button" 
                                        class="btn btn-outline-danger btn-sm btn-open-cancel-modal px-3" 
                                        data-order-id="<?= $orderId ?>">
                                    <i class="bi bi-x-circle me-1"></i>Cancel Order
                                </button>
                            <?php endif; ?>

                            <?php if ($isDelivered || $isCancelled): ?>
                                <a href="<?= url('pages/customer/shop.php?reorder_product_id=' . $productId . '&qty=' . $quantity) ?>" 
                                   class="btn btn-outline-primary btn-sm px-3">
                                    <i class="bi bi-arrow-repeat me-1"></i>Reorder
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Placeholder when filter matches 0 results -->
        <div id="noFilteredOrdersAlert" class="alert alert-light border text-center p-4 shadow-sm d-none">
            <i class="bi bi-search fs-3 text-muted d-block mb-2"></i>
            <strong>No orders found matching the selected status.</strong>
        </div>
    <?php endif; ?>
</div>

<!-- Cancel Order Confirmation Modal -->
<div class="modal fade" id="cancelOrderModal" tabindex="-1" aria-labelledby="cancelOrderModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form action="<?= url('pages/customer/orders.php') ?>" method="POST">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="cancel_order">
                <input type="hidden" name="order_id" id="cancelModalOrderId" value="0">

                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title fw-bold" id="cancelOrderModalLabel">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>Confirm Order Cancellation
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="mb-2">Are you sure you want to cancel <strong id="cancelModalOrderNumber" class="text-danger">Order</strong>?</p>
                    <p class="text-muted small mb-0">This will release the reserved LPG cylinder(s) back into inventory immediately.</p>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">Keep Order</button>
                    <button type="submit" class="btn btn-danger px-4 fw-semibold">Yes, Cancel Order</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Live Delivery Tracking Map Modal -->
<div class="modal fade" id="liveTrackingMapModal" tabindex="-1" aria-labelledby="liveTrackingMapModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg overflow-hidden">
            <div class="modal-header bg-dark text-white py-3">
                <div class="d-flex align-items-center gap-2">
                    <span class="live-pulse-dot"></span>
                    <h5 class="modal-title fw-bold mb-0" id="liveTrackingMapModalLabel">
                        <i class="bi bi-geo-alt-fill text-danger me-2"></i>Live Delivery Tracking &bull; Order <span id="modalOrderDisplayId" class="text-warning">#0</span>
                    </h5>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <!-- Status & Rider Header -->
                <div class="p-3 bg-light border-bottom d-flex flex-wrap align-items-center justify-content-between gap-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="p-2 bg-primary text-white rounded-circle fs-4 d-flex align-items-center justify-content-center" style="width: 46px; height: 46px;">
                            <i class="bi bi-bicycle"></i>
                        </div>
                        <div>
                            <div class="fw-bold text-dark fs-6" id="modalRiderName">Assigned Rider: Pedro Reyes</div>
                            <div class="extra-small text-muted" id="modalDeliveryAddress">Destination: 123 Rizal St, Caloocan City</div>
                        </div>
                    </div>
                    <div class="text-md-end">
                        <span id="modalStatusBadge" class="badge bg-primary fs-6 px-3 py-2">In Transit</span>
                        <div id="modalEtaText" class="extra-small text-muted mt-1 fw-semibold text-primary">🚴 Rider is en route to your location...</div>
                    </div>
                </div>

                <!-- Leaflet Map Container -->
                <div id="modal-live-map" style="height: 420px; width: 100%; position: relative; z-index: 1;"></div>
            </div>
            <div class="modal-footer bg-light py-2 px-3 d-flex justify-content-between align-items-center">
                <small class="text-muted"><i class="bi bi-info-circle me-1"></i>Real-time GPS simulation powered by OpenStreetMap & Leaflet</small>
                <button type="button" class="btn btn-secondary btn-sm px-4" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../../templates/footer.php';
?>
