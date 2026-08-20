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
            $('#cancel_order_id').val(orderId);
            $('#cancelModalOrderNumber').text('#' + orderId);
            $('#cancelOrderModalDisplayId').text('#' + orderId);

            const modalEl = document.getElementById('cancelOrderModal');
            if (modalEl && window.bootstrap && window.bootstrap.Modal) {
                const cancelModal = bootstrap.Modal.getOrCreateInstance(modalEl);
                cancelModal.show();
            }
        });

        // Initialize Live GPS Maps
        initLiveTrackingMaps();
    }

    // =========================================================================
    // 2.1 Live GPS Map Tracking (Leaflet Integration)
    // =========================================================================
    const activeMapInstances = {};
    const activeMapIntervals = {};

    function initLiveTrackingMaps() {
        if (typeof window.L === 'undefined') {
            return;
        }

        $('.order-live-map').each(function () {
            const $mapEl = $(this);
            const orderId = parseInt($mapEl.data('map-order-id'), 10);
            if (!orderId || activeMapInstances[orderId]) {
                return;
            }

            const mapContainerId = 'map-' + orderId;
            const $statusText = $('#map-status-text-' + orderId);
            const riderName = $mapEl.data('rider-name') || 'Delivery Rider';
            const customerAddress = $mapEl.data('customer-address') || 'Delivery Address';
            const orderStatus = ($mapEl.data('status') || '').toLowerCase();

            // Pseudo-random deterministic coords based on orderId around Manila / QC
            const offset = (orderId % 10) * 0.004;
            const customerCoord = [14.6091 + offset, 120.9822 + offset];
            let riderCoord = [14.5950 + offset, 120.9680 + offset];

            // If already picked up or out for delivery, set initial map view
            const map = L.map(mapContainerId, {
                zoomControl: true,
                scrollWheelZoom: false
            }).setView(customerCoord, 14);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(map);

            // Custom Customer Destination Marker
            const customerIcon = L.divIcon({
                className: 'customer-marker-wrapper',
                html: '<div class="customer-marker-icon" style="width:34px;height:34px;background:#ef4444;color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;border:2px solid #fff;box-shadow:0 3px 6px rgba(0,0,0,0.3);font-size:16px;">📍</div>',
                iconSize: [34, 34],
                iconAnchor: [17, 34],
                popupAnchor: [0, -30]
            });

            const customerMarker = L.marker(customerCoord, { icon: customerIcon })
                .addTo(map)
                .bindPopup('<strong>📍 Delivery Destination</strong><br>' + $('<div>').text(customerAddress).html());

            // Custom Rider Marker
            const riderIcon = L.divIcon({
                className: 'rider-marker-wrapper',
                html: '<div class="rider-marker-icon" style="width:36px;height:36px;background:#2563eb;color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;border:2px solid #fff;box-shadow:0 3px 6px rgba(0,0,0,0.3);font-size:18px;">🚴</div>',
                iconSize: [36, 36],
                iconAnchor: [18, 18],
                popupAnchor: [0, -20]
            });

            const riderMarker = L.marker(riderCoord, { icon: riderIcon })
                .addTo(map)
                .bindPopup('<strong>🚴 ' + $('<div>').text(riderName).html() + '</strong><br>En route with your LPG cylinder')
                .openPopup();

            // Route Polyline
            const routeLine = L.polyline([riderCoord, customerCoord], {
                color: '#2563eb',
                weight: 4,
                opacity: 0.85,
                dashArray: '8, 8',
                lineCap: 'round'
            }).addTo(map);

            // Fit bounds nicely
            const bounds = L.latLngBounds([riderCoord, customerCoord]);
            map.fitBounds(bounds, { padding: [40, 40] });

            activeMapInstances[orderId] = map;

            // Live Animated Rider Movement Simulation
            function stepRiderMovement() {
                const latDiff = customerCoord[0] - riderCoord[0];
                const lngDiff = customerCoord[1] - riderCoord[1];
                const distanceRemaining = Math.sqrt(latDiff * latDiff + lngDiff * lngDiff);

                if (distanceRemaining < 0.0006) {
                    if (activeMapIntervals[orderId]) {
                        clearInterval(activeMapIntervals[orderId]);
                    }
                    riderMarker.setLatLng(customerCoord);
                    routeLine.setLatLngs([customerCoord, customerCoord]);
                    riderMarker.bindPopup('<strong>✅ Rider has arrived!</strong><br>' + $('<div>').text(riderName).html() + ' is at your doorstep.').openPopup();
                    if ($statusText.length) {
                        $statusText.removeClass('text-primary').addClass('text-success fw-bold').html('<i class="bi bi-check-circle-fill me-1"></i>Rider has arrived at your location!');
                    }
                    return;
                }

                // Advance rider 4.5% of remaining distance per tick
                riderCoord[0] += latDiff * 0.045;
                riderCoord[1] += lngDiff * 0.045;

                riderMarker.setLatLng(riderCoord);
                routeLine.setLatLngs([riderCoord, customerCoord]);

                if ($statusText.length) {
                    const progressPercent = Math.min(95, Math.round((1 - (distanceRemaining / 0.022)) * 100));
                    $statusText.html('<i class="bi bi-bicycle me-1"></i>' + $('<div>').text(riderName).html() + ' is on the way (' + Math.max(5, progressPercent) + '% arrived)');
                }
            }

            activeMapIntervals[orderId] = setInterval(stepRiderMovement, 1200);
        });

        // Toggle map view collapse button
        $(document).on('click', '.btn-toggle-order-map', function (e) {
            e.preventDefault();
            const targetSelector = $(this).data('target');
            const $target = $(targetSelector);
            const $icon = $(this).find('i');

            if ($target.hasClass('show')) {
                $target.collapse('hide');
                $icon.removeClass('bi-chevron-up').addClass('bi-chevron-down');
            } else {
                $target.collapse('show');
                $icon.removeClass('bi-chevron-down').addClass('bi-chevron-up');
                
                // Invalidate leaflet size after animation
                setTimeout(function () {
                    for (const id in activeMapInstances) {
                        if (activeMapInstances[id]) {
                            activeMapInstances[id].invalidateSize();
                        }
                    }
                }, 350);
            }
        });
    }

    // =========================================================================
    // 3. Customer Profile Interactivity
    // =========================================================================
    function initProfileInteractivity() {
        const $profileForm = $('#customerProfileForm');
        if ($profileForm.length === 0) return;

        // Philippine mobile number formatter / validation feedback
        $('#phone').on('input', function () {
            let val = $(this).val().replace(/\D/g, '');
            if (val.length > 11) val = val.substring(0, 11);
            $(this).val(val);

            if (val.length === 11 && val.startsWith('09')) {
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
