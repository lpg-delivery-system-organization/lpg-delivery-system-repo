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

    // Single source of truth for the "Place Order" button state.
    //
    // The button is disabled when EITHER the cart rules block it (empty cart or
    // a line exceeding available stock) OR the selected delivery location is
    // outside the configured coverage zone. Both the shop-side cart logic and
    // the map-side coverage logic funnel through here so neither can
    // accidentally re-enable the other.
    function applyPlaceOrderState() {
        const $btn = $('#btnPlaceOrder');
        if ($btn.length === 0) return;

        // Cart first (empty cart / an item exceeds available stock).
        if ($btn.data('cart-blocked')) {
            $btn.prop('disabled', true).html($btn.data('cart-label') || 'Cart is Empty');
            return;
        }

        // Then delivery coverage. When enforcement is off (no geometry/config
        // available) the location never blocks checkout.
        const $form = $('#orderPlacementForm');
        const enforced = $form.data('coverage-enforced') !== false;
        const coverageOk = $form.data('coverage-ok');

        if (enforced && coverageOk !== true) {
            $btn.prop('disabled', true).html('<i class="bi bi-geo-alt-fill me-1"></i>Outside Delivery Area');
            return;
        }

        const totalText = $.trim($('#orderTotalDisplay').text()) || formatCurrency(0);
        $btn.prop('disabled', false).html('<i class="bi bi-bag-check me-2"></i>Place Order (' + totalText + ')');
    }

    // =========================================================================
    // 1. Shop Page: Cart (add / update / remove / drawer & checkout sync)
    // =========================================================================
    function initShopCart() {
        const $shopContainer = $('#customerShopContainer');
        if ($shopContainer.length === 0) return;

        const endpoint = window.LPG_CART_ENDPOINT || 'api/cart.php';
        const $orderSection = $('#orderCheckoutSection');
        const $submitBtn = $('#btnPlaceOrder');

        function getCart() {
            return window.LPG_CART || { items: [], count: 0, units: 0, subtotal: 0 };
        }

        function esc(t) {
            return $('<div>').text(t == null ? '' : t).html();
        }

        // --- Rendering ---
        function renderBadge() {
            const units = getCart().units || 0;
            const $b = $('#cartBadge');
            $b.text(units);
            $b.toggleClass('d-none', units <= 0);
            $('#cartDrawerCount').text(units);
        }

        function drawerRow(item) {
            const max = Math.max(1, Math.min(10, item.stock));
            const over = !item.within_stock;
            return '' +
                '<div class="cart-drawer-item' + (over ? ' cart-item-invalid' : '') + '">' +
                  '<div class="flex-grow-1">' +
                    '<div class="fw-semibold small text-dark">' + esc(item.name) + '</div>' +
                    '<div class="extra-small text-muted">' + esc(item.brand) + ' &middot; ' + esc(item.weight) + '</div>' +
                    '<div class="extra-small text-primary fw-semibold mt-1">' + formatCurrency(item.price) + ' each</div>' +
                    (over ? '<div class="extra-small text-danger"><i class="bi bi-exclamation-triangle me-1"></i>Only ' + item.stock + ' in stock</div>' : '') +
                  '</div>' +
                  '<div class="d-flex flex-column align-items-end gap-1">' +
                    '<div class="input-group input-group-sm cart-qty-group">' +
                      '<button class="btn btn-outline-secondary" type="button" data-cart-action="dec" data-id="' + item.product_id + '" aria-label="Decrease"><i class="bi bi-dash"></i></button>' +
                      '<input type="text" class="form-control text-center cart-qty-input" value="' + item.quantity + '" readonly aria-label="Quantity">' +
                      '<button class="btn btn-outline-secondary" type="button" data-cart-action="inc" data-id="' + item.product_id + '"' + (item.quantity >= max ? ' disabled' : '') + ' aria-label="Increase"><i class="bi bi-plus"></i></button>' +
                    '</div>' +
                    '<button class="btn btn-link text-danger btn-sm p-0 text-decoration-none extra-small" data-cart-action="remove" data-id="' + item.product_id + '"><i class="bi bi-trash"></i> Remove</button>' +
                  '</div>' +
                '</div>';
        }

        function renderDrawer() {
            const cart = getCart();
            const items = cart.items || [];
            const $items = $('#cartDrawerItems');
            if (items.length === 0) {
                $('#cartDrawerEmpty').removeClass('d-none');
                $items.addClass('d-none').empty();
                $('#cartDrawerFooter').addClass('d-none');
                return;
            }
            $('#cartDrawerEmpty').addClass('d-none');
            $items.removeClass('d-none').html(items.map(drawerRow).join(''));
            $('#cartDrawerSubtotal').text(formatCurrency(cart.subtotal));
            $('#cartDrawerFooter').removeClass('d-none');
        }

        function checkoutRow(item) {
            return '' +
                '<div class="d-flex justify-content-between align-items-start checkout-cart-row">' +
                  '<div class="pe-2">' +
                    '<div class="small fw-semibold text-dark">' + esc(item.name) + '</div>' +
                    '<div class="extra-small text-muted">' + formatCurrency(item.price) + ' &times; ' + item.quantity + '</div>' +
                  '</div>' +
                  '<div class="small fw-semibold text-dark text-nowrap">' + formatCurrency(item.subtotal) + '</div>' +
                '</div>';
        }

        function renderCheckoutSummary() {
            const cart = getCart();
            const items = cart.items || [];
            const $list = $('#checkoutCartList');
            if (items.length === 0) {
                $list.addClass('d-none').empty();
                $('#checkoutCartEmpty').removeClass('d-none');
            } else {
                $list.removeClass('d-none').html(items.map(checkoutRow).join(''));
                $('#checkoutCartEmpty').addClass('d-none');
            }
            $('#orderSubtotalDisplay').text(formatCurrency(cart.subtotal));
            $('#orderTotalDisplay').text(formatCurrency(cart.subtotal));

            // Keep the legacy hidden fallback in sync with the first line.
            if (items.length > 0) {
                $('#order_product_id').val(items[0].product_id);
            }
        }

        function updatePlaceOrderState() {
            const cart = getCart();
            const items = cart.items || [];
            let blocked = false;
            let label = '';
            if (items.length === 0) {
                blocked = true;
                label = '<i class="bi bi-cart-x me-1"></i>Cart is Empty';
            } else if (items.some(function (i) { return !i.within_stock; })) {
                blocked = true;
                label = '<i class="bi bi-exclamation-triangle me-1"></i>Fix Cart to Continue';
            }
            $submitBtn.data('cart-blocked', blocked).data('cart-label', label);
            applyPlaceOrderState();
        }

        function renderAll() {
            renderBadge();
            renderDrawer();
            renderCheckoutSummary();
            updatePlaceOrderState();
        }

        // --- API ---
        function handleSuccess(res, opts) {
            if (res && res.cart) {
                window.LPG_CART = res.cart;
                renderAll();
            }
            if ((!opts || opts.toast !== false) && window.showToast && res && res.message) {
                window.showToast(res.message, 'success');
            }
        }

        function handleError(xhr) {
            let msg = 'Could not update your cart.';
            try {
                const parsed = JSON.parse(xhr.responseText);
                msg = parsed.message || parsed.error || msg;
            } catch (e) {}
            if (window.showToast) window.showToast(msg, 'error');
        }

        function mutate(payload, opts) {
            const jq = $.ajax({
                url: endpoint,
                type: 'POST',
                data: payload,
                dataType: 'json'
            });
            jq.done(function (res) {
                if (res && res.success) {
                    handleSuccess(res, opts);
                } else if (window.showToast) {
                    window.showToast((res && (res.message || res.error)) || 'Action failed.', 'error');
                }
            }).fail(handleError);
            return jq;
        }

        // --- Events ---
        // Add to cart
        $(document).on('click', '.btn-add-to-cart', function (e) {
            e.preventDefault();
            const $btn = $(this);
            if ($btn.prop('disabled')) return;
            const id = parseInt($btn.data('id'), 10);
            if (!id) return;
            $btn.prop('disabled', true);
            mutate({ action: 'add', product_id: id, quantity: 1 }).always(function () {
                $btn.prop('disabled', false);
            });
        });

        // Open drawer
        $(document).on('click', '#btnOpenCart, #btnOpenCartInline', function (e) {
            e.preventDefault();
            const el = document.getElementById('cartDrawer');
            if (el && window.bootstrap && window.bootstrap.Offcanvas) {
                window.bootstrap.Offcanvas.getOrCreateInstance(el).show();
            }
        });

        // Drawer quantity / remove controls
        $(document).on('click', '[data-cart-action]', function (e) {
            e.preventDefault();
            const $b = $(this);
            const action = $b.data('cart-action');
            const id = parseInt($b.data('id'), 10);
            if (!id) return;

            const item = (getCart().items || []).filter(function (i) { return i.product_id === id; })[0];
            if (!item) return;

            if (action === 'inc') {
                mutate({ action: 'update', product_id: id, quantity: item.quantity + 1 });
            } else if (action === 'dec') {
                if (item.quantity <= 1) {
                    mutate({ action: 'remove', product_id: id });
                } else {
                    mutate({ action: 'update', product_id: id, quantity: item.quantity - 1 });
                }
            } else if (action === 'remove') {
                mutate({ action: 'remove', product_id: id });
            }
        });

        // Clear cart
        $(document).on('click', '#btnCartClear', function (e) {
            e.preventDefault();
            if (getCart().units === 0) return;
            mutate({ action: 'clear' });
        });

        // Proceed to checkout: close drawer and reveal the checkout form
        $(document).on('click', '#btnCartCheckout', function (e) {
            e.preventDefault();
            const el = document.getElementById('cartDrawer');
            if (el && window.bootstrap && window.bootstrap.Offcanvas) {
                window.bootstrap.Offcanvas.getOrCreateInstance(el).hide();
            }
            if ($orderSection.length) {
                const headerOffset = 70;
                const top = $orderSection.get(0).getBoundingClientRect().top + window.pageYOffset - headerOffset;
                window.scrollTo({ top: top, behavior: 'smooth' });
                $orderSection.addClass('shop-checkout-flash');
                setTimeout(function () {
                    $orderSection.removeClass('shop-checkout-flash');
                }, 1500);
            }
        });

        // Payment Method radio card visual toggle
        $('input[name="payment_method"]').on('change', function () {
            $('.payment-method-card').removeClass('border-primary bg-primary-subtle');
            $(this).closest('.payment-method-card').addClass('border-primary bg-primary-subtle');
        });

        // Initial paint from the server-rendered cart.
        renderAll();

        // Reorder deep-link (?reorder_product_id=..&qty=..) prefills the cart.
        const urlParams = new URLSearchParams(window.location.search);
        const reorderId = parseInt(urlParams.get('reorder_product_id'), 10);
        const reorderQty = parseInt(urlParams.get('qty'), 10) || 1;
        if (reorderId) {
            mutate({ action: 'add', product_id: reorderId, quantity: reorderQty }, { toast: false });
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
        const $notice = $('#deliveryCoverageNotice');
        const $form = $('#orderPlacementForm');

        if ($mapEl.length === 0 || $addressInput.length === 0 || typeof window.L === 'undefined') {
            return;
        }
        if ($mapEl.data('pin-picker-initialized')) return;
        $mapEl.data('pin-picker-initialized', true);

        // Delivery-zone geometry (store pin + radius) configured centrally in
        // config/app.php and emitted by shop.php. Coordinates and radius are
        // not secrets — no API keys or DB credentials are exposed here.
        const cfg = window.DeliveryCoverage || null;
        const enforceCoverage = !!(cfg && cfg.enforced !== false);
        const es = (typeof AppMaps !== 'undefined' && AppMaps.escapeHtml) ? AppMaps.escapeHtml : function (t) { return $('<div>').text(t || '').html(); };

        // Default the button to blocked until a location is confirmed when the
        // zone is enforced. If geometry is unavailable, never block checkout.
        $form.data('coverage-enforced', enforceCoverage);
        $form.data('coverage-ok', enforceCoverage ? false : true);

        const storeCoord = cfg ? [cfg.store.lat, cfg.store.lng] : [14.645962, 120.988134];
        const radiusMeters = cfg ? cfg.radiusMeters : 3000;
        const missingMsg = (cfg && cfg.messages && cfg.messages.missing) || "We couldn't confirm your exact delivery location. Please set your location on the map (or correct your address) before placing an order.";
        const withinMsg = (cfg && cfg.messages && cfg.messages.within) || 'Great! Your address is within our delivery coverage.';
        const outsideMsg = (cfg && cfg.messages && cfg.messages.outside) || "Sorry, we can't deliver to this location. Your selected address is outside our 3 km delivery coverage. Please select another location within our delivery area.";

        let debounceTimer = null;
        let lookupSeq = 0;

        const map = L.map('addressPreviewMap', {
            zoomControl: true,
            scrollWheelZoom: false
        }).setView(storeCoord, 13);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
        }).addTo(map);

        // Blue, semi-transparent circle with an exact radius from config.
        const coverageCircle = L.circle(storeCoord, {
            radius: radiusMeters,
            color: '#2563eb',
            weight: 2,
            opacity: 0.9,
            fillColor: '#2563eb',
            fillOpacity: 0.15
        }).addTo(map);

        // Store marker — deliberately different from the red/blue customer pin.
        const storeIcon = L.divIcon({
            className: 'store-marker-wrapper',
            html: '<div class="store-marker-icon"><i class="bi bi-shop"></i></div>',
            iconSize: [38, 38],
            iconAnchor: [19, 19],
            popupAnchor: [0, -18]
        });
        L.marker(storeCoord, { icon: storeIcon, zIndexOffset: 500 })
            .addTo(map)
            .bindPopup('<strong>' + es((cfg && cfg.storeLabel) || 'Elevate LPG') + '</strong><br>3 km delivery coverage area');

        // On-map legend label so the boundary is self-explanatory.
        const labelControl = L.control({ position: 'topright' });
        labelControl.onAdd = function () {
            const div = L.DomUtil.create('div', 'coverage-map-label');
            div.innerHTML = '<span class="coverage-map-dot"></span>3 KM DELIVERY COVERAGE';
            return div;
        };
        labelControl.addTo(map);

        // Frame the whole circle on first load.
        try { map.fitBounds(coverageCircle.getBounds(), { padding: [16, 16] }); } catch (err) {}

        function makePinIcon(inCoverage) {
            const color = inCoverage ? '#16a34a' : '#ef4444';
            return L.divIcon({
                className: 'pin-marker-wrapper',
                html: '<div style="width:32px;height:32px;background:' + color + ';color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid #fff;box-shadow:0 3px 8px rgba(0,0,0,0.35);font-size:15px;">📍</div>',
                iconSize: [32, 32],
                iconAnchor: [16, 32],
                popupAnchor: [0, -28]
            });
        }

        let marker = null;

        function renderCoverageNotice(state) {
            if (!$notice.length) return;
            if (!state) {
                $notice.html(
                    '<div class="coverage-notice coverage-notice-neutral">' +
                    '<i class="bi bi-geo-alt me-1"></i>' + es(missingMsg) +
                    '</div>'
                );
                return;
            }
            if (state.within) {
                $notice.html(
                    '<div class="coverage-notice coverage-notice-ok">' +
                    '<i class="bi bi-check-circle-fill me-1"></i>' + es(withinMsg) +
                    ' <span class="coverage-distance">Distance: ' + es(state.label) + '</span>' +
                    '</div>'
                );
            } else {
                $notice.html(
                    '<div class="coverage-notice coverage-notice-out">' +
                    '<div><i class="bi bi-exclamation-triangle-fill me-1"></i>' + es(outsideMsg) + '</div>' +
                    '<div class="coverage-out-meta">' +
                    '<span class="coverage-distance"><i class="bi bi-signpost-split me-1"></i>Distance from store: ' + es(state.label) + '</span>' +
                    '<button type="button" class="btn btn-sm btn-outline-danger" id="btnChangeDeliveryLocation">' +
                    '<i class="bi bi-arrow-repeat me-1"></i>Change Delivery Location</button>' +
                    '</div>' +
                    '</div>'
                );
            }
        }

        function updateCoverage(lat, lng) {
            if (!cfg) {
                // No geometry available: never block the customer.
                $form.data('coverage-ok', true);
                if (marker) marker.setIcon(makePinIcon(true));
                applyPlaceOrderState();
                return;
            }
            const distance = AppMaps.distanceMeters(cfg.store.lat, cfg.store.lng, lat, lng);
            const within = distance <= cfg.radiusMeters;
            const label = AppMaps.formatDistance(distance);

            $form.data('coverage-ok', within);
            if (marker) marker.setIcon(makePinIcon(within));
            renderCoverageNotice({ within: within, label: label });
            applyPlaceOrderState();
        }

        function setPin(lat, lng, opts) {
            opts = opts || {};
            $latInput.val(lat);
            $lngInput.val(lng);
            if (marker) {
                marker.setLatLng([lat, lng]);
            } else {
                marker = L.marker([lat, lng], { icon: makePinIcon(true), draggable: true, zIndexOffset: 1000 }).addTo(map);
                bindMarkerEvents();
            }
            if (!opts.keepView) {
                map.setView([lat, lng], Math.max(map.getZoom(), 16));
            }
            updateCoverage(lat, lng);
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
                updateCoverage(pos.lat, pos.lng);
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
                    setStatus('<i class="bi bi-exclamation-triangle me-1"></i>Address not found &mdash; click or drag the pin on the map to mark your exact spot', 'warn');
                    // Never drop a pin at the store on failure — that would
                    // falsely read as in-coverage. Leave the location unset so
                    // the customer must confirm it.
                    if (!marker) {
                        $form.data('coverage-ok', enforceCoverage ? false : true);
                        if (enforceCoverage) renderCoverageNotice(null);
                        applyPlaceOrderState();
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

        // "Change Delivery Location" action from the out-of-coverage warning.
        $notice.on('click', '#btnChangeDeliveryLocation', function () {
            $addressInput.trigger('focus').trigger('select');
            const el = $mapEl.get(0);
            if (el && el.scrollIntoView) {
                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });

        // Restore previously submitted coordinates (validation error re-render)
        const initialLat = parseFloat($latInput.val());
        const initialLng = parseFloat($lngInput.val());
        if (!isNaN(initialLat) && !isNaN(initialLng)) {
            setPin(initialLat, initialLng);
            setStatus('<i class="bi bi-geo-alt-fill me-1"></i>Pin restored &mdash; drag to fine-tune if needed', 'success');
        } else if ($.trim($addressInput.val()).length >= 6) {
            lookupAddress();
        } else {
            // Nothing chosen yet: show the prompt and keep checkout gated.
            if (enforceCoverage) renderCoverageNotice(null);
            applyPlaceOrderState();
        }

        setTimeout(function () { map.invalidateSize(); }, 300);
    }

    // =========================================================================
    // DOM Ready Initialization
    // =========================================================================
    $(function () {
        [initShopCart, initOrdersInteractivity, initProfileInteractivity,
         initAvatarUploadInteractivity,
         function () { if (window.AppMaps) AppMaps.initPinLocationMaps(); },
         function () { if (window.AppChat) AppChat.initPanel({ emptyStateHint: 'Start the conversation with your rider', peerRole: 'rider' }); },
         initCheckoutAddressPinPicker
        ].forEach(function (init) {
            try { init(); } catch (err) { console.error('Init failed:', err); }
        });
    });

})(window, window.jQuery);
