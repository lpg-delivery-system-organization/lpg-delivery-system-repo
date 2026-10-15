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

        // Completing a delivery now goes through the mandatory proof-of-delivery
        // photo modal - see initDeliveryProofModal() (.btn-open-proof-modal).

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
    // 1.1 Real GPS Moving Map for Rider (Leaflet + Geolocation API + Broadcasting)
    // =========================================================================
    let riderGpsWatchId = null;
    let riderBroadcastHeartbeatId = null;
    let riderIsBroadcasting = false;
    let riderLastFix = null;
    let riderLastPostAt = 0;

    function initRiderRealGpsMap() {
        if (typeof window.L === 'undefined' || typeof window.AppMaps === 'undefined') return;

        const mapEl = document.getElementById('riderDetailMap');
        if (!mapEl) return;

        const $mapEl = $(mapEl);
        const orderId = $mapEl.data('order-id');
        const orderStatus = ($mapEl.data('status') || '').toLowerCase();
        const customerAddress = $mapEl.data('address') || 'Manila, Philippines';
        const customerName = $mapEl.data('customer-name') || 'Customer';
        const $gpsStatus = $('#gpsRiderStatus');
        const $broadcastBtn = $('#btnToggleBroadcast');
        const $broadcastStatus = $('#broadcastStatus');
        const csrfToken = $('meta[name="csrf-token"]').attr('content') || '';
        const baseUrl = $('meta[name="base-url"]').attr('content') || '';
        const maxAccuracyM = (function () {
            const el = document.querySelector('meta[name="location-max-accuracy"]');
            const v = el ? parseInt(el.getAttribute('content'), 10) : NaN;
            return isNaN(v) ? 100 : v;
        })();

        const canBroadcast = (orderStatus === 'picked_up' || orderStatus === 'out_for_delivery');

        // Exact saved drop-off pin; simulated destination only for legacy orders.
        const dest = AppMaps.resolveDestination($mapEl.data('lat'), $mapEl.data('lng'));
        const customerCoord = dest || AppMaps.riderLegacyDest(orderId);
        let currentRiderCoord = null;

        const map = AppMaps.createMap('riderDetailMap', {
            zoomControl: true
        }).setView(customerCoord, 14);

        const customerMarker = L.marker(customerCoord, { icon: AppMaps.icons.customer(36) })
            .addTo(map)
            .bindPopup('<strong>📍 Drop-off: ' + AppMaps.escapeHtml(customerName) + '</strong><br>' + AppMaps.escapeHtml(customerAddress));

        // Rider marker stays hidden until the first real GPS fix arrives.
        const riderMarker = L.marker(customerCoord, { icon: AppMaps.icons.rider(40, '🛵') })
            .addTo(map)
            .bindPopup('<strong>🛵 You (Rider)</strong><br>Live GPS Position')
            .setOpacity(0);

        const routeLine = L.polyline([customerCoord, customerCoord], {
            color: '#0d9488',
            weight: 5,
            opacity: 0.85,
            dashArray: '8, 8',
            lineCap: 'round'
        }).addTo(map).setStyle({ opacity: 0 });

        let accuracyCircle = null;

        $('#btnCenterGps').on('click', function (e) {
            e.preventDefault();
            if (!currentRiderCoord) return;
            map.setView(currentRiderCoord, 16);
            riderMarker.openPopup();
        });

        function renderAccuracy(lat, lng, accuracy) {
            const radius = (accuracy == null || isNaN(accuracy) || accuracy <= 0) ? 0 : accuracy;
            if (!accuracyCircle) {
                accuracyCircle = L.circle([lat, lng], {
                    radius: Math.max(radius, 1),
                    color: '#0d9488',
                    weight: 1,
                    fillColor: '#14b8a6',
                    fillOpacity: 0.18,
                    opacity: 0.7,
                    interactive: false
                }).addTo(map);
            } else {
                accuracyCircle.setLatLng([lat, lng]);
                accuracyCircle.setRadius(Math.max(radius, 1));
            }
            accuracyCircle.setStyle({
                opacity: radius > 0 ? 0.7 : 0,
                fillOpacity: radius > 0 ? 0.18 : 0
            });
            return radius;
        }

        /** Human-readable precision summary for the GPS status line. */
        function describeFix(lat, lng, accuracy) {
            const radius = (accuracy == null || isNaN(accuracy)) ? null : Math.round(accuracy);
            if (radius === null) return 'accurate';
            if (radius > 1000) return 'approximate (&asymp;' + (radius / 1000).toFixed(1) + ' km) &mdash; use a phone with GPS';
            if (radius > maxAccuracyM) return 'signal weak (&asymp;' + radius + ' m)';
            return 'accurate (&asymp;' + radius + ' m)';
        }

        function updateRiderPosition(lat, lng, accuracy) {
            currentRiderCoord = [lat, lng];
            riderMarker.setLatLng(currentRiderCoord).setOpacity(1);
            routeLine.setLatLngs([currentRiderCoord, customerCoord]).setStyle({ opacity: 0.85 });
            renderAccuracy(lat, lng, accuracy);
            updateRiderRoute();
        }

        // Real-time road-following route (OSRM), throttled, with straight-line fallback.
        let riderRouteInFlight = false;
        let riderLastRouteFetchAt = 0;
        function updateRiderRoute() {
            if (riderRouteInFlight) return;
            const now = Date.now();
            if (now - riderLastRouteFetchAt < 6000) return;
            riderLastRouteFetchAt = now;

            riderRouteInFlight = true;
            AppMaps.fetchRoute(currentRiderCoord, customerCoord).then(function (points) {
                riderRouteInFlight = false;
                if (points && points.length > 1) {
                    routeLine.setLatLngs(points);
                }
            });
        }

        function postLocationToServer(lat, lng, accuracy) {
            if (!riderIsBroadcasting) return;
            // Throttle: at most one stored fix every 4 seconds.
            const now = Date.now();
            if (now - riderLastPostAt < 4000) return;
            riderLastPostAt = now;
            $.ajax({
                url: baseUrl + 'api/location.php',
                type: 'POST',
                data: {
                    action: 'update',
                    order_id: orderId,
                    latitude: lat,
                    longitude: lng,
                    accuracy: accuracy || null,
                    csrf_token: csrfToken
                },
                dataType: 'json'
            });
        }

        /** Single source of truth for every GPS fix on this page. */
        function onFix(pos) {
            const lat = pos.coords.latitude;
            const lng = pos.coords.longitude;
            const accuracy = pos.coords.accuracy;
            riderLastFix = { lat: lat, lng: lng, accuracy: accuracy };

            updateRiderPosition(lat, lng, accuracy);
            postLocationToServer(lat, lng, accuracy);

            if (riderIsBroadcasting) {
                $gpsStatus.html('<span class="text-success"><i class="bi bi-broadcast me-1"></i>Sharing live location &mdash; '
                    + describeFix(lat, lng, accuracy) + '</span>');
            } else {
                $gpsStatus.html('<span class="text-success"><i class="bi bi-geo-fill me-1"></i>GPS fix &mdash; '
                    + describeFix(lat, lng, accuracy) + '</span>');
            }
        }

        function onGpsError(err) {
            const msg = (err && err.message) ? err.message : 'unavailable';
            $gpsStatus.html('<span class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i>GPS unavailable: '
                + AppMaps.escapeHtml(msg) + '. Enable location access for live tracking.</span>');
        }

        function startBroadcasting() {
            if (riderIsBroadcasting) return;
            riderIsBroadcasting = true;

            if ($broadcastBtn.length) {
                $broadcastBtn.removeClass('btn-outline-success').addClass('btn-danger')
                    .html('<i class="bi bi-broadcast me-1"></i>Stop Broadcasting');
            }
            if ($broadcastStatus.length) {
                $broadcastStatus.html('<span class="badge bg-success"><i class="bi bi-broadcast me-1"></i>Live GPS Broadcasting Active</span>');
            }

            // Send the current fix immediately...
            if (riderLastFix) {
                riderLastPostAt = 0;
                postLocationToServer(riderLastFix.lat, riderLastFix.lng, riderLastFix.accuracy);
            }

            // ...then a light heartbeat so the customer still sees you when stationary.
            if (riderBroadcastHeartbeatId === null) {
                riderBroadcastHeartbeatId = setInterval(function () {
                    if (riderIsBroadcasting && riderLastFix) {
                        riderLastPostAt = 0;
                        postLocationToServer(riderLastFix.lat, riderLastFix.lng, riderLastFix.accuracy);
                    }
                }, 10000);
            }
        }

        function stopBroadcasting() {
            riderIsBroadcasting = false;
            if (riderBroadcastHeartbeatId) {
                clearInterval(riderBroadcastHeartbeatId);
                riderBroadcastHeartbeatId = null;
            }
            if ($broadcastBtn.length) {
                $broadcastBtn.removeClass('btn-danger').addClass('btn-outline-success')
                    .html('<i class="bi bi-broadcast me-1"></i>Start Broadcasting');
            }
            if ($broadcastStatus.length) {
                $broadcastStatus.html('<span class="badge bg-secondary"><i class="bi bi-pause-circle me-1"></i>Broadcasting Paused</span>');
            }
            if (riderLastFix) {
                $gpsStatus.html('<span class="text-muted"><i class="bi bi-info-circle me-1"></i>Broadcasting paused &mdash; GPS still active locally</span>');
            } else {
                $gpsStatus.html('<span class="text-muted"><i class="bi bi-info-circle me-1"></i>Broadcasting stopped</span>');
            }
        }

        if ($broadcastBtn.length && canBroadcast) {
            $broadcastBtn.on('click', function (e) {
                e.preventDefault();
                if (riderIsBroadcasting) {
                    stopBroadcasting();
                } else {
                    startBroadcasting();
                }
            });
        }

        // Exactly one geolocation watch powers the whole page. When broadcasting
        // is off it still updates the rider's own marker (private preview).
        if (navigator.geolocation) {
            riderGpsWatchId = navigator.geolocation.watchPosition(onFix, onGpsError, {
                enableHighAccuracy: true,
                timeout: 15000,
                maximumAge: 0
            });
            if (!canBroadcast) {
                $gpsStatus.html('<span class="text-muted"><i class="bi bi-hourglass-split me-1"></i>GPS ready &mdash; location sharing unlocks once the order is picked up.</span>');
            }
        } else {
            $gpsStatus.html('<span class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i>Geolocation is not supported by this browser.</span>');
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
                                const baseUrl = $('meta[name="base-url"]').attr('content') || '';
                                window.location.href = res.redirect || (baseUrl + 'pages/rider/order-detail.php?id=' + orderId);
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
    function initAvatarUploadInteractivity() {
        const $avatarForm = $('#riderAvatarForm');
        if ($avatarForm.length === 0) return;

        // Clicking "Upload Photo" opens the file browser directly
        $('#riderAvatarBtn').on('click', function () {
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
    // 3.5 Proof-of-Delivery Photo Modal (rider order-detail.php + deliveries.php)
    // =========================================================================
    function initDeliveryProofModal() {
        const modalEl = document.getElementById('deliveryProofModal');
        if (!modalEl) return;

        const formEl = document.getElementById('deliveryProofForm');
        const fileInput = document.getElementById('proofPhotoInput');
        const previewImg = document.getElementById('proofPreview');
        const previewWrap = document.getElementById('proofPreviewWrap');
        const submitBtn = document.getElementById('btnSubmitDeliveryProof');
        const retakeBtn = document.getElementById('proofRetake');
        const orderIdInput = document.getElementById('proofOrderId');
        const orderLabel = document.getElementById('proofOrderLabel');
        const codAlert = document.getElementById('proofCodAlert');
        const codAmount = document.getElementById('proofCodAmount');

        const bsModal = (window.bootstrap && bootstrap.Modal)
            ? bootstrap.Modal.getOrCreateInstance(modalEl)
            : null;

        const submitDefaultHtml = '<i class="bi bi-check-circle-fill me-1"></i>Complete Delivery';
        let previewUrl = null;
        let submitting = false;

        function clearPreview() {
            if (previewUrl) {
                URL.revokeObjectURL(previewUrl);
                previewUrl = null;
            }
            if (previewWrap) previewWrap.classList.add('d-none');
            if (previewImg) previewImg.removeAttribute('src');
        }

        function resetForm() {
            if (formEl) formEl.reset();
            clearPreview();
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = submitDefaultHtml;
            }
            if (codAlert) codAlert.classList.add('d-none');
            submitting = false;
        }

        $(document).on('click', '.btn-open-proof-modal', function (e) {
            e.preventDefault();
            e.stopPropagation();

            const $btn = $(this);
            const orderId = $btn.data('order-id') || '';
            const amount = $btn.data('amount') || '';
            const payment = String($btn.data('payment') || 'COD').toUpperCase();

            resetForm();
            if (orderIdInput) orderIdInput.value = orderId;
            if (orderLabel) orderLabel.textContent = orderId ? ('for Order #' + orderId) : '';

            if (payment === 'COD' && amount && codAlert && codAmount) {
                codAmount.textContent = amount;
                codAlert.classList.remove('d-none');
            }

            if (bsModal) bsModal.show();
        });

        if (fileInput) {
            fileInput.addEventListener('change', function () {
                const file = fileInput.files && fileInput.files[0];
                clearPreview();

                if (!file) {
                    if (submitBtn) submitBtn.disabled = true;
                    return;
                }

                if (!/^image\//.test(file.type)) {
                    if (window.showToast) window.showToast('Please choose an image file (JPG, PNG or WebP).', 'danger');
                    fileInput.value = '';
                    if (submitBtn) submitBtn.disabled = true;
                    return;
                }

                previewUrl = URL.createObjectURL(file);
                if (previewImg) previewImg.src = previewUrl;
                if (previewWrap) previewWrap.classList.remove('d-none');
                if (submitBtn) submitBtn.disabled = false;
            });
        }

        if (retakeBtn) {
            retakeBtn.addEventListener('click', function () {
                if (fileInput) fileInput.value = '';
                clearPreview();
                if (submitBtn) submitBtn.disabled = true;
                if (fileInput) fileInput.focus();
            });
        }

        if (formEl) {
            formEl.addEventListener('submit', function (e) {
                e.preventDefault();
                if (submitting) return;

                const file = fileInput && fileInput.files && fileInput.files[0];
                if (!file) {
                    if (window.showToast) window.showToast('Please attach the delivery photo first.', 'warning');
                    return;
                }

                submitting = true;
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Completing...';
                }

                const baseUrl = ($('meta[name="base-url"]').attr('content') || '').replace(/\/+$/, '');

                $.ajax({
                    url: baseUrl + '/api/delivery-proof.php',
                    method: 'POST',
                    data: new FormData(formEl),
                    processData: false,
                    contentType: false,
                    dataType: 'json'
                }).done(function (res) {
                    if (res && res.success) {
                        if (bsModal) bsModal.hide();
                        if (window.showToast) window.showToast(res.message || 'Delivery completed.', 'success');
                        setTimeout(function () { window.location.reload(); }, 700);
                    } else {
                        submitting = false;
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = submitDefaultHtml;
                        }
                        if (window.showToast) window.showToast((res && res.message) || 'Could not complete the delivery.', 'danger');
                    }
                }).fail(function (xhr) {
                    submitting = false;
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = submitDefaultHtml;
                    }
                    let msg = 'Could not complete the delivery. Please try again.';
                    try {
                        const res = xhr.responseJSON || JSON.parse(xhr.responseText);
                        if (res && res.message) msg = res.message;
                    } catch (err) { /* keep default */ }
                    if (window.showToast) window.showToast(msg, 'danger');
                });
            });
        }

        $(modalEl).on('hidden.bs.modal', resetForm);
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
    // 4. Delivery Address Pin Location Map & Chat Panel -> shared-maps.js
    //    (AppMaps.initPinLocationMaps / AppChat.initPanel)
    // =========================================================================

    // =========================================================================
    // DOM Ready Initialization
    // =========================================================================
    $(function () {
        [initDeliveriesInteractivity, initAvailableInteractivity, initProfileInteractivity,
         initAvatarUploadInteractivity,
         initDeliveryProofModal,
         initCopyAddressHandler,
         function () { if (window.AppMaps) AppMaps.initPinLocationMaps(); },
         function () { if (window.AppChat) AppChat.initPanel({ emptyStateHint: 'Start the conversation with the customer', peerRole: 'customer' }); }
        ].forEach(function (init) {
            try { init(); } catch (err) { console.error('Init failed:', err); }
        });
    });

})(window, window.jQuery);
