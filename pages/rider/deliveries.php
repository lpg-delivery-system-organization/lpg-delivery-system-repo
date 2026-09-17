<?php
/**
 * Rider Deliveries Portal
 * LPG Delivery System v2
 *
 * Displays active deliveries assigned to the logged-in rider with sequential
 * status progression actions (picked_up -> out_for_delivery -> delivered),
 * customer contact info with tel: links, delivery address with copy shortcut,
 * product details, payment collection alerts (COD vs GCash), notes, and
 * completed delivery history.
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

// Handle Status Progression POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        if (is_ajax()) {
            json_response(['success' => false, 'error' => 'Security token invalid or expired.'], 403);
        }
        set_flash('error', 'Security token invalid or expired. Please try again.');
        redirect('/pages/rider/deliveries.php');
        return;
    }

    $action = sanitize_input($_POST['action'] ?? '');
    $orderId = (int)($_POST['order_id'] ?? 0);

    if ($orderId <= 0) {
        if (is_ajax()) {
            json_response(['success' => false, 'error' => 'Invalid order ID.'], 400);
        }
        set_flash('error', 'Invalid order ID specified.');
        redirect('/pages/rider/deliveries.php');
        return;
    }

    $order = $orderModel->findById($orderId);

    // Verify order exists and is assigned to this rider
    if (!$order || (int)($order['rider_id'] ?? 0) !== $riderId) {
        if (is_ajax()) {
            json_response(['success' => false, 'error' => 'Order not found or not assigned to you.'], 403);
        }
        set_flash('error', 'Order not found or unauthorized access.');
        redirect('/pages/rider/deliveries.php');
        return;
    }

    $currentStatus = $order['status'] ?? '';

    try {
        if ($action === 'update_status' || $action === 'advance_status') {
            $targetStatus = sanitize_input($_POST['status'] ?? '');

            // Allowed sequential transitions for rider
            $allowedRiderTransitions = [
                'ready_for_delivery' => 'picked_up',
                'picked_up'          => 'out_for_delivery',
                'out_for_delivery'   => 'delivered'
            ];

            // If no specific target status provided, calculate next valid state
            if (empty($targetStatus)) {
                $targetStatus = $allowedRiderTransitions[$currentStatus] ?? '';
            }

            if (empty($targetStatus) || !isset($allowedRiderTransitions[$currentStatus]) || $allowedRiderTransitions[$currentStatus] !== $targetStatus) {
                throw new InvalidArgumentException("Cannot transition delivery #{$orderId} from '{$currentStatus}' to '{$targetStatus}'.");
            }

            // Perform status update (Order::updateStatus sets delivered_at on 'delivered')
            $orderModel->updateStatus($orderId, $targetStatus);

            $statusLabels = [
                'picked_up'        => 'Picked Up',
                'out_for_delivery' => 'Out for Delivery',
                'delivered'        => 'Delivered'
            ];
            $label = $statusLabels[$targetStatus] ?? ucfirst(str_replace('_', ' ', $targetStatus));

            $successMsg = "Order #{$orderId} has been updated to {$label}.";
            if ($targetStatus === 'delivered') {
                $successMsg = "Order #{$orderId} marked as successfully Delivered!";
            }

            if (is_ajax()) {
                json_response([
                    'success'  => true,
                    'message'  => $successMsg,
                    'order_id' => $orderId,
                    'status'   => $targetStatus
                ]);
            }

            set_flash('success', $successMsg);
        } else {
            throw new InvalidArgumentException('Unknown delivery action.');
        }
    } catch (Throwable $e) {
        if (is_ajax()) {
            json_response(['success' => false, 'error' => $e->getMessage()], 400);
        }
        set_flash('error', $e->getMessage());
    }

    redirect('/pages/rider/deliveries.php');
    return;
}

// Retrieve all orders assigned to this rider
$allDeliveries = $orderModel->getByRider($riderId);

// Calculate metrics
$activeStatuses = ['ready_for_delivery', 'picked_up', 'out_for_delivery'];
$activeDeliveries = [];
$completedDeliveries = [];

$countAll = count($allDeliveries);
$countActive = 0;
$countPickedUp = 0;
$countOutForDelivery = 0;
$countDelivered = 0;
$codToCollect = 0.0;

foreach ($allDeliveries as $del) {
    $st = $del['status'] ?? 'pending';
    if (in_array($st, $activeStatuses, true)) {
        $countActive++;
        $activeDeliveries[] = $del;
        if (($del['payment_method'] ?? 'cod') === 'cod') {
            $codToCollect += (float)($del['total_amount'] ?? 0);
        }
        if ($st === 'picked_up') {
            $countPickedUp++;
        } elseif ($st === 'out_for_delivery') {
            $countOutForDelivery++;
        }
    } elseif ($st === 'delivered') {
        $countDelivered++;
        $completedDeliveries[] = $del;
    }
}

$page_title = 'My Deliveries';
$current_page = 'deliveries';
$page_js = 'rider.js';

require_once __DIR__ . '/../../templates/header.php';
require_once __DIR__ . '/../../templates/components/order-card.php';
?>

<div class="container-fluid px-0" id="riderDeliveriesContainer">
    <!-- Header Section -->
    <div class="card mb-4 rounded-3 overflow-hidden position-relative app-banner-gradient text-white" data-aos="fade-down">
        <div class="card-body p-4 p-lg-5 position-relative" style="z-index: 2;">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div>
                    <h3 class="fw-bold mb-1 d-flex align-items-center gap-2">
                        <i class="bi bi-truck"></i>My Assigned Deliveries
                    </h3>
                    <p class="text-white-50 mb-0 small">Manage your active delivery queue, advance delivery stages, and view delivery history.</p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a href="<?= url('pages/rider/available.php') ?>" class="btn btn-warning shadow-sm px-3 fw-semibold text-dark">
                        <i class="bi bi-inbox me-1"></i>Available Orders
                        <?php
                        $availableCount = count($orderModel->getAvailableForRider());
                        if ($availableCount > 0): ?>
                            <span class="badge bg-white text-primary ms-1"><?= $availableCount ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="<?= url('pages/rider/deliveries.php') ?>" class="btn btn-outline-light btn-sm px-3 fw-semibold">
                        <i class="bi bi-arrow-clockwise me-1"></i>Refresh
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary Metrics Cards -->
    <div class="row g-3 mb-4">
        <!-- Active Deliveries -->
        <div class="col-sm-6 col-xl-3">
            <div class="card app-stat-card border-0 shadow-sm p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted extra-small text-uppercase fw-semibold">Active Deliveries</span>
                        <h3 class="fw-bold my-1 text-primary"><?= $countActive ?></h3>
                        <span class="extra-small text-muted"><?= $countOutForDelivery ?> out for delivery</span>
                    </div>
                    <div class="stat-icon-wrapper bg-primary-subtle text-primary">
                        <i class="bi bi-truck"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Picked Up (In Transit) -->
        <div class="col-sm-6 col-xl-3">
            <div class="card app-stat-card border-0 shadow-sm p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted extra-small text-uppercase fw-semibold">Picked Up / Ready</span>
                        <h3 class="fw-bold my-1 text-info"><?= $countPickedUp + ($countActive - $countPickedUp - $countOutForDelivery) ?></h3>
                        <span class="extra-small text-muted"><?= $countPickedUp ?> in vehicle</span>
                    </div>
                    <div class="stat-icon-wrapper bg-info-subtle text-info">
                        <i class="bi bi-box-seam"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Completed Deliveries -->
        <div class="col-sm-6 col-xl-3">
            <div class="card app-stat-card border-0 shadow-sm p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted extra-small text-uppercase fw-semibold">Delivered</span>
                        <h3 class="fw-bold my-1 text-success"><?= $countDelivered ?></h3>
                        <span class="extra-small text-muted">Completed deliveries</span>
                    </div>
                    <div class="stat-icon-wrapper bg-success-subtle text-success">
                        <i class="bi bi-check2-circle"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- COD Cash to Collect -->
        <div class="col-sm-6 col-xl-3">
            <div class="card app-stat-card border-0 shadow-sm p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted extra-small text-uppercase fw-semibold">COD Cash to Collect</span>
                        <h3 class="fw-bold my-1 text-warning-emphasis"><?= e(format_currency($codToCollect)) ?></h3>
                        <span class="extra-small text-muted">From active orders</span>
                    </div>
                    <div class="stat-icon-wrapper bg-warning-subtle text-warning-emphasis">
                        <i class="bi bi-cash-stack"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Buttons Bar -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-2 d-flex flex-wrap gap-2 align-items-center justify-content-between">
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <button type="button" class="btn btn-sm btn-primary active rider-filter-btn px-3" data-filter="all">
                    All Deliveries <span class="badge bg-white text-primary ms-1"><?= $countAll ?></span>
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary rider-filter-btn px-3" data-filter="active">
                    Active <span class="badge bg-primary ms-1"><?= $countActive ?></span>
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary rider-filter-btn px-3" data-filter="picked_up">
                    Picked Up <span class="badge bg-secondary ms-1"><?= $countPickedUp ?></span>
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary rider-filter-btn px-3" data-filter="out_for_delivery">
                    Out for Delivery <span class="badge bg-secondary ms-1"><?= $countOutForDelivery ?></span>
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary rider-filter-btn px-3" data-filter="delivered">
                    Delivered <span class="badge bg-secondary ms-1"><?= $countDelivered ?></span>
                </button>
            </div>
            <div class="d-flex align-items-center">
                <div class="input-group input-group-sm" style="max-width: 280px;">
                    <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" class="form-control" id="riderDeliverySearch" placeholder="Search customer, address, #ID...">
                </div>
            </div>
        </div>
    </div>

    <!-- Deliveries List -->
    <?php if (empty($allDeliveries)): ?>
        <div class="card border-0 shadow-sm p-5 text-center my-4">
            <div class="mb-3 text-muted">
                <i class="bi bi-truck fs-1 opacity-50"></i>
            </div>
            <h5 class="fw-bold">No deliveries assigned</h5>
            <p class="text-muted small mb-3">You currently have no active or completed deliveries assigned to your account.</p>
            <div>
                <a href="<?= url('pages/rider/available.php') ?>" class="btn btn-primary px-4">
                    <i class="bi bi-inbox me-1"></i>Browse Available Orders to Claim
                </a>
            </div>
        </div>
    <?php else: ?>
        <div id="riderDeliveriesList">
            <?php foreach ($allDeliveries as $delivery): ?>
                <?php
                $orderId = (int)$delivery['id'];
                $status = (string)($delivery['status'] ?? 'pending');
                $customerName = (string)($delivery['customer_name'] ?? 'Customer');
                $contactPhone = (string)($delivery['contact_phone'] ?? $delivery['customer_phone'] ?? '');
                $deliveryAddress = (string)($delivery['delivery_address'] ?? '');
                $productName = (string)($delivery['product_name'] ?? 'LPG Cylinder');
                $brand = (string)($delivery['product_brand'] ?? $delivery['brand'] ?? '');
                $weight = (string)($delivery['product_weight'] ?? $delivery['weight'] ?? '');
                $quantity = (int)($delivery['quantity'] ?? 1);
                $unitPrice = (float)($delivery['unit_price'] ?? 0);
                $totalAmount = (float)($delivery['total_amount'] ?? 0);
                $paymentMethod = strtoupper((string)($delivery['payment_method'] ?? 'COD'));
                $notes = (string)($delivery['notes'] ?? '');
                $createdAt = (string)($delivery['created_at'] ?? '');
                $deliveredAt = (string)($delivery['delivered_at'] ?? '');
                $productImage = (string)($delivery['product_image'] ?? $delivery['image_url'] ?? '');

                $isReady = ($status === 'ready_for_delivery');
                $isPickedUp = ($status === 'picked_up');
                $isOutForDelivery = ($status === 'out_for_delivery');
                $isDelivered = ($status === 'delivered');
                $isActive = in_array($status, $activeStatuses, true);
                ?>
                <div class="card app-order-card app-clickable-card shadow-sm border-0 mb-4 rider-delivery-card"
                     data-order-id="<?= $orderId ?>"
                     data-status="<?= e($status) ?>"
                     data-is-active="<?= $isActive ? '1' : '0' ?>"
                     data-customer="<?= e(strtolower($customerName)) ?>"
                     data-address="<?= e(strtolower($deliveryAddress)) ?>"
                     data-phone="<?= e($contactPhone) ?>"
                     data-href="<?= url('pages/rider/order-detail.php?id=' . $orderId) ?>">

                    <!-- Card Header -->
                    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center py-3 border-bottom gap-2">
                        <div class="d-flex align-items-center gap-2">
                            <a href="<?= url('pages/rider/order-detail.php?id=' . $orderId) ?>" class="fw-bold fs-5 text-primary text-decoration-none">
                                #<?= $orderId ?> <i class="bi bi-box-arrow-up-right fs-6 ms-1"></i>
                            </a>
                            <span class="text-muted small">&bull;</span>
                            <small class="text-muted"><i class="bi bi-clock me-1"></i>Assigned <?= e(format_date($createdAt)) ?></small>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <?= get_order_status_badge($status) ?>
                        </div>
                    </div>

                    <?php
                    // Delivery progress: 1 = picked up, 2 = out for delivery, 3 = delivered
                    $stepIndex = match ($status) {
                        'picked_up' => 1,
                        'out_for_delivery' => 2,
                        'delivered' => 3,
                        default => 0,
                    };
                    $stepLabels = ['Picked up', 'On the way', 'Delivered'];
                    ?>
                    <div class="px-3 px-md-4 pt-3">
                        <ol class="delivery-steps" aria-label="Delivery progress">
                            <?php foreach ($stepLabels as $i => $label): ?>
                                <?php $stepNo = $i + 1; ?>
                                <li class="<?= $stepNo <= $stepIndex ? 'done' : ($stepNo === $stepIndex + 1 && $stepIndex < 3 ? 'current' : '') ?>"><?= e($label) ?></li>
                            <?php endforeach; ?>
                        </ol>
                    </div>

                    <!-- Card Body -->
                    <div class="card-body p-3 p-md-4">
                        <div class="row g-4">
                            <!-- Customer & Delivery Location -->
                            <div class="col-lg-6">
                                <h6 class="text-muted text-uppercase fw-semibold small mb-3">
                                    <i class="bi bi-geo-alt-fill text-danger me-1"></i>Customer & Delivery Location
                                </h6>
                                <div class="p-3 bg-light rounded-3 border">
                                    <div class="fw-bold fs-6 text-dark mb-1 d-flex align-items-center justify-content-between">
                                        <span><?= e($customerName) ?></span>
                                        <?php if ($contactPhone): ?>
                                            <a href="tel:<?= e($contactPhone) ?>" class="btn btn-outline-primary btn-sm py-0 px-2 fw-semibold" title="Call Customer">
                                                <i class="bi bi-telephone-fill me-1"></i>Call
                                            </a>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Phone Number -->
                                    <div class="small mb-2">
                                        <i class="bi bi-telephone text-primary me-2"></i>
                                        <?php if ($contactPhone): ?>
                                            <a href="tel:<?= e($contactPhone) ?>" class="text-decoration-none fw-medium text-dark">
                                                <?= e($contactPhone) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">No phone provided</span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Full Address -->
                                    <div class="small mb-2">
                                        <div class="d-flex align-items-start gap-2">
                                            <i class="bi bi-geo-alt text-danger mt-1 flex-shrink-0"></i>
                                            <div class="flex-grow-1">
                                                <span class="fw-semibold text-dark">Address:</span>
                                                <span class="text-dark d-block"><?= e($deliveryAddress ?: 'No address specified') ?></span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Copy Address & Map Shortcuts -->
                                    <div class="d-flex gap-2 pt-2 border-top">
                                        <button type="button" 
                                                class="btn btn-light btn-sm border px-2 extra-small btn-copy-address" 
                                                data-address="<?= e($deliveryAddress) ?>" 
                                                title="Copy full address to clipboard">
                                            <i class="bi bi-clipboard me-1"></i>Copy Address
                                        </button>
                                        <a href="https://maps.google.com/?q=<?= urlencode($deliveryAddress) ?>" 
                                           target="_blank" 
                                           rel="noopener noreferrer" 
                                           class="btn btn-light btn-sm border px-2 extra-small text-decoration-none">
                                            <i class="bi bi-map me-1 text-primary"></i>Open Map
                                        </a>
                                    </div>

                                    <?php if ($notes): ?>
                                        <div class="mt-3 p-2 bg-white rounded border border-warning-subtle text-muted extra-small">
                                            <i class="bi bi-chat-left-dots text-warning me-1"></i>
                                            <strong>Customer Note:</strong> <?= e($notes) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Product & Payment Details -->
                            <div class="col-lg-6">
                                <h6 class="text-muted text-uppercase fw-semibold small mb-3">
                                    <i class="bi bi-box-seam text-primary me-1"></i>Order & Payment Details
                                </h6>
                                <div class="p-3 bg-light rounded-3 border">
                                    <!-- Product Summary -->
                                    <div class="d-flex align-items-start gap-3 mb-3">
                                        <div class="app-product-icon p-2 bg-white rounded border text-center flex-shrink-0 overflow-hidden" style="width: 56px; height: 56px;">
                                            <?php if (!empty($productImage) && is_file(dirname(__DIR__, 2) . '/' . ltrim($productImage, '/'))): ?>
                                                <img src="<?= e(asset($productImage)) ?>" alt="<?= e($productName) ?>" class="rounded" style="width: 100%; height: 100%; object-fit: cover;">
                                            <?php else: ?>
                                                <i class="bi bi-fire fs-3 text-warning"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <h6 class="mb-1 fw-bold text-dark"><?= e($productName) ?></h6>
                                            <p class="text-muted small mb-1">
                                                <?php if ($brand): ?><span class="badge bg-secondary-subtle text-secondary me-1"><?= e($brand) ?></span><?php endif; ?>
                                                <?php if ($weight): ?><span class="badge bg-info-subtle text-info-emphasis me-1"><?= e($weight) ?></span><?php endif; ?>
                                            </p>
                                            <div class="small text-muted">
                                                <span>Qty: <strong class="text-dark"><?= $quantity ?></strong></span>
                                                <span class="mx-2">&bull;</span>
                                                <span>Price: <strong class="text-dark"><?= e(format_currency($unitPrice)) ?></strong></span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Payment Alert Badge / Box -->
                                    <div class="p-2 rounded mb-2 <?= $paymentMethod === 'COD' ? 'bg-warning-subtle border border-warning' : 'bg-primary-subtle border border-primary' ?>">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="d-flex align-items-center gap-2">
                                                <i class="bi <?= $paymentMethod === 'COD' ? 'bi-cash-coin fs-4 text-warning-emphasis' : 'bi-check-circle-fill fs-4 text-primary' ?>"></i>
                                                <div>
                                                    <strong class="d-block text-dark small">
                                                        <?= $paymentMethod === 'COD' ? 'Cash on Delivery (COD)' : 'GCash (Paid Online)' ?>
                                                    </strong>
                                                    <span class="extra-small text-muted">
                                                        <?= $paymentMethod === 'COD' ? 'Please collect cash payment upon delivery' : 'Payment verified online, no cash collection' ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="text-end">
                                                <span class="extra-small text-muted d-block"><?= $paymentMethod === 'COD' ? 'Amount to Collect' : 'Total Paid' ?></span>
                                                <span class="fw-bold fs-6 <?= $paymentMethod === 'COD' ? 'text-danger' : 'text-primary' ?>">
                                                    <?= e(format_currency($totalAmount)) ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Timestamps info -->
                                    <div class="extra-small text-muted d-flex flex-column gap-1 mt-2">
                                        <div><i class="bi bi-calendar3 me-1"></i>Placed: <?= e(format_date($createdAt)) ?></div>
                                        <?php if ($isDelivered && $deliveredAt): ?>
                                            <div class="text-success fw-semibold">
                                                <i class="bi bi-check2-circle me-1"></i>Delivered: <?= e(format_date($deliveredAt)) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Card Footer: Sequential Status Progression -->
                    <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center py-3 border-top gap-2">
                        <div class="d-flex align-items-baseline gap-2">
                            <span class="text-muted small">Total Order Value:</span>
                            <span class="fs-5 fw-bold text-dark"><?= e(format_currency($totalAmount)) ?></span>
                            <span class="badge <?= $paymentMethod === 'COD' ? 'bg-warning text-dark' : 'bg-primary' ?> ms-1 extra-small"><?= e($paymentMethod) ?></span>
                        </div>

                        <!-- Status Action Controls -->
                        <div class="d-flex gap-2 align-items-center">
                            <!-- If Ready for Delivery: Button "Pick Up Order" -->
                            <?php if ($isReady): ?>
                                <form action="<?= url('pages/rider/deliveries.php') ?>" method="POST" class="m-0">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="order_id" value="<?= $orderId ?>">
                                    <input type="hidden" name="status" value="picked_up">
                                    <button type="submit" class="btn btn-primary px-3 fw-semibold shadow-sm">
                                        <i class="bi bi-box-seam me-1"></i>Pick Up Order
                                    </button>
                                </form>
                            <?php endif; ?>

                            <!-- If Picked Up: Button "Mark Out for Delivery" -->
                            <?php if ($isPickedUp): ?>
                                <form action="<?= url('pages/rider/deliveries.php') ?>" method="POST" class="m-0">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="order_id" value="<?= $orderId ?>">
                                    <input type="hidden" name="status" value="out_for_delivery">
                                    <button type="submit" class="btn btn-info text-white px-3 fw-semibold shadow-sm">
                                        <i class="bi bi-truck me-1"></i>Mark Out for Delivery
                                    </button>
                                </form>
                            <?php endif; ?>

                            <!-- If Out for Delivery: Button "Mark Delivered" -->
                            <?php if ($isOutForDelivery): ?>
                                <button type="button" 
                                        class="btn btn-success px-4 fw-semibold shadow-sm btn-open-deliver-modal"
                                        data-order-id="<?= $orderId ?>"
                                        data-customer="<?= e($customerName) ?>"
                                        data-amount="<?= e(format_currency($totalAmount)) ?>"
                                        data-payment="<?= e($paymentMethod) ?>">
                                    <i class="bi bi-check-circle-fill me-1"></i>Mark Delivered
                                </button>
                            <?php endif; ?>

                            <!-- If Delivered: Status indication -->
                            <?php if ($isDelivered): ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2 fs-6">
                                    <i class="bi bi-check2-all me-1"></i>Delivery Completed
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- No matching filter alert -->
        <div id="noRiderFilteredAlert" class="alert alert-light border text-center p-4 shadow-sm my-4 d-none">
            <i class="bi bi-search fs-3 text-muted d-block mb-2"></i>
            <strong>No deliveries match your selected filter or search query.</strong>
        </div>
    <?php endif; ?>
</div>

<!-- Confirm Delivery Modal -->
<div class="modal fade" id="confirmDeliverModal" tabindex="-1" aria-labelledby="confirmDeliverModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form action="<?= url('pages/rider/deliveries.php') ?>" method="POST" id="confirmDeliverForm">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="order_id" id="deliverModalOrderId" value="0">
                <input type="hidden" name="status" value="delivered">

                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title fw-bold" id="confirmDeliverModalLabel">
                        <i class="bi bi-check-circle-fill me-2"></i>Confirm Order Delivery
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="mb-3">Are you sure you want to mark <strong id="deliverModalOrderNumber" class="text-primary">Order</strong> as delivered to <strong id="deliverModalCustomerName">Customer</strong>?</p>

                    <!-- COD Collection Warning in Modal -->
                    <div id="deliverModalCodAlert" class="p-3 bg-warning-subtle border border-warning rounded mb-3 d-none">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-cash-stack fs-3 text-warning-emphasis"></i>
                            <div>
                                <strong class="d-block text-dark">Collect Cash Payment:</strong>
                                <span class="fs-5 fw-bold text-danger" id="deliverModalCollectAmount">₱0.00</span>
                                <div class="extra-small text-muted">Confirm that the customer has handed over full cash payment.</div>
                            </div>
                        </div>
                    </div>

                    <div id="deliverModalGcashAlert" class="p-3 bg-primary-subtle border border-primary rounded mb-3 d-none">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-shield-check fs-3 text-primary"></i>
                            <div>
                                <strong class="d-block text-dark">GCash Payment Verified</strong>
                                <div class="extra-small text-muted">Customer has prepaid online. No cash collection required.</div>
                            </div>
                        </div>
                    </div>

                    <p class="text-muted extra-small mb-0">This will complete the delivery lifecycle and record the completion timestamp.</p>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success px-4 fw-semibold" id="btnSubmitConfirmDeliver">
                        <i class="bi bi-check2 me-1"></i>Yes, Confirm Delivered
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../../templates/footer.php';
?>
