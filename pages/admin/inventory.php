<?php
/**
 * Admin Product Catalog & Inventory Management Portal
 * LPG Delivery System v2
 *
 * Provides:
 * - Real-time inventory monitoring and stock status categorization
 * - Add new LPG cylinder products with pricing, weight, and image attributes
 * - Quick stock and price adjustment modal
 * - Full product details update modal
 * - Active / Inactive catalog visibility toggle
 * - AJAX & Synchronous POST mutations protected by CSRF verification
 */

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/middleware.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/Product.php';

// Enforce admin role guard
require_role('admin');

$db = Database::connect();
$productModel = new Product($db);

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        if (is_ajax()) {
            json_response(['success' => false, 'error' => 'Security token invalid or expired.'], 403);
        }
        set_flash('error', 'Security token invalid or expired. Please try again.');
        redirect('/pages/admin/inventory.php');
        return;
    }

    $action = sanitize_input($_POST['action'] ?? '');
    $productId = (int)($_POST['product_id'] ?? 0);

    // Friendly label for feedback popups ("Phoenix 50kg" instead of #59)
    $productLabel = "product #{$productId}";
    if ($productId > 0) {
        $foundProduct = $productModel->findById($productId);
        if (!empty($foundProduct['name'])) {
            $productLabel = "'" . $foundProduct['name'] . "'";
        }
    }

    try {
        switch ($action) {
            case 'create_product':
                $name = sanitize_input($_POST['name'] ?? '');
                $brand = sanitize_input($_POST['brand'] ?? '');
                $weight = sanitize_input($_POST['weight'] ?? '');
                $price = (float)($_POST['price'] ?? 0);
                $stock = (int)($_POST['stock'] ?? 0);
                $imageUrl = sanitize_input($_POST['image_url'] ?? '') ?: null;
                $status = sanitize_input($_POST['status'] ?? 'active');

                if (empty($name) || empty($brand) || empty($weight)) {
                    throw new InvalidArgumentException('Product name, brand, and weight are required.');
                }
                if ($price <= 0) {
                    throw new InvalidArgumentException('Product price must be greater than zero.');
                }
                if ($stock < 0) {
                    throw new InvalidArgumentException('Initial stock cannot be negative.');
                }
                if (!in_array($status, ['active', 'inactive'], true)) {
                    $status = 'active';
                }

                $newId = $productModel->create([
                    'name'      => $name,
                    'brand'     => $brand,
                    'weight'    => $weight,
                    'price'     => $price,
                    'stock'     => $stock,
                    'image_url' => $imageUrl,
                    'status'    => $status
                ]);

                if (is_ajax()) {
                    json_response([
                        'success' => true,
                        'message' => "Product '{$name}' created successfully.",
                        'product_id' => $newId
                    ]);
                }
                set_flash('success', "Product '{$name}' has been added to the catalog.");
                break;

            case 'update_stock_price':
                if ($productId <= 0) {
                    throw new InvalidArgumentException('Invalid product ID.');
                }
                $stock = (int)($_POST['stock'] ?? 0);
                $price = (float)($_POST['price'] ?? 0);

                if ($stock < 0) {
                    throw new InvalidArgumentException('Stock quantity cannot be negative.');
                }
                if ($price <= 0) {
                    throw new InvalidArgumentException('Price must be greater than zero.');
                }

                $productModel->updateStock($productId, $stock);
                $productModel->updatePrice($productId, $price);

                if (is_ajax()) {
                    json_response([
                        'success' => true,
                        'message' => "Stock and price updated for {$productLabel}.",
                        'product_id' => $productId,
                        'stock' => $stock,
                        'price' => $price
                    ]);
                }
                set_flash('success', "Stock ({$stock} units) and price (" . format_currency($price) . ") updated for {$productLabel}.");
                break;

            case 'update_product':
                if ($productId <= 0) {
                    throw new InvalidArgumentException('Invalid product ID.');
                }
                $name = sanitize_input($_POST['name'] ?? '');
                $brand = sanitize_input($_POST['brand'] ?? '');
                $weight = sanitize_input($_POST['weight'] ?? '');
                $price = (float)($_POST['price'] ?? 0);
                $stock = (int)($_POST['stock'] ?? 0);
                $imageUrl = sanitize_input($_POST['image_url'] ?? '') ?: null;
                $status = sanitize_input($_POST['status'] ?? 'active');

                if (empty($name) || empty($brand) || empty($weight)) {
                    throw new InvalidArgumentException('Product name, brand, and weight are required.');
                }
                if ($price <= 0) {
                    throw new InvalidArgumentException('Product price must be greater than zero.');
                }
                if ($stock < 0) {
                    throw new InvalidArgumentException('Stock cannot be negative.');
                }
                if (!in_array($status, ['active', 'inactive'], true)) {
                    $status = 'active';
                }

                $productModel->update($productId, [
                    'name'      => $name,
                    'brand'     => $brand,
                    'weight'    => $weight,
                    'price'     => $price,
                    'stock'     => $stock,
                    'image_url' => $imageUrl,
                    'status'    => $status
                ]);

                if (is_ajax()) {
                    json_response([
                        'success' => true,
                        'message' => "{$productLabel} updated successfully.",
                        'product_id' => $productId
                    ]);
                }
                set_flash('success', "{$productLabel} details updated successfully.");
                break;

            case 'toggle_status':
                if ($productId <= 0) {
                    throw new InvalidArgumentException('Invalid product ID.');
                }
                $productModel->toggleStatus($productId);
                $updated = $productModel->findById($productId);
                $newStatus = $updated['status'] ?? 'active';

                if (is_ajax()) {
                    json_response([
                        'success' => true,
                        'message' => "{$productLabel} is now {$newStatus}.",
                        'product_id' => $productId,
                        'status' => $newStatus
                    ]);
                }
                set_flash('success', "{$productLabel} status changed to " . ucfirst($newStatus) . ".");
                break;

            default:
                throw new InvalidArgumentException('Unknown inventory action requested.');
        }
    } catch (Throwable $e) {
        if (is_ajax()) {
            json_response(['success' => false, 'error' => $e->getMessage()], 400);
        }
        set_flash('error', $e->getMessage());
    }

    redirect('/pages/admin/inventory.php');
    return;
}

