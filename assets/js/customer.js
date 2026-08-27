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
            if ($btn.prop('disabled')) return;
            const $card = $btn.closest('.app-product-card');

            const id = $btn.data('id');
            const name = $btn.data('name');
            const brand = $btn.data('brand');
            const weight = $btn.data('weight');
            const price = parseFloat($btn.data('price')) || 0;
            const stock = parseInt($btn.data('stock'), 10) || 0;

            // Reset every other product button back to default "Select" state
            $('.btn-select-product').not($btn).each(function () {
                const $b = $(this);
                if ($b.prop('disabled')) return; // keep "Sold Out" buttons untouched
                $b.removeClass('btn-primary').addClass('btn-outline-primary')
                  .html('<i class="bi bi-hand-index me-1"></i>Select');
            });

            // Mark the clicked product as the selected one
            $btn.removeClass('btn-outline-primary').addClass('btn-primary')
                .html('<i class="bi bi-check2 me-1"></i>Selected');

            // Highlight selected product card
            $('.app-product-card').removeClass('app-product-selected');
            $card.addClass('app-product-selected');

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

            // Desktop: pulse-highlight the pinned checkout card to draw attention
            if ($(window).width() >= 992) {
                $orderSection.addClass('shop-checkout-flash');
                setTimeout(function () {
                    $orderSection.removeClass('shop-checkout-flash');
                }, 1500);
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

        // Clickable order cards navigation
        $(document).on('click', '.customer-order-item.app-clickable-card', function (e) {
            if ($(e.target).closest('a, button, form, input').length) {
                return;
            }
            const href = $(this).data('href');
            if (href) {
                window.location.href = href;
            }
        });

        // Cancel order modal population (orders list page)
        $(document).on('click', '.btn-open-cancel-modal', function (e) {
            const orderId = $(this).data('order-id');

            $('#cancelModalOrderId').val(orderId);
            $('#cancelModalOrderNumber').text('#' + orderId);

            // Reset reason fields
            $('#cancelReasonSelect').val('');
            $('#cancelReasonOtherInput').val('').addClass('d-none');
            return true;
        });

        // Toggle the free-text "Other" reason input on the cancel modal
        $(document).on('change', '#cancelReasonSelect', function () {
            const showOther = ($(this).val() === 'other');
            $('#cancelReasonOtherInput').toggleClass('d-none', !showOther);
            if (!showOther) {
                $('#cancelReasonOtherInput').val('');
            }
        });

        // Cancel order from order detail page (populate cancel reason modal)
        $(document).on('click', '.btn-cancel-order-detail', function (e) {
            $('#cancelModalOrderId').val($(this).data('order-id'));
            $('#cancelModalOrderNumber').text('#' + $(this).data('order-id'));
            $('#cancelReasonSelect').val('');
            $('#cancelReasonOtherInput').val('').addClass('d-none');
            return true;
        });

        // Submit cancellation (AJAX) from the order detail page modal
        $(document).on('click', '#btnSubmitCancelOrderDetail', function (e) {
            e.preventDefault();
            const $btn = $(this);
            const $openBtn = $('.btn-cancel-order-detail');
            const orderId = $('#cancelModalOrderId').val();
            const csrfToken = $openBtn.data('csrf') || $('meta[name="csrf-token"]').attr('content') || '';

            let reason = $.trim($('#cancelReasonSelect').val() || '');
            if (reason === 'other') {
                reason = $.trim($('#cancelReasonOtherInput').val() || '');
                if (reason === '') reason = 'Other';
            }
            if (reason === '') {
                window.showToast('Please select or provide a reason for cancellation.', 'error');
                return;
            }

            $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Cancelling...');
            window.ajaxAction({
                url: window.location.href,
                data: {
                    action: 'cancel_order',
                    order_id: orderId,
                    cancel_reason: reason,
                    csrf_token: csrfToken
                },
                onSuccess: function (res) {
                    $('#cancelOrderModal').modal('hide');
                    window.showToast(res.message || 'Order #' + orderId + ' cancelled successfully.', 'success');
                    setTimeout(function () { window.location.reload(); }, 600);
                },
                onError: function () {
                    $btn.prop('disabled', false).html('<i class="bi bi-x-circle me-1"></i>Yes, Cancel Order');
                    $('#cancelOrderModal').modal('hide');
                }
            });
        });

        // Refund request reason char counter
        $(document).on('input', '#refundRequestReason', function () {
            const len = $(this).val().length;
            $('#refundReasonCount').text(len + ' / 255');
        });

        // Submit refund request for a paid online order
        $(document).on('click', '#btnSubmitRefundRequest', function (e) {
            e.preventDefault();
            const $btn = $(this);
            const reason = $.trim($('#refundRequestReason').val() || '');
            const orderId = $('.btn[data-bs-target="#refundRequestModal"]').data('order-id');
            const csrfToken = $('.btn[data-bs-target="#refundRequestModal"]').data('csrf') || $('meta[name="csrf-token"]').attr('content') || '';
            const baseUrl = $('meta[name="base-url"]').attr('content') || '';

            if (!orderId) {
                window.showToast('Could not determine the order. Please refresh and try again.', 'error');
                return;
            }
            if (reason.length === 0) {
                window.showToast('Please provide a reason for your refund request.', 'error');
                $('#refundRequestReason').focus();
                return;
            }

            $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Submitting...');
            window.ajaxAction({
                url: baseUrl + '/api/orders.php',
                data: {
                    action: 'request_refund',
                    order_id: orderId,
                    reason: reason,
                    csrf_token: csrfToken
                },
                onSuccess: function (res) {
                    $('#refundRequestModal').modal('hide');
                    window.showToast(res.message || 'Refund request submitted.', 'success');
                    setTimeout(function () { window.location.reload(); }, 800);
                },
                onError: function (msg) {
                    $btn.prop('disabled', false).html('<i class="bi bi-check-lg me-1"></i>Submit Refund Request');
                    window.showToast(msg || 'Unable to submit refund request.', 'error');
                }
            });
        });

        // Initialize Live GPS Maps & Detail Page Maps
        initLiveTrackingMaps();
        initDetailTrackingMap();
    }

    // Initialize map on order detail page
    function initDetailTrackingMap() {
        if (typeof window.L === 'undefined' || typeof window.AppMaps === 'undefined') return;

        $('.order-detail-map').each(function () {
            const $mapEl = $(this);
            const orderId = $mapEl.data('order-id');
            const address = $mapEl.data('address') || 'Manila';
            const riderName = $mapEl.data('rider-name') || 'Delivery Rider';
            const mapContainerId = $mapEl.attr('id');

            if (!mapContainerId || $('#' + mapContainerId).data('initialized')) return;
            $('#' + mapContainerId).data('initialized', true);

            const dest = AppMaps.resolveDestination($mapEl.data('lat'), $mapEl.data('lng'));
            const customerCoord = dest || AppMaps.legacyDest(orderId);
            const riderStart = dest ? [dest[0] - 0.014, dest[1] - 0.014] : AppMaps.legacyRider(orderId);

            const map = AppMaps.createMap(mapContainerId, {
                zoomControl: true,
                scrollWheelZoom: false
            }).setView(customerCoord, 14);

            L.marker(customerCoord, { icon: AppMaps.icons.customer(34) })
                .addTo(map)
                .bindPopup('<strong>📍 Delivery Address</strong><br>' + AppMaps.escapeHtml(address));

            const riderMarker = L.marker(riderStart, { icon: AppMaps.icons.rider(36, '🚴') })
                .addTo(map)
                .bindPopup('<strong>🚴 ' + AppMaps.escapeHtml(riderName) + '</strong><br>En route with LPG')
                .openPopup();

            const routeLine = L.polyline([riderStart, customerCoord], {
                color: '#0d9488',
                weight: 4,
                opacity: 0.85,
                dashArray: '8, 8'
            }).addTo(map);

            map.fitBounds(L.latLngBounds([riderStart, customerCoord]), { padding: [40, 40] });

            const $statusText = $('#detailMapStatus_' + orderId);

            AppMaps.startLiveTracking({
                map: map,
                orderId: orderId,
                customerCoord: customerCoord,
                riderStart: riderStart,
                riderMarker: riderMarker,
                routeLine: routeLine,
                riderName: riderName,
                pollMs: 4000,
                initialPollDelayMs: 500,
                simulateTickMs: 1200,
                simulateStep: 0.045,
                fitPadding: [40, 40],
                statusEl: $statusText,
                texts: {
                    arrived: function () {
                        return '<i class="bi bi-check-circle-fill me-1"></i>Rider has arrived at destination!';
                    },
                    arrivedPopup: function (name) {
                        return '<strong>✅ Rider arrived!</strong><br>' + AppMaps.escapeHtml(name) + ' is at your address.';
                    }
                },
                onRealFix: function () {
                    if ($statusText.length) {
                        $statusText.removeClass('text-primary').addClass('text-success fw-bold')
                            .html('<i class="bi bi-geo-fill me-1"></i>Rider live location updated');
                    }
                }
            });
        });
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

            // Exact saved drop-off pin; simulated coords only for legacy orders
            const dest = AppMaps.resolveDestination($mapEl.data('lat'), $mapEl.data('lng'));
            const customerCoord = dest || AppMaps.legacyDest(orderId);
            const riderStart = dest ? [dest[0] - 0.014, dest[1] - 0.014] : AppMaps.legacyRider(orderId);

            // If already picked up or out for delivery, set initial map view
            const map = AppMaps.createMap(mapContainerId, {
                zoomControl: true,
                scrollWheelZoom: false
            }).setView(customerCoord, 14);

            L.marker(customerCoord, { icon: AppMaps.icons.customer(34) })
                .addTo(map)
                .bindPopup('<strong>📍 Delivery Destination</strong><br>' + AppMaps.escapeHtml(customerAddress));

            const riderMarker = L.marker(riderStart, { icon: AppMaps.icons.rider(36, '🚴') })
                .addTo(map)
                .bindPopup('<strong>🚴 ' + AppMaps.escapeHtml(riderName) + '</strong><br>En route with your LPG cylinder')
                .openPopup();

            // Route Polyline
            const routeLine = L.polyline([riderStart, customerCoord], {
                color: '#0d9488',
                weight: 4,
                opacity: 0.85,
                dashArray: '8, 8',
                lineCap: 'round'
            }).addTo(map);

            // Fit bounds nicely
            map.fitBounds(L.latLngBounds([riderStart, customerCoord]), { padding: [40, 40] });

            activeMapInstances[orderId] = map;

            AppMaps.startLiveTracking({
                map: map,
                orderId: orderId,
                customerCoord: customerCoord,
                riderStart: riderStart,
                riderMarker: riderMarker,
                routeLine: routeLine,
                riderName: riderName,
                pollMs: 5000,
                initialPollDelayMs: 800,
                simulateTickMs: 1200,
                simulateStep: 0.045,
                fitPadding: [40, 40],
                statusEl: $statusText
            });
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

        // Dedicated Modal Live Tracking Map Handler
        let modalMapInstance = null;
        let modalTrackingHandle = null;

        $(document).on('click', '.btn-track-live-map', function (e) {
            e.preventDefault();
            const $btn = $(this);
            const orderId = $btn.data('order-id');
            const riderName = $btn.data('rider-name') || 'Pedro Reyes (Rider)';
            const customerAddress = $btn.data('customer-address') || 'Delivery Address';
            const status = ($btn.data('status') || '').toLowerCase();
            const statusLabel = $btn.data('status-label') || 'In Transit';

            const btnLat = $btn.data('lat');
            const btnLng = $btn.data('lng');

            $('#modalOrderDisplayId').text('#' + orderId);
            $('#modalRiderName').text('Assigned Rider: ' + riderName);
            $('#modalDeliveryAddress').text('Destination: ' + customerAddress);
            $('#modalStatusBadge').text(statusLabel);
            $('#modalEtaText').text('🚴 ' + riderName + ' is en route...');

            const modalEl = document.getElementById('liveTrackingMapModal');
            if (modalEl && window.bootstrap && window.bootstrap.Modal) {
                const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                modal.show();
            }

            // Also open inline collapsible if present
            const targetCollapse = $btn.data('target');
            if (targetCollapse && $(targetCollapse).length) {
                $(targetCollapse).collapse('show');
            }

            // Setup modal map once modal is shown
            $('#liveTrackingMapModal').one('shown.bs.modal', function () {
                if (typeof window.L === 'undefined' || typeof window.AppMaps === 'undefined') return;

                if (modalTrackingHandle) {
                    modalTrackingHandle.stop();
                    modalTrackingHandle = null;
                }
                if (modalMapInstance) {
                    modalMapInstance.remove();
                    modalMapInstance = null;
                }

                const dest = AppMaps.resolveDestination(btnLat, btnLng);
                const customerCoord = dest || AppMaps.legacyDest(orderId);
                const riderStart = dest ? [dest[0] - 0.014, dest[1] - 0.014] : AppMaps.legacyRider(orderId);

                modalMapInstance = AppMaps.createMap('modal-live-map', {
                    zoomControl: true
                }).setView(customerCoord, 14);

                L.marker(customerCoord, { icon: AppMaps.icons.customer(36) })
                    .addTo(modalMapInstance)
                    .bindPopup('<strong>📍 Delivery Destination</strong><br>' + AppMaps.escapeHtml(customerAddress));

                const riderMarker = L.marker(riderStart, { icon: AppMaps.icons.rider(40, '🚴') })
                    .addTo(modalMapInstance)
                    .bindPopup('<strong>🚴 ' + AppMaps.escapeHtml(riderName) + '</strong><br>LPG Cylinder En Route')
                    .openPopup();

                const routeLine = L.polyline([riderStart, customerCoord], {
                    color: '#0d9488',
                    weight: 5,
                    opacity: 0.85,
                    dashArray: '8, 8',
                    lineCap: 'round'
                }).addTo(modalMapInstance);

                modalMapInstance.fitBounds(L.latLngBounds([riderStart, customerCoord]), { padding: [50, 50] });
                modalMapInstance.invalidateSize();

                modalTrackingHandle = AppMaps.startLiveTracking({
                    map: modalMapInstance,
                    orderId: orderId,
                    customerCoord: customerCoord,
                    riderStart: riderStart,
                    riderMarker: riderMarker,
                    routeLine: routeLine,
                    riderName: riderName,
                    pollMs: 4000,
                    initialPollDelayMs: 300,
                    simulateTickMs: 1000,
                    simulateStep: 0.05,
                    fitPadding: [50, 50],
                    texts: {
                        enroute: function (name, pct) {
                            return '<i class="bi bi-bicycle me-1"></i>' + AppMaps.escapeHtml(name) + ' is moving closer (' + pct + '% arrived)';
                        },
                        arrivedPopup: function (name) {
                            return '<strong>✅ Rider has arrived!</strong><br>' + AppMaps.escapeHtml(name) + ' is at your doorstep.';
                        }
                    },
                    onTick: function (html) {
                        $('#modalEtaText').html(html);
                    },
                    onArrive: function () {
                        $('#modalEtaText').removeClass('text-primary').addClass('text-success fw-bold').html('<i class="bi bi-check-circle-fill me-1"></i>Rider has arrived at your location!');
                        $('#modalStatusBadge').removeClass('bg-primary').addClass('bg-success').text('Arrived at Destination');
                    }
                });
            });

            $('#liveTrackingMapModal').on('hidden.bs.modal', function () {
                if (modalTrackingHandle) {
                    modalTrackingHandle.stop();
                    modalTrackingHandle = null;
                }
            });
        });
    }

    // =========================================================================
    // 3. Customer Profile Interactivity
    // =========================================================================
    function initAvatarUploadInteractivity() {
        const $avatarForm = $('#customerAvatarForm');
        if ($avatarForm.length === 0) return;

        // Clicking "Upload Photo" opens the file browser directly
        $('#customerAvatarBtn').on('click', function () {
            $('#profile_picture').trigger('click');
        });

        // Auto-submit the form once the user picks an image
        $('#profile_picture').on('change', function () {
            if (this.files && this.files.length > 0) {
                $avatarForm[0].submit();
            }
        });
    }

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
    // Geocoding Helper & Pin Picker moved to shared-maps.js (AppMaps)
    // =========================================================================

    // =========================================================================
    // Checkout: Exact Location Pin Picker (auto-geocode + draggable pin)
    // =========================================================================
    function initCheckoutAddressPinPicker() {
        const $mapEl = $('#addressPreviewMap');
        const $addressInput = $('#delivery_address');
        const $latInput = $('#delivery_latitude');
        const $lngInput = $('#delivery_longitude');
        const $status = $('#addressPinStatus');

        if ($mapEl.length === 0 || $addressInput.length === 0 || typeof window.L === 'undefined') {
            return;
        }
        if ($mapEl.data('pin-picker-initialized')) return;
        $mapEl.data('pin-picker-initialized', true);

        const fallbackCoord = [14.5995, 120.9842];
        let debounceTimer = null;
        let lookupSeq = 0;

        const map = L.map('addressPreviewMap', {
            zoomControl: true,
            scrollWheelZoom: false
        }).setView(fallbackCoord, 13);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
        }).addTo(map);

        const pinIcon = L.divIcon({
            className: 'pin-marker-wrapper',
            html: '<div style="width:32px;height:32px;background:#ef4444;color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid #fff;box-shadow:0 3px 8px rgba(0,0,0,0.35);font-size:15px;">📍</div>',
            iconSize: [32, 32],
            iconAnchor: [16, 32],
            popupAnchor: [0, -28]
        });

        let marker = null;

        function setPin(lat, lng, opts) {
            opts = opts || {};
            $latInput.val(lat);
            $lngInput.val(lng);
            if (marker) {
                marker.setLatLng([lat, lng]);
            } else {
                marker = L.marker([lat, lng], { icon: pinIcon, draggable: true }).addTo(map);
                bindMarkerEvents();
            }
            if (!opts.keepView) {
                map.setView([lat, lng], Math.max(map.getZoom(), 16));
            }
        }

        function setStatus(html, tone) {
            const tones = {
                success: 'text-success fw-semibold',
                warn: 'text-warning fw-semibold',
                muted: 'text-muted'
            };
            $status.removeClass().addClass('extra-small ' + (tones[tone] || tones.muted)).html(html);
        }

        function bindMarkerEvents() {
            if (!marker) return;
            marker.on('dragend', function () {
                const pos = marker.getLatLng();
                $latInput.val(pos.lat.toFixed(7));
                $lngInput.val(pos.lng.toFixed(7));
                setStatus('<i class="bi bi-hand-index-thumb me-1"></i>Pin placed at your exact location', 'success');
            });
        }

        function lookupAddress() {
            const address = $.trim($addressInput.val());
            const seq = ++lookupSeq;

            if (address.length < 6) {
                setStatus('<i class="bi bi-hourglass-split me-1"></i>Type your complete address to auto-detect the pin...', 'muted');
                return;
            }

            setStatus('<i class="bi bi-arrow-repeat me-1"></i>Detecting your location on the map...', 'muted');

            AppMaps.geocode(address).then(function (result) {
                if (seq !== lookupSeq) return;
                if (result) {
                    setPin(result.lat, result.lng);
                    setStatus('<i class="bi bi-geo-alt-fill me-1"></i>Pin location confirmed &mdash; drag to fine-tune if needed', 'success');
                } else {
                    setStatus('<i class="bi bi-exclamation-triangle me-1"></i>Address not found &mdash; click or drag the pin to mark your exact spot', 'warn');
                    if (!marker) {
                        setPin(fallbackCoord[0], fallbackCoord[1], { keepView: true });
                    }
                }
            });
        }

        // Debounced auto-geocode while typing
        $addressInput.on('input change blur', function () {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(lookupAddress, 800);
        });

        // Click-to-move pin for manual placement
        map.on('click', function (e) {
            setPin(e.latlng.lat, e.latlng.lng, { keepView: true });
            setStatus('<i class="bi bi-hand-index-thumb me-1"></i>Pin placed at your exact location', 'success');
        });

        // Restore previously submitted coordinates (validation error re-render)
        const initialLat = parseFloat($latInput.val());
        const initialLng = parseFloat($lngInput.val());
        if (!isNaN(initialLat) && !isNaN(initialLng)) {
            setPin(initialLat, initialLng);
            setStatus('<i class="bi bi-geo-alt-fill me-1"></i>Pin restored &mdash; drag to fine-tune if needed', 'success');
        } else if ($.trim($addressInput.val()).length >= 6) {
            lookupAddress();
        }

        setTimeout(function () { map.invalidateSize(); }, 300);
    }

    // =========================================================================
    // DOM Ready Initialization
    // =========================================================================
    $(function () {
        [initShopInteractivity, initOrdersInteractivity, initProfileInteractivity,
         initAvatarUploadInteractivity,
         function () { if (window.AppMaps) AppMaps.initPinLocationMaps(); },
         function () { if (window.AppChat) AppChat.initPanel({ emptyStateHint: 'Start the conversation with your rider' }); },
         initCheckoutAddressPinPicker
        ].forEach(function (init) {
            try { init(); } catch (err) { console.error('Init failed:', err); }
        });
    });

})(window, window.jQuery);
