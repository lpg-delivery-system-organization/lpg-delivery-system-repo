<?php
/**
 * Order Card Component
 * LPG Delivery System v2
 *
 * Reusable partial and helper for rendering order details across Customer, Admin, and Rider portals.
 */

if (!function_exists('get_order_status_badge')) {
    /**
     * Get HTML badge for order status
     *
     * @param string $status
     * @return string
     */
    function get_order_status_badge(string $status): string {
        $status = strtolower($status);
        $labels = [
            'pending'            => ['label' => 'Pending',            'class' => 'badge-status-pending'],
            'approved'           => ['label' => 'Approved',           'class' => 'badge-status-approved'],
            'ready_for_delivery' => ['label' => 'Ready for Delivery', 'class' => 'badge-status-ready_for_delivery'],
            'picked_up'          => ['label' => 'Picked Up',          'class' => 'badge-status-picked_up'],
            'out_for_delivery'   => ['label' => 'Out for Delivery',   'class' => 'badge-status-out_for_delivery'],
            'delivered'          => ['label' => 'Delivered',          'class' => 'badge-status-delivered'],
            'cancelled'          => ['label' => 'Cancelled',          'class' => 'badge-status-cancelled']
        ];

        $info = $labels[$status] ?? ['label' => ucfirst(str_replace('_', ' ', $status)), 'class' => 'bg-secondary text-white'];
        return '<span class="badge ' . e($info['class']) . ' app-status-badge">' . e($info['label']) . '</span>';
    }
}

if (!function_exists('render_order_card')) {
    /**
     * Render an order card HTML component
     *
     * @param array $order Order record data
     * @param array $options Optional configuration (e.g. 'show_actions' => true, 'role' => 'customer')
     * @return string
     */
    function render_order_card(array $order, array $options = []): string {
        $orderId = (int)($order['id'] ?? 0);
        $status = (string)($order['status'] ?? 'pending');
        $productName = (string)($order['product_name'] ?? $order['name'] ?? 'LPG Cylinder');
        $brand = (string)($order['brand'] ?? '');
        $weight = (string)($order['weight'] ?? '');
        $qty = (int)($order['quantity'] ?? 1);
        $unitPrice = (float)($order['unit_price'] ?? 0);
        $totalAmount = (float)($order['total_amount'] ?? ($qty * $unitPrice));
        $paymentMethod = strtoupper((string)($order['payment_method'] ?? 'COD'));
        $deliveryAddress = (string)($order['delivery_address'] ?? $order['address'] ?? '');
        $contactPhone = (string)($order['contact_phone'] ?? $order['phone'] ?? '');
        $notes = (string)($order['notes'] ?? '');
        $createdAt = (string)($order['created_at'] ?? '');
        $deliveredAt = (string)($order['delivered_at'] ?? '');
        $customerName = (string)($order['customer_name'] ?? '');
        $riderName = (string)($order['rider_name'] ?? '');
        $role = $options['role'] ?? (function_exists('current_user_role') ? current_user_role() : 'customer');
        $customActions = $options['actions_html'] ?? '';

        ob_start();
        ?>
        <div class="card app-order-card shadow-sm border-0 mb-3" data-order-id="<?= e((string)$orderId) ?>">
            <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center py-3 border-bottom">
                <div class="d-flex align-items-center gap-2">
                    <span class="fw-bold fs-5 text-primary">#<?= e((string)$orderId) ?></span>
                    <small class="text-muted"><i class="bi bi-clock me-1"></i><?= e(format_date($createdAt)) ?></small>
                </div>
                <div>
                    <?= get_order_status_badge($status) ?>
                </div>
            </div>
            <div class="card-body p-3 p-md-4">
                <div class="row g-3">
                    <div class="col-md-6">
                        <h6 class="text-muted text-uppercase fw-semibold small mb-2">Item Details</h6>
                        <div class="d-flex align-items-start gap-3">
                            <div class="app-product-icon p-2 bg-light rounded text-center">
                                <i class="bi bi-fire fs-3 text-warning"></i>
                            </div>
                            <div>
                                <h6 class="mb-1 fw-bold"><?= e($productName) ?></h6>
                                <p class="text-muted small mb-1">
                                    <?php if ($brand): ?><span class="badge bg-light text-dark border me-1"><?= e($brand) ?></span><?php endif; ?>
                                    <?php if ($weight): ?><span class="badge bg-light text-dark border me-1"><?= e($weight) ?></span><?php endif; ?>
                                </p>
                                <div class="small">
                                    <span class="text-muted">Quantity:</span> <strong class="text-dark"><?= e((string)$qty) ?></strong>
                                    <span class="mx-2 text-muted">&bull;</span>
                                    <span class="text-muted">Unit Price:</span> <strong><?= e(format_currency($unitPrice)) ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-muted text-uppercase fw-semibold small mb-2">Delivery & Payment</h6>
                        <div class="small">
                            <?php if ($customerName && $role !== 'customer'): ?>
                                <p class="mb-1"><i class="bi bi-person text-muted me-2"></i><strong>Customer:</strong> <?= e($customerName) ?></p>
                            <?php endif; ?>
                            <p class="mb-1 text-truncate"><i class="bi bi-geo-alt text-muted me-2"></i><?= e($deliveryAddress ?: 'No address specified') ?></p>
                            <p class="mb-1"><i class="bi bi-telephone text-muted me-2"></i><?= e($contactPhone ?: 'No contact number') ?></p>
                            <p class="mb-1">
                                <i class="bi bi-credit-card text-muted me-2"></i><strong>Payment:</strong>
                                <span class="badge <?= $paymentMethod === 'GCASH' ? 'bg-primary' : 'bg-success' ?> ms-1"><?= e($paymentMethod) ?></span>
                            </p>
                            <?php if ($riderName): ?>
                                <p class="mb-1"><i class="bi bi-truck text-muted me-2"></i><strong>Rider:</strong> <?= e($riderName) ?></p>
                            <?php endif; ?>
                            <?php if ($deliveredAt): ?>
                                <p class="mb-1 text-success"><i class="bi bi-check-circle text-success me-2"></i>Delivered on <?= e(format_date($deliveredAt)) ?></p>
                            <?php endif; ?>
                            <?php if ($notes): ?>
                                <p class="mb-1 text-muted"><i class="bi bi-chat-left-text me-2"></i><em><?= e($notes) ?></em></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-light d-flex flex-wrap justify-content-between align-items-center py-3 border-top">
                <div class="d-flex align-items-baseline gap-2">
                    <span class="text-muted small">Total:</span>
                    <span class="fs-5 fw-bold text-dark"><?= e(format_currency($totalAmount)) ?></span>
                </div>
                <div class="app-order-card-actions d-flex gap-2">
                    <?= $customActions ?>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

// Support direct inclusion with $order variable
if (isset($order) && is_array($order)) {
    echo render_order_card($order, $options ?? []);
}