// Retrieve all products
$products = $productModel->getAll();

// Compute metric aggregations
$totalProducts = count($products);
$activeCount = 0;
$inactiveCount = 0;
$lowStockCount = 0;
$outOfStockCount = 0;
$totalUnitsInStock = 0;
$brandsList = [];

foreach ($products as $p) {
    $st = $p['status'] ?? 'active';
    $stock = (int)($p['stock'] ?? 0);
    $totalUnitsInStock += $stock;

    if ($st === 'active') {
        $activeCount++;
    } else {
        $inactiveCount++;
    }

    if ($stock === 0) {
        $outOfStockCount++;
    } elseif ($stock <= 5) {
        $lowStockCount++;
    }

    if (!empty($p['brand']) && !in_array($p['brand'], $brandsList, true)) {
        $brandsList[] = $p['brand'];
    }
}
sort($brandsList);

$page_title = 'Inventory Management';
$current_page = 'inventory';
$page_js = 'admin.js';

require_once __DIR__ . '/../../templates/header.php';
?>

<div class="container-fluid px-0" id="adminInventoryContainer">
    <!-- Header Section -->
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4" data-aos="fade-down">
        <div>
            <h3 class="fw-bold mb-1 d-flex align-items-center gap-2">
                <i class="bi bi-tags text-primary"></i>Product Catalog & Inventory
            </h3>
            <p class="text-muted small mb-0">Track cylinder stock levels, manage retail pricing, and configure active catalog visibility.</p>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-primary shadow-sm px-4 fw-semibold" data-bs-toggle="modal" data-bs-target="#addProductModal">
                <i class="bi bi-plus-lg me-1"></i>Add New Product
            </button>
        </div>
    </div>

    <!-- Inventory Stat Cards -->
    <div class="row g-3 mb-4">
        <!-- Total Products -->
        <div class="col-sm-6 col-xl-3" data-aos="fade-up" data-aos-delay="0">
            <div class="card app-stat-card border-0 shadow-sm h-100 p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">Total Catalog</span>
                        <h3 class="fw-bold my-1 text-dark" data-counter-target="<?= $totalProducts ?>"><?= $totalProducts ?></h3>
                        <span class="extra-small text-muted"><?= $activeCount ?> active, <?= $inactiveCount ?> inactive</span>
                    </div>
                    <div class="stat-icon-wrapper bg-primary-subtle text-primary">
                        <i class="bi bi-boxes"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Total Stock Units -->
        <div class="col-sm-6 col-xl-3" data-aos="fade-up" data-aos-delay="100">
            <div class="card app-stat-card border-0 shadow-sm h-100 p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">Total Units</span>
                        <h3 class="fw-bold my-1 text-success" data-counter-target="<?= $totalUnitsInStock ?>"><?= $totalUnitsInStock ?></h3>
                        <span class="extra-small text-muted">Cylinders in warehouse</span>
                    </div>
                    <div class="stat-icon-wrapper bg-success-subtle text-success">
                        <i class="bi bi-box-seam"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Low Stock Items -->
        <div class="col-sm-6 col-xl-3" data-aos="fade-up" data-aos-delay="200">
            <div class="card app-stat-card border-0 shadow-sm h-100 p-3 <?= $lowStockCount > 0 ? 'border-start border-warning border-4' : '' ?>">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">Low Stock</span>
                        <h3 class="fw-bold my-1 text-warning" data-counter-target="<?= $lowStockCount ?>"><?= $lowStockCount ?></h3>
                        <span class="extra-small text-muted">Stock &le; 5 units</span>
                    </div>
                    <div class="stat-icon-wrapper bg-warning-subtle text-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Out of Stock Items -->
        <div class="col-sm-6 col-xl-3" data-aos="fade-up" data-aos-delay="300">
            <div class="card app-stat-card border-0 shadow-sm h-100 p-3 <?= $outOfStockCount > 0 ? 'border-start border-danger border-4' : '' ?>">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">Out of Stock</span>
                        <h3 class="fw-bold my-1 text-danger" data-counter-target="<?= $outOfStockCount ?>"><?= $outOfStockCount ?></h3>
                        <span class="extra-small text-muted">Requires immediate replenishment</span>
                    </div>
                    <div class="stat-icon-wrapper bg-danger-subtle text-danger">
                        <i class="bi bi-x-circle"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Search & Filter Controls -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-3">
            <div class="row g-3 align-items-center">
                <div class="col-md-5">
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" class="form-control border-start-0" id="inventorySearchInput" placeholder="Search by product name, brand, or weight...">
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <select class="form-select" id="inventoryBrandFilter">
                        <option value="all">All Brands (<?= count($brandsList) ?>)</option>
                        <?php foreach ($brandsList as $brand): ?>
                            <option value="<?= e($brand) ?>"><?= e($brand) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 col-sm-6">
                    <select class="form-select" id="inventoryStatusFilter">
                        <option value="all">All Statuses</option>
                        <option value="active">Active Only</option>
                        <option value="inactive">Inactive Only</option>
                        <option value="low_stock">Low / Out of Stock</option>
                    </select>
                </div>
                <div class="col-md-2 text-md-end text-muted small">
                    Showing <strong id="visibleInventoryCount"><?= $totalProducts ?></strong> items
                </div>
            </div>
        </div>
    </div>

    <!-- Inventory Table -->
    <div class="card border-0 shadow-sm rounded-3" data-aos="fade-up">
        <div class="card-body p-0">
            <?php if (empty($products)): ?>
                <div class="text-center py-5 px-3">
                    <div class="mb-3 text-muted">
                        <i class="bi bi-tags fs-1 opacity-50"></i>
                    </div>
                    <h5 class="fw-bold">No products in inventory</h5>
                    <p class="text-muted small mb-3">Add your first LPG cylinder product to start selling.</p>
                    <button type="button" class="btn btn-primary btn-sm px-4" data-bs-toggle="modal" data-bs-target="#addProductModal">
                        <i class="bi bi-plus-lg me-1"></i>Add Product
                    </button>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="adminInventoryTable">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4">ID</th>
                                <th>Product</th>
                                <th>Brand</th>
                                <th>Weight</th>
                                <th>Unit Price</th>
                                <th>Stock Level</th>
                                <th>Status</th>
                                <th>Last Updated</th>
                                <th class="text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $prod): ?>
                                <?php
                                $pId = (int)$prod['id'];
                                $pStock = (int)$prod['stock'];
                                $pPrice = (float)$prod['price'];
                                $pStatus = (string)$prod['status'];
                                $isActive = ($pStatus === 'active');
                                ?>
                                <tr class="admin-inventory-row"
                                    data-id="<?= $pId ?>"
                                    data-name="<?= e($prod['name']) ?>"
                                    data-brand="<?= e($prod['brand']) ?>"
                                    data-weight="<?= e($prod['weight']) ?>"
                                    data-price="<?= $pPrice ?>"
                                    data-stock="<?= $pStock ?>"
                                    data-image="<?= e($prod['image_url'] ?? '') ?>"
                                    data-status="<?= e($pStatus) ?>">

                                    <!-- ID -->
                                    <td class="ps-4 fw-bold text-muted">#<?= $pId ?></td>

                                    <!-- Product Name & Icon -->
                                    <td>
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="app-product-icon p-1 bg-light rounded text-center flex-shrink-0 overflow-hidden" style="width: 40px; height: 40px;">
                                                <?php if (!empty($prod['image_url']) && is_file(dirname(__DIR__, 2) . '/' . ltrim($prod['image_url'], '/'))): ?>
                                                    <img src="<?= e(asset($prod['image_url'])) ?>" alt="<?= e($prod['name']) ?>" class="rounded" style="width: 100%; height: 100%; object-fit: cover;">
                                                <?php else: ?>
                                                    <i class="bi bi-fire text-warning fs-5"></i>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <div class="fw-bold text-dark"><?= e($prod['name']) ?></div>
                                                <small class="text-muted"><?= e($prod['weight']) ?></small>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Brand -->
                                    <td>
                                        <span class="badge bg-secondary-subtle text-secondary fw-semibold"><?= e($prod['brand']) ?></span>
                                    </td>

                                    <!-- Weight -->
                                    <td>
                                        <span class="badge bg-info-subtle text-info-emphasis"><?= e($prod['weight']) ?></span>
                                    </td>

                                    <!-- Price -->
                                    <td class="fw-bold text-dark">
                                        <?= e(format_currency($pPrice)) ?>
                                    </td>

                                    <!-- Stock Level with Badge -->
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="fw-bold fs-6"><?= $pStock ?></span>
                                            <?php if ($pStock === 0): ?>
                                                <span class="badge bg-danger">Out of Stock</span>
                                            <?php elseif ($pStock <= 5): ?>
                                                <span class="badge bg-warning text-dark">Low Stock</span>
                                            <?php else: ?>
                                                <span class="badge bg-success-subtle text-success">In Stock</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <!-- Status -->
                                    <td>
                                        <span class="badge <?= $isActive ? 'bg-success' : 'bg-secondary' ?> text-uppercase px-2 py-1">
                                            <?= e($pStatus) ?>
                                        </span>
                                    </td>

                                    <!-- Updated Date -->
                                    <td class="text-muted small">
                                        <?= e(format_date($prod['updated_at'], 'M d, Y')) ?>
                                    </td>

                                    <!-- Actions -->
                                    <td class="text-end pe-4">
                                        <div class="d-inline-flex gap-1">
                                            <!-- Quick Stock & Price Edit Button -->
                                            <button type="button" 
                                                    class="btn btn-outline-primary btn-sm p-1 px-2 btn-open-stock-modal"
                                                    data-id="<?= $pId ?>"
                                                    data-name="<?= e($prod['name']) ?>"
                                                    data-stock="<?= $pStock ?>"
                                                    data-price="<?= $pPrice ?>"
                                                    title="Quick Adjust Stock & Price">
                                                <i class="bi bi-sliders"></i> Stock
                                            </button>

                                            <!-- Full Edit Modal Button -->
                                            <button type="button" 
                                                    class="btn btn-light btn-sm p-1 px-2 border btn-open-edit-modal"
                                                    data-id="<?= $pId ?>"
                                                    data-name="<?= e($prod['name']) ?>"
                                                    data-brand="<?= e($prod['brand']) ?>"
                                                    data-weight="<?= e($prod['weight']) ?>"
                                                    data-price="<?= $pPrice ?>"
                                                    data-stock="<?= $pStock ?>"
                                                    data-image="<?= e($prod['image_url'] ?? '') ?>"
                                                    data-status="<?= e($pStatus) ?>"
                                                    title="Edit Product Details">
                                                <i class="bi bi-pencil"></i>
                                            </button>

                                            <!-- Toggle Status Button -->
                                            <form action="<?= url('pages/admin/inventory.php') ?>" method="POST" class="d-inline">
                                                <?= csrf_input() ?>
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="product_id" value="<?= $pId ?>">
                                                <button type="submit" 
                                                        class="btn btn-sm p-1 px-2 <?= $isActive ? 'btn-outline-warning text-dark' : 'btn-outline-success' ?>" 
                                                        title="<?= $isActive ? 'Deactivate Product' : 'Activate Product' ?>"
                                                        data-confirm="<?= $isActive ? 'Deactivate ' . e($prod['name']) . '? It will be hidden from customer ordering.' : 'Activate ' . e($prod['name']) . '? It will be available for customers to order.' ?>"
                                                        data-confirm-title="<?= $isActive ? 'Deactivate Product' : 'Activate Product' ?>"
                                                        data-confirm-icon="bi-toggle-<?= $isActive ? 'off' : 'on' ?>"
                                                        data-confirm-btn-class="<?= $isActive ? 'btn-warning' : 'btn-success' ?>">
                                                    <i class="bi <?= $isActive ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
                                                </button>
                                            </form>
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

    <!-- Filter Alert when no matches -->
    <div id="noInventoryFilteredAlert" class="alert alert-light border text-center p-4 shadow-sm my-4 d-none">
        <i class="bi bi-search fs-3 text-muted d-block mb-2"></i>
        <strong>No products found matching the selected filter criteria.</strong>
    </div>
