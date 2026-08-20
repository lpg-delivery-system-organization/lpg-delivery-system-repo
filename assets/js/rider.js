/**
 * LPG Delivery System v2 - Rider Portal Client Scripts
 *
 * Provides:
 * 1. Deliveries live search & status tab filtering (all, active, picked_up, out_for_delivery, delivered)
 * 2. Delivery confirmation modal opener with COD cash collection alert
 * 3. Available orders live search & payment method filter
 * 4. 1-click clipboard address copy shortcut with toast feedback
 * 5. Profile phone number validator & real-time password complexity checklist
 */

(function (window, $) {
    'use strict';

    // Helper: Copy text to clipboard safely
    function copyToClipboard(text, successMessage) {
        if (!text) return;

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function () {
                if (window.showToast) {
                    window.showToast(successMessage || 'Copied to clipboard!', 'info');
                }
            }).catch(function () {
                fallbackCopyText(text, successMessage);
            });
        } else {
            fallbackCopyText(text, successMessage);
        }
    }

    function fallbackCopyText(text, successMessage) {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.style.position = 'fixed';
        textArea.style.left = '-999999px';
        textArea.style.top = '-999999px';
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();

        try {
            document.execCommand('copy');
            if (window.showToast) {
                window.showToast(successMessage || 'Copied to clipboard!', 'info');
            }
        } catch (err) {
            console.error('Fallback clipboard copy failed:', err);
        }
        document.body.removeChild(textArea);
    }

    // =========================================================================
    // 1. Deliveries Management Interactivity (deliveries.php)
    // =========================================================================
    function initDeliveriesInteractivity() {
        const $container = $('#riderDeliveriesContainer');
        let currentFilter = 'all';
        let searchQuery = '';

        if ($container.length > 0) {

        function applyDeliveryFilters() {
            const $cards = $('.rider-delivery-card');
            let visibleCount = 0;

            $cards.each(function () {
                const $card = $(this);
                const status = ($card.data('status') || '').toLowerCase();
                const isActive = String($card.data('is-active')) === '1';
                const orderId = String($card.data('order-id') || '');
                const customer = ($card.data('customer') || '').toLowerCase();
                const address = ($card.data('address') || '').toLowerCase();
                const phone = ($card.data('phone') || '').toLowerCase();

                // Status match
                let statusMatch = false;
                if (currentFilter === 'all') {
                    statusMatch = true;
                } else if (currentFilter === 'active') {
                    statusMatch = isActive;
                } else if (currentFilter === status) {
                    statusMatch = true;
                }

                // Search query match
                let searchMatch = true;
                if (searchQuery.trim() !== '') {
                    const q = searchQuery.toLowerCase().trim();
                    searchMatch = orderId.includes(q) ||
                                  customer.includes(q) ||
                                  address.includes(q) ||
                                  phone.includes(q);
                }

                if (statusMatch && searchMatch) {
                    $card.show();
                    visibleCount++;
                } else {
                    $card.hide();
                }
            });

            if (visibleCount === 0) {
                $('#noRiderFilteredAlert').removeClass('d-none');
            } else {
                $('#noRiderFilteredAlert').addClass('d-none');
            }
        }

        // Status Filter Buttons
        $('.rider-filter-btn').on('click', function (e) {
            e.preventDefault();
            const $btn = $(this);
            currentFilter = $btn.data('filter') || 'all';

            $('.rider-filter-btn').removeClass('active btn-primary text-white').addClass('btn-outline-secondary');
            $btn.addClass('active btn-primary text-white').removeClass('btn-outline-secondary');

            applyDeliveryFilters();
        });
        }

        // Clickable delivery card navigation
        $(document).on('click', '.rider-delivery-card.app-clickable-card, .available-order-item .app-clickable-card, .app-clickable-card', function (e) {
            if ($(e.target).closest('a, button, form, input, select, textarea').length) {
                return;
            }
            const href = $(this).data('href') || $(this).attr('data-href');
            if (href) {
                window.location.href = href;
            }
        });

        // Open Confirm Delivery Modal via AJAX
        $(document).on('click', '.btn-open-deliver-modal', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const $btn = $(this);
            const orderId = $btn.data('order-id');
            const customer = $btn.data('customer') || 'Customer';
            const amount = $btn.data('amount') || '₱0.00';
            const payment = ($btn.data('payment') || 'COD').toUpperCase();
            const csrfToken = $('meta[name="csrf-token"]').attr('content') || '';

            const confirmMsg = payment === 'COD' 
                ? 'Please confirm you collected <strong>' + amount + ' cash</strong> from ' + customer + ' and delivered the cylinder.'
                : 'Confirm that Order #' + orderId + ' has been handed over to ' + customer + '.';

            window.confirmAction({
                title: 'Confirm Delivery #' + orderId,
                message: confirmMsg,
                icon: 'bi-check-circle-fill',
                iconBg: 'bg-success-subtle',
                iconColor: 'text-success',
                confirmText: 'Yes, Confirm Delivered',
                confirmClass: 'btn-success',
                onConfirm: function (closeModal) {
                    window.ajaxAction({
                        url: window.location.href,
                        data: {
                            action: 'update_status',
                            status: 'delivered',
                            order_id: orderId,
                            csrf_token: csrfToken
                        },
                        onSuccess: function (res) {
                            closeModal();
                            window.showToast('Delivery #' + orderId + ' marked as delivered!', 'success');
                            setTimeout(function () { window.location.reload(); }, 600);
                        },
                        onError: function () {
                            closeModal();
                        }
                    });
                }
            });
        });

        // Pick up / Out for delivery AJAX progression
        $(document).on('submit', '.rider-delivery-card form', function (e) {
            e.preventDefault();
            const $form = $(this);
            const orderId = $form.find('input[name="order_id"]').val();
            const targetStatus = $form.find('input[name="status"]').val();
            const csrfToken = $form.find('input[name="csrf_token"]').val() || $('meta[name="csrf-token"]').attr('content');

            const statusTitle = targetStatus === 'picked_up' ? 'Pick Up Order #' + orderId : 'Mark Out for Delivery';
            const statusMsg = targetStatus === 'picked_up' 
                ? 'Confirm you have picked up the cylinder for Order #' + orderId + ' from the warehouse?'
                : 'Confirm you are now en route to deliver Order #' + orderId + '?';

            window.confirmAction({
                title: statusTitle,
                message: statusMsg,
                icon: targetStatus === 'picked_up' ? 'bi-box-seam' : 'bi-truck',
                iconBg: targetStatus === 'picked_up' ? 'bg-primary-subtle' : 'bg-info-subtle',
                iconColor: targetStatus === 'picked_up' ? 'text-primary' : 'text-info',
                confirmText: 'Confirm',
                confirmClass: targetStatus === 'picked_up' ? 'btn-primary' : 'btn-info text-white',
                onConfirm: function (closeModal) {
                    window.ajaxAction({
                        url: window.location.href,
                        data: {
                            action: 'update_status',
                            status: targetStatus,
                            order_id: orderId,
                            csrf_token: csrfToken
                        },
                        onSuccess: function (res) {
                            closeModal();
                            window.showToast('Status updated successfully.', 'success');
                            setTimeout(function () { window.location.reload(); }, 600);
                        },
                        onError: function () {
                            closeModal();
                        }
                    });
                }
            });
        });

        // Handle generic Rider Action Buttons (e.g. on order-detail.php)
        $(document).on('click', '.btn-rider-action', function (e) {
            e.preventDefault();
            const $btn = $(this);
            const action = $btn.data('action');
            const status = $btn.data('status') || '';
            const orderId = $btn.data('order-id');
            const csrfToken = $btn.data('csrf') || $('meta[name="csrf-token"]').attr('content') || '';
            const title = $btn.data('confirm-title') || 'Confirm Action';
            const msg = $btn.data('confirm-msg') || 'Are you sure you want to proceed?';
            const icon = $btn.data('confirm-icon') || 'bi-question-circle';
            const iconColor = $btn.data('confirm-color') || 'text-primary';
            const confirmBtnClass = $btn.data('confirm-btn') || 'btn-primary';

            window.confirmAction({
                title: title,
                message: msg,
                icon: icon,
                iconColor: iconColor,
                confirmText: 'Confirm',
                confirmClass: confirmBtnClass,
                onConfirm: function (closeModal) {
                    window.ajaxAction({
                        url: window.location.href,
                        data: {
                            action: action,
                            status: status,
                            order_id: orderId,
                            csrf_token: csrfToken
                        },
                        onSuccess: function (res) {
                            closeModal();
                            window.showToast(res.message || 'Action completed successfully.', 'success');
                            setTimeout(function () { 
                                if (res.redirect) {
                                    window.location.href = res.redirect;
                                } else {
                                    window.location.reload(); 
                                }
                            }, 600);
                        },
                        onError: function () {
                            closeModal();
                        }
                    });
                }
            });
        });

        // Initialize Real GPS Rider Map
        initRiderRealGpsMap();
    }

    // =========================================================================
    // 1.1 Real GPS Moving Map for Rider (Leaflet + Geolocation API)
    // =========================================================================
    function initRiderRealGpsMap() {
        if (typeof window.L === 'undefined') return;

        const mapEl = document.getElementById('riderDetailMap');
        if (!mapEl) return;

        const $mapEl = $(mapEl);
        const orderId = $mapEl.data('order-id');
        const customerAddress = $mapEl.data('address') || 'Manila, Philippines';
        const customerName = $mapEl.data('customer-name') || 'Customer';
        const $gpsStatus = $('#gpsRiderStatus');

        // Deterministic Customer Coordinates (around Metro Manila)
        const offset = (orderId % 10) * 0.005;
        const customerCoord = [14.5995 + offset, 120.9842 + offset];

        // Initial default rider coordinates (fallback until GPS fix)
        let currentRiderCoord = [14.5850 + offset, 120.9750 + offset];

        const map = L.map('riderDetailMap', {
            zoomControl: true
        }).setView(customerCoord, 14);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);

        // Customer Destination Marker (Red Pin)
        const customerIcon = L.divIcon({
            className: 'customer-marker-wrapper',
            html: '<div class="customer-marker-icon" style="width:36px;height:36px;background:#ef4444;color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;border:2px solid #fff;box-shadow:0 3px 6px rgba(0,0,0,0.3);font-size:16px;">📍</div>',
            iconSize: [36, 36],
            iconAnchor: [18, 36],
            popupAnchor: [0, -32]
        });

        const customerMarker = L.marker(customerCoord, { icon: customerIcon })
            .addTo(map)
            .bindPopup('<strong>📍 Drop-off: ' + $('<div>').text(customerName).html() + '</strong><br>' + $('<div>').text(customerAddress).html());

        // Rider Live Moving Marker (Blue Motorcycle)
        const riderIcon = L.divIcon({
            className: 'rider-marker-wrapper',
            html: '<div class="rider-marker-icon" style="width:40px;height:40px;background:#2563eb;color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;border:2px solid #fff;box-shadow:0 3px 6px rgba(0,0,0,0.3);font-size:20px;">🛵</div>',
            iconSize: [40, 40],
            iconAnchor: [20, 20],
            popupAnchor: [0, -22]
        });

        const riderMarker = L.marker(currentRiderCoord, { icon: riderIcon })
            .addTo(map)
            .bindPopup('<strong>🛵 You (Rider)</strong><br>Live GPS Position')
            .openPopup();

        // Route Polyline
        const routeLine = L.polyline([currentRiderCoord, customerCoord], {
            color: '#2563eb',
            weight: 5,
            opacity: 0.85,
            dashArray: '8, 8',
            lineCap: 'round'
        }).addTo(map);

        const bounds = L.latLngBounds([currentRiderCoord, customerCoord]);
        map.fitBounds(bounds, { padding: [50, 50] });

        // Center on GPS button
        $('#btnCenterGps').on('click', function (e) {
            e.preventDefault();
            map.setView(currentRiderCoord, 16);
            riderMarker.openPopup();
        });

        // Real Browser Geolocation API
        if (navigator.geolocation) {
            navigator.geolocation.watchPosition(
                function (pos) {
                    const lat = pos.coords.latitude;
                    const lng = pos.coords.longitude;
                    currentRiderCoord = [lat, lng];

                    riderMarker.setLatLng(currentRiderCoord);
                    routeLine.setLatLngs([currentRiderCoord, customerCoord]);

                    $gpsStatus.html('<span class="text-success"><i class="bi bi-geo-fill me-1"></i>Live GPS Active (' + lat.toFixed(4) + ', ' + lng.toFixed(4) + ')</span>');
                },
                function (err) {
                    // Fallback to simulated movement if permission denied or unavailable
                    $gpsStatus.html('<span class="text-muted"><i class="bi bi-info-circle me-1"></i>Simulated GPS (Location permission unavailable)</span>');
                    
                    // Simulate moving towards destination
                    setInterval(function () {
                        const latDiff = customerCoord[0] - currentRiderCoord[0];
                        const lngDiff = customerCoord[1] - currentRiderCoord[1];
                        if (Math.abs(latDiff) > 0.0002 || Math.abs(lngDiff) > 0.0002) {
                            currentRiderCoord[0] += latDiff * 0.03;
                            currentRiderCoord[1] += lngDiff * 0.03;
                            riderMarker.setLatLng(currentRiderCoord);
                            routeLine.setLatLngs([currentRiderCoord, customerCoord]);
                        }
                    }, 2000);
                },
                {
                    enableHighAccuracy: true,
                    timeout: 10000,
                    maximumAge: 0
                }
            );
        } else {
            $gpsStatus.html('<span class="text-muted">Geolocation not supported by browser.</span>');
        }
    }

    // =========================================================================
    // 2. Available Orders Interactivity (available.php)
    // =========================================================================
    function initAvailableInteractivity() {
        const $container = $('#riderAvailableContainer');
        if ($container.length === 0) return;

        let searchQuery = '';
        let paymentFilter = 'all';

        function applyAvailableFilters() {
            const $items = $('.available-order-item');
            let visibleCount = 0;

            $items.each(function () {
                const $item = $(this);
                const orderId = String($item.data('order-id') || '');
                const customer = ($item.data('customer') || '').toLowerCase();
                const address = ($item.data('address') || '').toLowerCase();
                const phone = ($item.data('phone') || '').toLowerCase();
                const payment = ($item.data('payment') || '').toUpperCase();

                // Payment filter match
                let paymentMatch = (paymentFilter === 'all') || (payment === paymentFilter);

                // Search query match
                let searchMatch = true;
                if (searchQuery.trim() !== '') {
                    const q = searchQuery.toLowerCase().trim();
                    searchMatch = orderId.includes(q) ||
                                  customer.includes(q) ||
                                  address.includes(q) ||
                                  phone.includes(q);
                }

                if (paymentMatch && searchMatch) {
                    $item.show();
                    visibleCount++;
                } else {
                    $item.hide();
                }
            });

            $('#availableVisibleCount').text(visibleCount);
            if (visibleCount === 0) {
                $('#noAvailableFilteredAlert').removeClass('d-none');
            } else {
                $('#noAvailableFilteredAlert').addClass('d-none');
            }
        }

        // Search Input
        $('#availableOrderSearch').on('input', function () {
            searchQuery = $(this).val() || '';
            applyAvailableFilters();
        });

        // Payment Method Select Filter
        $('#availablePaymentFilter').on('change', function () {
            paymentFilter = $(this).val() || 'all';
            applyAvailableFilters();
        });

        // Claim Delivery Button with Confirmation Modal & AJAX
        $(document).on('submit', '.form-claim-delivery', function (e) {
            e.preventDefault();
            const $form = $(this);
            const orderId = $form.find('input[name="order_id"]').val();
            const csrfToken = $form.find('input[name="csrf_token"]').val() || $('meta[name="csrf-token"]').attr('content');

            window.confirmAction({
                title: 'Claim Delivery #' + orderId,
                message: 'Are you sure you want to claim this delivery order for immediate fulfillment?',
                icon: 'bi-box-arrow-in-down',
                iconBg: 'bg-primary-subtle',
                iconColor: 'text-primary',
                confirmText: 'Yes, Claim Delivery',
                confirmClass: 'btn-primary',
                onConfirm: function (closeModal) {
                    window.ajaxAction({
                        url: $form.attr('action') || window.location.href,
                        data: {
                            action: 'claim_order',
                            order_id: orderId,
                            csrf_token: csrfToken
                        },
                        onSuccess: function (res) {
                            closeModal();
                            window.showToast('Order #' + orderId + ' claimed successfully!', 'success');
                            setTimeout(function () {
                                window.location.href = res.redirect || ('/pages/rider/order-detail.php?id=' + orderId);
                            }, 500);
                        },
                        onError: function () {
                            closeModal();
                        }
                    });
                }
            });
        });
    }

    // =========================================================================
    // 3. Profile Management Interactivity (profile.php)
    // =========================================================================
    function initProfileInteractivity() {
        const $profileForm = $('#riderProfileForm');
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
    // 4. Global Copy Address Handler
    // =========================================================================
    function initCopyAddressHandler() {
        $(document).on('click', '.btn-copy-address', function (e) {
            e.preventDefault();
            const address = $(this).data('address') || '';
            const $btn = $(this);
            const originalHtml = $btn.html();

            copyToClipboard(address, 'Address copied to clipboard!');

            // Temporary visual button feedback
            $btn.html('<i class="bi bi-check2 text-success me-1"></i>Copied!');
            setTimeout(function () {
                $btn.html(originalHtml);
            }, 2000);
        });
    }

    // =========================================================================
    // DOM Ready Initialization
    // =========================================================================
    $(function () {
        initDeliveriesInteractivity();
        initAvailableInteractivity();
        initProfileInteractivity();
        initCopyAddressHandler();
    });

})(window, window.jQuery);
