<?php
/**
 * Customer LPG Catalog & Order Placement Page
 * LPG Delivery System v2
 *
 * Interactive catalog with real-time stock indicators, product selection,
 * dynamic quantity counter, payment selection, and atomic order placement.
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
$productModel = new Product($db);
$orderModel = new Order($db);
$userModel = new User($db);

$customerId = (int)current_user_id();
$customer = $userModel->findById($customerId);

$products = $productModel->getActive();

$errors = [];
$formData = [
    'product_id'       => (int)($_GET['reorder_product_id'] ?? ($products[0]['id'] ?? 0)),
    'quantity'         => (int)($_GET['qty'] ?? 1),
    'payment_method'   => 'cod',
    'delivery_address' => $customer['address'] ?? '',
    'contact_phone'    => $customer['phone'] ?? '',
    'notes'            => ''
];

// Handle Order Placement POST submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData['product_id'] = (int)($_POST['product_id'] ?? 0);
    $formData['quantity'] = (int)($_POST['quantity'] ?? 1);
    $formData['payment_method'] = sanitize_input($_POST['payment_method'] ?? 'cod');
    $formData['delivery_address'] = sanitize_input($_POST['delivery_address'] ?? '');
    $formData['contact_phone'] = sanitize_input($_POST['contact_phone'] ?? '');
    $formData['notes'] = sanitize_input($_POST['notes'] ?? '');

    // 1. Verify CSRF Token
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your security token is invalid or expired. Please refresh the page and try again.';
    }

    // 2. Validate Product Selection
    if ($formData['product_id'] <= 0) {
        $errors[] = 'Please select a valid LPG cylinder product from the catalog.';
    } else {
        $selectedProduct = $productModel->findById($formData['product_id']);
        if (!$selectedProduct || $selectedProduct['status'] !== 'active') {
            $errors[] = 'The selected product is currently unavailable.';
        } elseif ($formData['quantity'] > (int)$selectedProduct['stock']) {
            $errors[] = "Requested quantity exceeds available stock ({$selectedProduct['stock']} available).";
        }
    }

    // 3. Validate Quantity
    if ($formData['quantity'] <= 0) {
        $errors[] = 'Quantity must be at least 1 unit.';
    }

    // 4. Validate Delivery Address
    if (empty($formData['delivery_address'])) {
        $errors[] = 'Please enter your complete delivery address.';
    }

    // 5. Validate Contact Phone Number
    if (empty($formData['contact_phone'])) {
        $errors[] = 'Please provide a valid contact phone number.';
    } elseif (!preg_match('/^09\d{9}$/', $formData['contact_phone'])) {
        $errors[] = 'Contact phone number must be an 11-digit Philippine mobile number starting with 09 (e.g. 09171234567).';
    }

    // 6. Validate Payment Method
    if (!in_array($formData['payment_method'], ['cod', 'gcash'], true)) {
        $errors[] = 'Please select a valid payment method (Cash on Delivery or GCash).';
    }

    // Process order if no validation errors
    if (empty($errors)) {
        try {
            $newOrderId = $orderModel->place([
                'customer_id'      => $customerId,
                'product_id'       => $formData['product_id'],
                'quantity'         => $formData['quantity'],
                'payment_method'   => $formData['payment_method'],
                'delivery_address' => $formData['delivery_address'],
                'contact_phone'    => $formData['contact_phone'],
                'notes'            => $formData['notes'],
                'status'           => 'pending'
            ]);

            set_flash('success', "Order #{$newOrderId} has been successfully placed! We will process your delivery shortly.");
            redirect('/pages/customer/orders.php');
            return;

        } catch (Throwable $e) {
            $errors[] = 'Failed to place order: ' . $e->getMessage();
        }
    }
}

$page_title = 'Shop LPG Products';
$current_page = 'shop';
$page_js = 'customer.js';

require_once __DIR__ . '/../../templates/header.php';
?>

<div class="container-fluid px-0" id="customerShopContainer">
    <!-- Page Title & Information Banner -->
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <h3 class="fw-bold mb-1 d-flex align-items-center gap-2">
                <i class="bi bi-shop text-primary"></i>LPG Cylinder Catalog
            </h3>
            <p class="text-muted small mb-0">Select your preferred LPG brand and size, configure quantity, and order for doorstep delivery.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2">
                <i class="bi bi-shield-check me-1"></i>100% Safety Certified
            </span>
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2">
                <i class="bi bi-truck me-1"></i>Fast Doorstep Delivery
            </span>
        </div>
    </div>

    <!-- Validation Error Alert -->
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm mb-4" role="alert">
            <div class="d-flex align-items-start gap-2">
                <i class="bi bi-exclamation-triangle-fill fs-5 mt-1 flex-shrink-0"></i>
                <div>
                    <strong class="d-block mb-1">Please correct the following errors:</strong>
                    <ul class="mb-0 ps-3">
                        <?php foreach ($errors as $error): ?>
                            <li><?= e($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Products Grid Column -->
        <div class="col-lg-7 col-xl-8">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h5 class="fw-bold mb-0 text-dark">Available Products (<?= count($products) ?>)</h5>
                <small class="text-muted">Click any item to configure order</small>
            </div>

            <?php if (empty($products)): ?>
                <div class="card border-0 shadow-sm p-5 text-center">
                    <i class="bi bi-box-seam fs-1 text-muted opacity-50 mb-3"></i>
                    <h5 class="fw-bold">No products currently available</h5>
                    <p class="text-muted small">Please check back later or contact customer support.</p>
                </div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($products as $product): ?>
                        <?php
                        $pId = (int)$product['id'];
                        $pStock = (int)$product['stock'];
                        $pPrice = (float)$product['price'];
                        $isOutOfStock = ($pStock <= 0);
                        $isSelected = ($pId === $formData['product_id']);
                        ?>
                        <div class="col-md-6 col-xl-4">
                            <div class="card app-product-card h-100 border-0 shadow-sm <?= $isSelected ? 'border-primary shadow-sm bg-light-subtle' : '' ?>" id="productCard_<?= $pId ?>">
                                <div class="app-product-img-wrapper position-relative text-center p-3">
                                    <?php if (!empty($product['image_url']) && file_exists(dirname(__DIR__, 2) . '/' . ltrim($product['image_url'], '/'))): ?>
                                        <img src="<?= asset($product['image_url']) ?>" alt="<?= e($product['name']) ?>" class="img-fluid">
                                    <?php else: ?>
                                        <div class="d-flex flex-column align-items-center justify-content-center h-100 text-primary">
                                            <i class="bi bi-fire text-warning" style="font-size: 4rem;"></i>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Stock Badge -->
                                    <div class="position-absolute top-0 end-0 p-2">
                                        <?php if ($pStock > 5): ?>
                                            <span class="badge bg-success shadow-sm">In Stock (<?= $pStock ?>)</span>
                                        <?php elseif ($pStock > 0): ?>
                                            <span class="badge bg-warning text-dark shadow-sm">Low Stock (<?= $pStock ?>)</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger shadow-sm">Out of Stock</span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="card-body p-3 d-flex flex-column">
                                    <div class="mb-2">
                                        <span class="badge bg-secondary-subtle text-secondary me-1"><?= e($product['brand']) ?></span>
                                        <span class="badge bg-info-subtle text-info-emphasis"><?= e($product['weight']) ?></span>
                                    </div>

                                    <h6 class="card-title fw-bold text-dark mb-1"><?= e($product['name']) ?></h6>

                                    <div class="mt-auto pt-3 d-flex align-items-center justify-content-between border-top">
                                        <div>
                                            <span class="text-muted extra-small d-block">Price</span>
                                            <span class="fs-5 fw-bold text-primary"><?= e(format_currency($pPrice)) ?></span>
                                        </div>
                                        <button type="button" 
                                                class="btn btn-sm <?= $isSelected ? 'btn-primary' : 'btn-outline-primary' ?> btn-select-product px-3"
                                                data-id="<?= $pId ?>"
                                                data-name="<?= e($product['name']) ?>"
                                                data-brand="<?= e($product['brand']) ?>"
                                                data-weight="<?= e($product['weight']) ?>"
                                                data-price="<?= $pPrice ?>"
                                                data-stock="<?= $pStock ?>"
                                                <?= $isOutOfStock ? 'disabled' : '' ?>>
                                            <?= $isOutOfStock ? 'Sold Out' : ($isSelected ? '<i class="bi bi-check2 me-1"></i>Selected' : 'Select') ?>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Order Summary & Checkout Column -->
        <div class="col-lg-5 col-xl-4">
            <div class="card border-0 shadow-sm rounded-3 sticky-top" style="top: 80px;" id="orderCheckoutSection">
                <div class="card-header bg-white py-3 border-bottom">
                    <h5 class="card-title mb-0 fw-bold d-flex align-items-center gap-2">
                        <i class="bi bi-cart-check text-primary"></i>Order & Delivery Details
                    </h5>
                </div>
                <div class="card-body p-3 p-md-4">
                    <form action="<?= url('pages/customer/shop.php') ?>" method="POST" id="orderPlacementForm">
                        <?= csrf_input() ?>
                        <input type="hidden" name="product_id" id="order_product_id" value="<?= (int)$formData['product_id'] ?>">

                        <!-- Selected Product Summary Box -->
                        <div class="p-3 bg-light rounded-3 mb-3 border">
                            <div class="d-flex align-items-start justify-content-between">
                                <div>
                                    <span class="text-muted extra-small text-uppercase fw-semibold d-block">Selected Cylinder</span>
                                    <h6 class="fw-bold mb-1 text-dark" id="orderProductNameDisplay">LPG Cylinder</h6>
                                    <div id="orderProductMetaDisplay" class="mb-1"></div>
                                    <div id="orderStockStatusDisplay" class="small"></div>
                                </div>
                                <div class="text-end">
                                    <span class="text-muted extra-small text-uppercase fw-semibold d-block">Unit Price</span>
                                    <h5 class="fw-bold text-primary mb-0" id="orderUnitPriceDisplay">₱0.00</h5>
                                </div>
                            </div>
                        </div>

                        <!-- Quantity Selector -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold small text-dark d-flex justify-content-between align-items-center">
                                <span>Quantity</span>
                                <span class="text-muted extra-small">Cylinders to deliver</span>
                            </label>
                            <div class="input-group">
                                <button type="button" class="btn btn-outline-secondary px-3" id="btnQtyMinus" aria-label="Decrease quantity">
                                    <i class="bi bi-dash-lg"></i>
                                </button>
                                <input type="number" 
                                       name="quantity" 
                                       id="order_quantity" 
                                       class="form-control text-center fw-bold fs-5 input-order-qty" 
                                       value="<?= (int)$formData['quantity'] ?>" 
                                       min="1" 
                                       max="10" 
                                       required>
                                <button type="button" class="btn btn-outline-secondary px-3" id="btnQtyPlus" aria-label="Increase quantity">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Payment Method Radio Cards -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold small text-dark">Payment Method</label>
                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="card h-100 p-2 border cursor-pointer payment-method-card <?= $formData['payment_method'] === 'cod' ? 'border-primary bg-primary-subtle' : '' ?>" style="cursor: pointer;">
                                        <div class="d-flex align-items-center gap-2">
                                            <input type="radio" name="payment_method" value="cod" class="form-check-input mt-0" <?= $formData['payment_method'] === 'cod' ? 'checked' : '' ?>>
                                            <div>
                                                <div class="fw-bold small text-dark"><i class="bi bi-cash me-1 text-success"></i>COD</div>
                                                <div class="text-muted extra-small">Cash on Delivery</div>
                                            </div>
                                        </div>
                                    </label>
                                </div>
                                <div class="col-6">
                                    <label class="card h-100 p-2 border cursor-pointer payment-method-card <?= $formData['payment_method'] === 'gcash' ? 'border-primary bg-primary-subtle' : '' ?>" style="cursor: pointer;">
                                        <div class="d-flex align-items-center gap-2">
                                            <input type="radio" name="payment_method" value="gcash" class="form-check-input mt-0" <?= $formData['payment_method'] === 'gcash' ? 'checked' : '' ?>>
                                            <div>
                                                <div class="fw-bold small text-dark"><i class="bi bi-phone me-1 text-primary"></i>GCash</div>
                                                <div class="text-muted extra-small">E-Wallet Payment</div>
                                            </div>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Contact Phone -->
                        <div class="mb-3">
                            <label for="contact_phone" class="form-label fw-semibold small text-dark">
                                Contact Phone <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-white"><i class="bi bi-telephone text-muted"></i></span>
                                <input type="text" 
                                       name="contact_phone" 
                                       id="contact_phone" 
                                       class="form-control" 
                                       placeholder="09171234567" 
                                       maxlength="11" 
                                       value="<?= e($formData['contact_phone']) ?>" 
                                       required>
                            </div>
                            <div class="form-text extra-small">11-digit mobile number for rider delivery confirmation.</div>
                        </div>

                        <!-- Delivery Address -->
                        <div class="mb-3">
                            <label for="delivery_address" class="form-label fw-semibold small text-dark">
                                Delivery Address <span class="text-danger">*</span>
                            </label>
                            <textarea name="delivery_address" 
                                      id="delivery_address" 
                                      rows="2" 
                                      class="form-control" 
                                      placeholder="House/Unit #, Street, Barangay, City, Landmark" 
                                      required><?= e($formData['delivery_address']) ?></textarea>
                        </div>

                        <!-- Delivery Notes / Landmarks (Optional) -->
                        <div class="mb-3">
                            <label for="notes" class="form-label fw-semibold small text-dark">
                                Delivery Notes / Landmarks <span class="text-muted extra-small fw-normal">(Optional)</span>
                            </label>
                            <textarea name="notes" 
                                      id="notes" 
                                      rows="2" 
                                      class="form-control" 
                                      placeholder="e.g. Near blue gate, please call upon arrival"><?= e($formData['notes']) ?></textarea>
                        </div>

                        <!-- Order Pricing Breakdown -->
                        <div class="border-top pt-3 mb-3">
                            <div class="d-flex justify-content-between small text-muted mb-1">
                                <span>Subtotal</span>
                                <span id="orderSubtotalDisplay" class="fw-semibold text-dark">₱0.00</span>
                            </div>
                            <div class="d-flex justify-content-between small text-muted mb-2">
                                <span>Delivery Fee</span>
                                <span class="text-success fw-semibold">FREE</span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center border-top pt-2">
                                <span class="fw-bold text-dark">Total Amount</span>
                                <span class="fs-4 fw-bold text-primary" id="orderTotalDisplay">₱0.00</span>
                            </div>
                        </div>

                        <!-- Submit Order Button -->
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary btn-lg fw-bold shadow-sm" id="btnPlaceOrder">
                                <i class="bi bi-bag-check me-2"></i>Place Order
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../../templates/footer.php';
?>