</div>

<!-- Modal 1: Add New Product -->
<div class="modal fade" id="addProductModal" tabindex="-1" aria-labelledby="addProductModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form action="<?= url('pages/admin/inventory.php') ?>" method="POST" id="addProductForm">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="create_product">

                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold" id="addProductModalLabel">
                        <i class="bi bi-plus-circle me-2"></i>Add New LPG Product
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="addProdName" class="form-label fw-semibold">Product Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" id="addProdName" placeholder="e.g. Solane 11kg Cylinder" required>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label for="addProdBrand" class="form-label fw-semibold">Brand <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="brand" id="addProdBrand" placeholder="e.g. Solane, Gasul, Total" required>
                        </div>
                        <div class="col-sm-6">
                            <label for="addProdWeight" class="form-label fw-semibold">Weight <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="weight" id="addProdWeight" placeholder="e.g. 11kg, 22kg, 50kg" required>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label for="addProdPrice" class="form-label fw-semibold">Unit Price (PHP) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">₱</span>
                                <input type="number" class="form-control" name="price" id="addProdPrice" step="0.01" min="1" placeholder="850.00" required>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <label for="addProdStock" class="form-label fw-semibold">Initial Stock <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="stock" id="addProdStock" min="0" placeholder="25" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="addProdImage" class="form-label fw-semibold">Image URL (Optional)</label>
                        <input type="text" class="form-control" name="image_url" id="addProdImage" placeholder="assets/img/products/solane-11kg.png">
                    </div>
                    <div class="mb-3">
                        <label for="addProdStatus" class="form-label fw-semibold">Catalog Status</label>
                        <select class="form-select" name="status" id="addProdStatus">
                            <option value="active" selected>Active (Visible to customers)</option>
                            <option value="inactive">Inactive (Hidden from shop)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4 fw-semibold">
                        <i class="bi bi-check-lg me-1"></i>Save Product
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal 2: Quick Adjust Stock & Price -->
<div class="modal fade" id="editStockPriceModal" tabindex="-1" aria-labelledby="editStockPriceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form action="<?= url('pages/admin/inventory.php') ?>" method="POST" id="editStockPriceForm">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="update_stock_price">
                <input type="hidden" name="product_id" id="stockModalProductId" value="0">

                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title fw-bold" id="editStockPriceModalLabel">
                        <i class="bi bi-sliders me-2 text-warning"></i>Adjust Stock & Price
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="p-3 bg-light rounded mb-3 border">
                        <div class="fw-bold text-dark fs-6" id="stockModalProductName">Product Name</div>
                        <small class="text-muted">ID: <span id="stockModalProductIdDisplay">#</span></small>
                    </div>

                    <div class="mb-3">
                        <label for="stockModalStock" class="form-label fw-semibold">Current Stock Level <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="stock" id="stockModalStock" min="0" required>
                        <div class="form-text small">Enter the total available inventory units for this product.</div>
                    </div>

                    <div class="mb-3">
                        <label for="stockModalPrice" class="form-label fw-semibold">Unit Price (PHP) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">₱</span>
                            <input type="number" class="form-control" name="price" id="stockModalPrice" step="0.01" min="1" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4 fw-semibold">
                        <i class="bi bi-check2 me-1"></i>Update Inventory
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal 3: Full Product Edit -->
<div class="modal fade" id="editProductModal" tabindex="-1" aria-labelledby="editProductModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form action="<?= url('pages/admin/inventory.php') ?>" method="POST" id="editProductForm">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="update_product">
                <input type="hidden" name="product_id" id="editProdId" value="0">

                <div class="modal-header bg-light border-bottom">
                    <h5 class="modal-title fw-bold" id="editProductModalLabel">
                        <i class="bi bi-pencil-square me-2 text-primary"></i>Edit Product Details
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="editProdName" class="form-label fw-semibold">Product Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" id="editProdName" required>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label for="editProdBrand" class="form-label fw-semibold">Brand <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="brand" id="editProdBrand" required>
                        </div>
                        <div class="col-sm-6">
                            <label for="editProdWeight" class="form-label fw-semibold">Weight <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="weight" id="editProdWeight" required>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label for="editProdPrice" class="form-label fw-semibold">Unit Price (PHP) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">₱</span>
                                <input type="number" class="form-control" name="price" id="editProdPrice" step="0.01" min="1" required>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <label for="editProdStock" class="form-label fw-semibold">Stock Level <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="stock" id="editProdStock" min="0" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="editProdImage" class="form-label fw-semibold">Image URL</label>
                        <input type="text" class="form-control" name="image_url" id="editProdImage">
                    </div>
                    <div class="mb-3">
                        <label for="editProdStatus" class="form-label fw-semibold">Catalog Status</label>
                        <select class="form-select" name="status" id="editProdStatus">
                            <option value="active">Active (Visible in Catalog)</option>
                            <option value="inactive">Inactive (Hidden)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4 fw-semibold">
                        <i class="bi bi-check-lg me-1"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../../templates/footer.php';
?>
