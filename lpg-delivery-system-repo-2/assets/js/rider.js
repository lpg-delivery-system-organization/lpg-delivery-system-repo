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
        if ($container.length === 0) return;

        let currentFilter = 'all';
        let searchQuery = '';

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

        // Search Input
        $('#riderDeliverySearch').on('input', function () {
            searchQuery = $(this).val() || '';
            applyDeliveryFilters();
        });

        // Open Confirm Delivery Modal
        $(document).on('click', '.btn-open-deliver-modal', function (e) {
            e.preventDefault();
            const $btn = $(this);
            const orderId = $btn.data('order-id');
            const customer = $btn.data('customer') || 'Customer';
            const amount = $btn.data('amount') || '₱0.00';
            const payment = ($btn.data('payment') || 'COD').toUpperCase();

            $('#deliverModalOrderId').val(orderId);
            $('#deliverModalOrderNumber').text('Order #' + orderId);
            $('#deliverModalCustomerName').text(customer);
            $('#deliverModalCollectAmount').text(amount);

            if (payment === 'COD') {
                $('#deliverModalCodAlert').removeClass('d-none');
                $('#deliverModalGcashAlert').addClass('d-none');
            } else {
                $('#deliverModalCodAlert').addClass('d-none');
                $('#deliverModalGcashAlert').removeClass('d-none');
            }

            const modalEl = document.getElementById('confirmDeliverModal');
            if (modalEl && window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }
        });

        // Prevent double submit on status forms
        $(document).on('submit', '#confirmDeliverForm', function () {
            $('#btnSubmitConfirmDeliver').prop('disabled', true).html(
                '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Confirming...'
            );
        });
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

        // Claim Delivery Button Loading State
        $(document).on('submit', '.form-claim-delivery', function () {
            const $submitBtn = $(this).find('.btn-claim-order');
            $submitBtn.prop('disabled', true).html(
                '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Claiming...'
            );
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
