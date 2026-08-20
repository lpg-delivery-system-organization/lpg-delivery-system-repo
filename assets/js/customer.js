/**
 * LPG Delivery System v2 - Customer Portal Client Scripts
 *
 * Provides:
 * 1. Product card selection & interactive checkout synchronization
 * 2. Quantity counter (+/-) with live price and total computation
 * 3. Payment method interactive radio selection
 * 4. Order history status filter tabs
 * 5. Reorder URL parameter auto-selection
 * 6. Order cancellation modal confirmation handler
 * 7. Profile & order form client-side validations
 */

(function (window, $) {
    'use strict';

    // Helper: format number to Philippine Peso string (e.g. ₱1,900.00)
    function formatCurrency(amount) {
        const num = parseFloat(amount) || 0;
        return '₱' + num.toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    // =========================================================================
    // 1. Shop Page: Product Selection & Live Total Calculation
    // =========================================================================
    function initShopInteractivity() {
        const $shopContainer = $('#customerShopContainer');
        if ($shopContainer.length === 0) return;

        const $orderSection = $('#orderCheckoutSection');
        const $productIdInput = $('#order_product_id');
        const $qtyInput = $('#order_quantity');
        const $unitPriceDisplay = $('#orderUnitPriceDisplay');
        const $subtotalDisplay = $('#orderSubtotalDisplay');
        const $totalDisplay = $('#orderTotalDisplay');
        const $productNameDisplay = $('#orderProductNameDisplay');
        const $productMetaDisplay = $('#orderProductMetaDisplay');
        const $stockStatusDisplay = $('#orderStockStatusDisplay');
        const $submitBtn = $('#btnPlaceOrder');

        let currentPrice = 0;
        let maxStock = 0;

        function updateTotals() {
            let qty = parseInt($qtyInput.val(), 10) || 1;
            if (qty < 1) qty = 1;
            if (maxStock > 0 && qty > maxStock) qty = maxStock;
            $qtyInput.val(qty);

            const total = qty * currentPrice;
            $unitPriceDisplay.text(formatCurrency(currentPrice));
            $subtotalDisplay.text(formatCurrency(total));
            $totalDisplay.text(formatCurrency(total));

            // Stock validation
            if (maxStock <= 0) {
                $submitBtn.prop('disabled', true).html('<i class="bi bi-x-circle me-1"></i>Out of Stock');
                $stockStatusDisplay.html('<span class="text-danger fw-semibold"><i class="bi bi-exclamation-circle me-1"></i>Currently Out of Stock</span>');
            } else if (qty > maxStock) {
                $submitBtn.prop('disabled', true).html('<i class="bi bi-exclamation-triangle me-1"></i>Exceeds Available Stock');
            } else {
                $submitBtn.prop('disabled', false).html('<i class="bi bi-bag-check me-2"></i>Place Order (' + formatCurrency(total) + ')');
                $stockStatusDisplay.html('<span class="text-success fw-semibold"><i class="bi bi-check-circle me-1"></i>In Stock (' + maxStock + ' available)</span>');
            }
        }

        // Select product handler
        $(document).on('click', '.btn-select-product', function (e) {
            e.preventDefault();
            const $btn = $(this);
            const $card = $btn.closest('.app-product-card');

            const id = $btn.data('id');
            const name = $btn.data('name');
            const brand = $btn.data('brand');
            const weight = $btn.data('weight');
            const price = parseFloat($btn.data('price')) || 0;
            const stock = parseInt($btn.data('stock'), 10) || 0;

            // Highlight selected product card
            $('.app-product-card').removeClass('border-primary shadow-sm ring-2 ring-primary bg-light-subtle');
            $card.addClass('border-primary shadow-sm bg-light-subtle');

            // Update state
            $productIdInput.val(id);
            currentPrice = price;
            maxStock = stock;
            $qtyInput.attr('max', maxStock > 0 ? maxStock : 1);

            // Update UI info
            $productNameDisplay.text(name);
            $productMetaDisplay.html(
                (brand ? '<span class="badge bg-secondary me-1">' + brand + '</span>' : '') +
                (weight ? '<span class="badge bg-info text-dark">' + weight + '</span>' : '')
            );

            updateTotals();

            // Show checkout section if hidden
            $orderSection.removeClass('d-none');

            // Scroll to order section smoothly on mobile
            if ($(window).width() < 992) {
                $('html, body').animate({
                    scrollTop: $orderSection.offset().top - 80
                }, 400);
            }
        });

        // Quantity Plus Button
        $('#btnQtyPlus').on('click', function () {
            let qty = parseInt($qtyInput.val(), 10) || 1;
            if (maxStock > 0 && qty < maxStock) {
                $qtyInput.val(qty + 1);
                updateTotals();
            } else if (maxStock <= 0) {
                if (window.showToast) window.showToast('This product is out of stock.', 'warning');
            } else {
                if (window.showToast) window.showToast('Maximum available stock is ' + maxStock + ' units.', 'warning');
            }
        });

        // Quantity Minus Button
        $('#btnQtyMinus').on('click', function () {
            let qty = parseInt($qtyInput.val(), 10) || 1;
            if (qty > 1) {
                $qtyInput.val(qty - 1);
                updateTotals();
            }
        });

        // Quantity manual input change
        $qtyInput.on('input change', function () {
            updateTotals();
        });

        // Payment Method radio card visual toggle
        $('input[name="payment_method"]').on('change', function () {
            $('.payment-method-card').removeClass('border-primary bg-primary-subtle');
            $(this).closest('.payment-method-card').addClass('border-primary bg-primary-subtle');
        });

        // Handle URL parameters for reordering (e.g. ?reorder_product_id=2&qty=2)
        const urlParams = new URLSearchParams(window.location.search);
        const reorderId = urlParams.get('reorder_product_id');
        const reorderQty = parseInt(urlParams.get('qty'), 10) || 1;

        if (reorderId) {
            const $targetBtn = $('.btn-select-product[data-id="' + reorderId + '"]');
            if ($targetBtn.length > 0) {
                $targetBtn.trigger('click');
                if (reorderQty > 1 && reorderQty <= maxStock) {
                    $qtyInput.val(reorderQty);
                    updateTotals();
                }
            }
        } else {
            // Auto-select first available product if none selected
            const $firstBtn = $('.btn-select-product').first();
            if ($firstBtn.length > 0) {
                $firstBtn.trigger('click');
            }
        }
    }

    // =========================================================================
    // 2. Orders Page: Filter Tabs & Cancellation Modal
    // =========================================================================
    function initOrdersInteractivity() {
        const $ordersContainer = $('#customerOrdersContainer');
        if ($ordersContainer.length === 0) return;

        // Filter tabs
        $('.order-filter-btn').on('click', function (e) {
            e.preventDefault();
            const $btn = $(this);
            const statusFilter = $btn.data('filter');

            $('.order-filter-btn').removeClass('active btn-primary text-white').addClass('btn-outline-secondary');
            $btn.addClass('active btn-primary text-white').removeClass('btn-outline-secondary');

            const $cards = $('.customer-order-item');
            let visibleCount = 0;

            $cards.each(function () {
                const $item = $(this);
                const itemStatus = ($item.data('status') || '').toLowerCase();

                if (statusFilter === 'all') {
                    $item.show();
                    visibleCount++;
                } else if (statusFilter === 'in_transit') {
                    if (['approved', 'ready_for_delivery', 'picked_up', 'out_for_delivery'].indexOf(itemStatus) !== -1) {
                        $item.show();
                        visibleCount++;
                    } else {
                        $item.hide();
                    }
                } else if (itemStatus === statusFilter) {
                    $item.show();
                    visibleCount++;
                } else {
                    $item.hide();
                }
            });

            if (visibleCount === 0) {
                $('#noFilteredOrdersAlert').removeClass('d-none');
            } else {
                $('#noFilteredOrdersAlert').addClass('d-none');
            }
        });

        // Cancel order modal population
        $(document).on('click', '.btn-open-cancel-modal', function (e) {
            e.preventDefault();
            const orderId = $(this).data('order-id');
            $('#cancelModalOrderId').val(orderId);
            $('#cancelModalOrderNumber').text('#' + orderId);

            const modalEl = document.getElementById('cancelOrderModal');
            if (modalEl && window.bootstrap && window.bootstrap.Modal) {
                const modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
                modal.show();
            }
        });
    }

    // =========================================================================
    // 3. Profile Page: Password Checklist & Phone Input Formatter
    // =========================================================================
    function initProfileInteractivity() {
        const $profileForm = $('#customerProfileForm');
        if ($profileForm.length === 0) return;

        // Philippine mobile number formatter / validation feedback
        $('#phone').on('input', function () {
            const val = $(this).val().replace(/\D/g, '');
            $(this).val(val);
            const isValid = /^09\d{9}$/.test(val);
            if (val.length === 11 && isValid) {
                $(this).removeClass('is-invalid').addClass('is-valid');
            } else if (val.length > 0) {
                $(this).removeClass('is-valid').addClass('is-invalid');
            } else {
                $(this).removeClass('is-valid is-invalid');
            }
        });

        // Password change checklist
        const $newPassword = $('#new_password');
        const $confirmPassword = $('#confirm_new_password');

        if ($newPassword.length > 0) {
            function validatePasswordRules() {
                const pass = $newPassword.val() || '';
                const confirm = $confirmPassword.val() || '';

                const rules = {
                    length: pass.length >= 8,
                    upper: /[A-Z]/.test(pass),
                    lower: /[a-z]/.test(pass),
                    number: /[0-9]/.test(pass),
                    special: /[\W_]/.test(pass),
                    match: pass.length > 0 && pass === confirm
                };

                for (const key in rules) {
                    const $item = $('#rule-' + key);
                    const $icon = $item.find('i');
                    if (rules[key]) {
                        $item.removeClass('text-muted text-danger').addClass('text-success fw-medium');
                        $icon.removeClass('bi-circle bi-x-circle text-muted text-danger').addClass('bi-check-circle-fill text-success');
                    } else {
                        $item.removeClass('text-success fw-medium').addClass('text-muted');
                        $icon.removeClass('bi-check-circle-fill text-success bi-x-circle text-danger').addClass('bi-circle text-muted');
                    }
                }
            }

            $newPassword.on('input focus', validatePasswordRules);
            $confirmPassword.on('input focus', validatePasswordRules);
        }
    }

    // =========================================================================
    // DOM Ready Initialization
    // =========================================================================
    $(function () {
        initShopInteractivity();
        initOrdersInteractivity();
        initProfileInteractivity();
    });

})(window, window.jQuery);
