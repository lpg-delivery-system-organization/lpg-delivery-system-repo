/**
 * LPG Delivery System v2 - Admin Portal Client Scripts
 *
 * Provides:
 * 1. Orders live search, status tabs filtering, rider filter, and details/assignment modals
 * 2. Inventory live search, brand filter, stock level filter, and edit/restock modals
 * 3. User accounts live search, role tabs filtering, status filter, and edit user modal
 * 4. Helper formatters, currency, and modal openers
 */

(function (window, $) {
    'use strict';

    // Format number to Philippine Peso (PHP)
    function formatCurrency(amount) {
        const num = parseFloat(amount) || 0;
        return '₱' + num.toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    // Helper to get status badge HTML
    function getStatusBadge(status) {
        const s = (status || '').toLowerCase();
        const labels = {
            'pending':            '<span class="badge badge-status-pending app-status-badge">Pending</span>',
            'approved':           '<span class="badge badge-status-approved app-status-badge">Approved</span>',
            'ready_for_delivery': '<span class="badge badge-status-ready_for_delivery app-status-badge">Ready for Delivery</span>',
            'picked_up':          '<span class="badge badge-status-picked_up app-status-badge">Picked Up</span>',
            'out_for_delivery':   '<span class="badge badge-status-out_for_delivery app-status-badge">Out for Delivery</span>',
            'delivered':          '<span class="badge badge-status-delivered app-status-badge">Delivered</span>',
            'cancelled':          '<span class="badge badge-status-cancelled app-status-badge">Cancelled</span>'
        };
        return labels[s] || '<span class="badge bg-secondary">' + s + '</span>';
    }

    // =========================================================================
    // 1. Admin Orders Management Interactivity
    // =========================================================================
    function initOrdersManagement() {
        const $container = $('#adminOrdersContainer');
        let currentStatusFilter = 'all';
        let currentRiderFilter = 'all';
        let currentSearchQuery = '';

        if ($container.length > 0) {

        function applyOrderFilters() {
            const $rows = $('.admin-order-row');
            let visibleCount = 0;

            $rows.each(function () {
                const $row = $(this);
                const status = ($row.data('status') || '').toLowerCase();
                const riderId = String($row.data('rider-id') || '0');
                const orderId = String($row.data('order-id') || '');
                const customer = ($row.data('customer') || '').toLowerCase();
                const phone = ($row.data('phone') || '').toLowerCase();
                const address = ($row.data('address') || '').toLowerCase();
                const product = ($row.data('product') || '').toLowerCase();

                // Status match
                let statusMatch = (currentStatusFilter === 'all') || (status === currentStatusFilter);

                // Rider match
                let riderMatch = true;
                if (currentRiderFilter === 'unassigned') {
                    riderMatch = (riderId === '0' || riderId === '');
                } else if (currentRiderFilter !== 'all') {
                    riderMatch = (riderId === currentRiderFilter);
                }

                // Search match
                let searchMatch = true;
                if (currentSearchQuery.trim() !== '') {
                    const q = currentSearchQuery.toLowerCase().trim();
                    searchMatch = orderId.includes(q) ||
                                  customer.includes(q) ||
                                  phone.includes(q) ||
                                  address.includes(q) ||
                                  product.includes(q);
                }

                if (statusMatch && riderMatch && searchMatch) {
                    $row.show();
                    visibleCount++;
                } else {
                    $row.hide();
                }
            });

            $('#visibleOrderCount').text(visibleCount);
            if (visibleCount === 0) {
                $('#noAdminFilteredOrdersAlert').removeClass('d-none');
            } else {
                $('#noAdminFilteredOrdersAlert').addClass('d-none');
            }
        }

        // Status Filter Buttons
        $('.admin-order-filter-btn').on('click', function (e) {
            e.preventDefault();
            const $btn = $(this);
            currentStatusFilter = $btn.data('filter') || 'all';

            $('.admin-order-filter-btn').removeClass('active btn-primary text-white').addClass('btn-outline-secondary');
            $btn.addClass('active btn-primary text-white').removeClass('btn-outline-secondary');

            applyOrderFilters();
        });

        // Search Input
        $('#orderSearchInput').on('input', function () {
            currentSearchQuery = $(this).val() || '';
            applyOrderFilters();
        });

        // Rider Select Filter
        $('#orderRiderFilterSelect').on('change', function () {
            currentRiderFilter = $(this).val() || 'all';
            applyOrderFilters();
        });
        }

        // Clickable order row navigation
        $(document).on('click', '.admin-order-row.app-clickable-row, .app-clickable-row', function (e) {
            if ($(e.target).closest('a, button, form, input, select, .dropdown-menu').length) {
                return;
            }
            const href = $(this).data('href') || $(this).attr('data-href');
            if (href) {
                window.location.href = href;
            }
        });

        // Quick Approve Order with confirmation and AJAX
        $(document).on('submit', 'form:has(input[value="approve_order"])', function (e) {
            e.preventDefault();
            const $form = $(this);
            const orderId = $form.find('input[name="order_id"]').val();
            const csrfToken = $form.find('input[name="csrf_token"]').val() || $('meta[name="csrf-token"]').attr('content');

            window.confirmAction({
                title: 'Approve Order #' + orderId,
                message: 'Are you sure you want to approve Order #' + orderId + ' for fulfillment?',
                icon: 'bi-check-circle-fill',
                iconBg: 'bg-success-subtle',
                iconColor: 'text-success',
                confirmText: 'Yes, Approve',
                confirmClass: 'btn-success',
                onConfirm: function (closeModal) {
                    window.ajaxAction({
                        url: $form.attr('action') || window.location.href,
                        data: {
                            action: 'approve_order',
                            order_id: orderId,
                            csrf_token: csrfToken
                        },
                        onSuccess: function () {
                            closeModal();
                            window.showToast('Order #' + orderId + ' approved successfully.', 'success');
                            setTimeout(function () { window.location.reload(); }, 500);
                        },
                        onError: function () {
                            closeModal();
                        }
                    });
                }
            });
        });

        // Admin Order Detail Dispatch Action Buttons
        $(document).on('click', '.btn-admin-dispatch-action', function (e) {
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
                            setTimeout(function () { window.location.reload(); }, 600);
                        },
                        onError: function () {
                            closeModal();
                        }
                    });
                }
            });
        });

        // Assign Rider Form AJAX Submit
        $(document).on('submit', '#adminAssignRiderForm, #assignRiderForm', function (e) {
            e.preventDefault();
            const $form = $(this);
            const formData = $form.serialize();

            window.ajaxAction({
                url: window.location.href,
                data: formData,
                onSuccess: function (res) {
                    window.showToast(res.message || 'Rider assigned successfully.', 'success');
                    setTimeout(function () { window.location.reload(); }, 500);
                }
            });
        });

        // Cancel Order Form AJAX Submit
        $(document).on('submit', '#adminCancelOrderForm', function (e) {
            e.preventDefault();
            const $form = $(this);
            const formData = $form.serialize();

            window.ajaxAction({
                url: window.location.href,
                data: formData,
                onSuccess: function (res) {
                    window.showToast(res.message || 'Order cancelled and stock released.', 'success');
                    setTimeout(function () { window.location.reload(); }, 500);
                }
            });
        });

        // Open Assign Rider Modal on Admin Order Details
        $(document).on('click', '.btn-open-admin-assign-modal', function (e) {
            e.preventDefault();
            const modalEl = document.getElementById('adminAssignRiderModal');
            if (modalEl && window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }
        });

        // Open Cancel Modal on Admin Order Details
        $(document).on('click', '.btn-open-admin-cancel-modal', function (e) {
            e.preventDefault();
            const modalEl = document.getElementById('adminCancelOrderModal');
            if (modalEl && window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }
        });

        // Open Edit Customer Details Modal on Admin Order Details (walk-in)
        $(document).on('click', '.btn-open-edit-customer-info', function (e) {
            e.preventDefault();
            $('#editCustName').val($(this).data('customer-name') || '');
            $('#editCustPhone').val($(this).data('phone') || '');
            $('#editCustAddress').val($(this).data('address') || '');

            const modalEl = document.getElementById('editCustomerInfoModal');
            if (modalEl && window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }
        });

        // Edit Customer Details Form AJAX Submit (walk-in)
        $(document).on('submit', '#editCustomerInfoForm', function (e) {
            e.preventDefault();
            const $form = $(this);
            const formData = $form.serialize();

            window.ajaxAction({
                url: window.location.href,
                data: formData,
                onSuccess: function (res) {
                    window.showToast(res.message || 'Customer details updated successfully.', 'success');
                    setTimeout(function () { window.location.reload(); }, 500);
                }
            });
        });

        // Open Create Walk-in Order Modal
        $(document).on('click', '.btn-open-create-order', function (e) {
            e.preventDefault();
            const modalEl = document.getElementById('createOrderModal');
            if (modalEl && window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }
        });

        // Prefill phone/address when selecting a registered customer (walk-in name field stays independent)
        $(document).on('change', '#createCustomerId', function () {
            syncCreateOrderCustomerDefaults();
        });

        // Show stock limit hint when choosing a product
        $(document).on('change', '#createProductId', function () {
            const stock = parseInt($(this).find('option:selected').data('stock'), 10) || 0;
            if (stock > 0) {
                $('#createQuantity').attr('max', stock);
                $('#createQtyHint').text('Available stock: ' + stock + ' unit(s).');
            } else {
                $('#createQuantity').removeAttr('max');
                $('#createQtyHint').text('Select a product to see available stock.');
            }
        });

        // Prefill helper: copy selected existing customer's contact details into the form
        function syncCreateOrderCustomerDefaults() {
            const $opt = $('#createCustomerId option:selected');
            if ($opt.val()) {
                $('#createPhone').val($opt.data('phone') || '');
                $('#createAddress').val($opt.data('address') || '');
            }
        }

        // Create Walk-in Order Form AJAX Submit
        $(document).on('submit', '#createOrderForm', function (e) {
            e.preventDefault();
            const $form = $(this);
            const formData = $form.serialize();

            window.ajaxAction({
                url: $form.attr('action'),
                data: formData,
                onSuccess: function (res) {
                    window.showToast(res.message || 'Walk-in order created successfully.', 'success');
                    const target = res.order_id ? 'pages/admin/order-detail.php?id=' + res.order_id : null;
                    setTimeout(function () {
                        if (target && window.location.pathname.indexOf('pages/admin/orders.php') !== -1) {
                            window.location.href = target;
                        } else {
                            window.location.reload();
                        }
                    }, 600);
                }
            });
        });

        // Assign Rider Modal Opener
        $(document).on('click', '.btn-open-assign-rider', function (e) {
            e.preventDefault();
            const orderId = $(this).data('order-id');
            const customer = $(this).data('customer') || '';
            const product = $(this).data('product') || '';
            const image = $(this).data('image') || '';

            $('#assignModalOrderId').val(orderId);
            $('#assignModalOrderNumber').text('Order #' + orderId);
            $('#assignModalSummary').text('Customer: ' + customer + ' • ' + product);

            const $imgContainer = $('#assignModalProductImage');
            if (image) {
                const baseUrl = $('meta[name="base-url"]').attr('content') || '';
                $imgContainer.find('img').attr('src', baseUrl + '/' + image.replace(/^\//, '')).attr('alt', product);
                $imgContainer.removeClass('d-none');
            } else {
                $imgContainer.addClass('d-none');
            }

            const modalEl = document.getElementById('assignRiderModal');
            if (modalEl && window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }
        });

        // Cancel Order Modal Opener
        $(document).on('click', '.btn-open-cancel-order', function (e) {
            e.preventDefault();
            const orderId = $(this).data('order-id');
            $('#adminCancelOrderId').val(orderId);
            $('#adminCancelOrderNumber').text('Order #' + orderId);

            const modalEl = document.getElementById('adminCancelOrderModal');
            if (modalEl && window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }
        });

        // View Order Details Modal Opener (orders list page only)
        if ($container.length > 0) {
        $(document).on('click', '.btn-view-order-details', function (e) {
            e.preventDefault();
            const $row = $(this).closest('.admin-order-row');

            const orderId = $row.data('order-id');
            const customer = $row.data('customer') || 'Customer';
            const phone = $row.data('phone') || 'None';
            const address = $row.data('address') || 'None';
            const notes = $row.data('notes') || 'No special instructions';
            const product = $row.data('product') || 'LPG Cylinder';
            const brand = $row.data('brand') || '';
            const weight = $row.data('weight') || '';
            const qty = $row.data('qty') || 1;
            const unitPrice = parseFloat($row.data('unit-price')) || 0;
            const total = parseFloat($row.data('total')) || 0;
            const payment = $row.data('payment') || 'COD';
            const status = $row.data('status') || 'pending';
            const created = $row.data('created') || 'N/A';
            const delivered = $row.data('delivered') || 'Not yet delivered';
            const riderId = $row.data('rider-id');

            $('#detailOrderNumber').text('#' + orderId);
            $('#detailCustomerName').text(customer);
            $('#detailCustomerPhone').text(phone);
            $('#detailDeliveryAddress').text(address);
            $('#detailNotes').text(notes);
            $('#detailProductName').text(product + (brand ? ' (' + brand + ' ' + weight + ')' : ''));
            $('#detailQuantity').text(qty + ' unit(s)');
            $('#detailUnitPrice').text(formatCurrency(unitPrice));
            $('#detailTotalAmount').text(formatCurrency(total));
            $('#detailPaymentMethod').text(payment).attr('class', 'badge ' + (payment === 'GCASH' ? 'bg-primary' : 'bg-success'));
            $('#detailStatusBadge').html(getStatusBadge(status));
            $('#detailCreatedAt').text(created);
            $('#detailDeliveredAt').text(delivered || 'In Progress');

            // Find rider name if assigned
            const riderCellText = $row.find('td:nth-child(5)').text().trim();
            $('#detailRiderName').text(riderCellText || 'Unassigned');

            const modalEl = document.getElementById('orderDetailsModal');
            if (modalEl && window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }
        });

        // URL Query Parameter pre-filtering (e.g. ?q=1001 or ?status=pending)
        const urlParams = new URLSearchParams(window.location.search);
        const queryQ = urlParams.get('q');
        const queryStatus = urlParams.get('status');

        if (queryQ) {
            $('#orderSearchInput').val(queryQ);
            currentSearchQuery = queryQ;
        }
        if (queryStatus) {
            const $statusBtn = $('.admin-order-filter-btn[data-filter="' + queryStatus + '"]');
            if ($statusBtn.length > 0) {
                $statusBtn.trigger('click');
            }
        } else {
            applyOrderFilters();
        }
        }
    }

    // =========================================================================
    // 2. Admin Inventory Management Interactivity
    // =========================================================================
    function initInventoryManagement() {
        const $container = $('#adminInventoryContainer');
        if ($container.length === 0) return;

        let currentBrandFilter = 'all';
        let currentStatusFilter = 'all';
        let currentSearchQuery = '';

        function applyInventoryFilters() {
            const $rows = $('.admin-inventory-row');
            let visibleCount = 0;

            $rows.each(function () {
                const $row = $(this);
                const name = ($row.data('name') || '').toLowerCase();
                const brand = ($row.data('brand') || '').toLowerCase();
                const weight = ($row.data('weight') || '').toLowerCase();
                const status = ($row.data('status') || '').toLowerCase();
                const stock = parseInt($row.data('stock'), 10) || 0;

                // Brand match
                let brandMatch = (currentBrandFilter === 'all') || (brand === currentBrandFilter.toLowerCase());

                // Status / Stock match
                let statusMatch = true;
                if (currentStatusFilter === 'active') {
                    statusMatch = (status === 'active');
                } else if (currentStatusFilter === 'inactive') {
                    statusMatch = (status === 'inactive');
                } else if (currentStatusFilter === 'low_stock') {
                    statusMatch = (stock <= 5);
                }

                // Search query match
                let searchMatch = true;
                if (currentSearchQuery.trim() !== '') {
                    const q = currentSearchQuery.toLowerCase().trim();
                    searchMatch = name.includes(q) || brand.includes(q) || weight.includes(q);
                }

                if (brandMatch && statusMatch && searchMatch) {
                    $row.show();
                    visibleCount++;
                } else {
                    $row.hide();
                }
            });

            $('#visibleInventoryCount').text(visibleCount);
            if (visibleCount === 0) {
                $('#noInventoryFilteredAlert').removeClass('d-none');
            } else {
                $('#noInventoryFilteredAlert').addClass('d-none');
            }
        }

        // Search Input
        $('#inventorySearchInput').on('input', function () {
            currentSearchQuery = $(this).val() || '';
            applyInventoryFilters();
        });

        // Brand Filter Select
        $('#inventoryBrandFilter').on('change', function () {
            currentBrandFilter = $(this).val() || 'all';
            applyInventoryFilters();
        });

        // Status Filter Select
        $('#inventoryStatusFilter').on('change', function () {
            currentStatusFilter = $(this).val() || 'all';
            applyInventoryFilters();
        });

        // Quick Adjust Stock & Price Modal Opener
        $(document).on('click', '.btn-open-stock-modal', function (e) {
            e.preventDefault();
            const $btn = $(this);
            const id = $btn.attr('data-id');
            const name = $btn.attr('data-name') || '';
            const stock = $btn.attr('data-stock');
            const price = parseFloat($btn.attr('data-price'));

            $('#stockModalProductId').val(id);
            $('#stockModalProductIdDisplay').text('#' + id);
            $('#stockModalProductName').text(name);
            $('#stockModalStock').val(stock === undefined ? '' : stock);
            $('#stockModalPrice').val(isNaN(price) ? '' : price.toFixed(2));

            const modalEl = document.getElementById('editStockPriceModal');
            if (modalEl && window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
            } else {
                console.error('Stock modal cannot open: modal element or Bootstrap JS missing.');
            }
        });

        // Full Edit Product Modal Opener
        $(document).on('click', '.btn-open-edit-modal', function (e) {
            e.preventDefault();
            const $btn = $(this);
            const id = $btn.attr('data-id');
            const name = $btn.attr('data-name') || '';
            const brand = $btn.attr('data-brand') || '';
            const weight = $btn.attr('data-weight') || '';
            const price = parseFloat($btn.attr('data-price'));
            const stock = $btn.attr('data-stock');
            const image = $btn.attr('data-image') || '';
            const status = String($btn.attr('data-status') || 'active').toLowerCase().trim();

            $('#editProdId').val(id);
            $('#editProdName').val(name);
            $('#editProdBrand').val(brand);
            $('#editProdWeight').val(weight);
            $('#editProdPrice').val(isNaN(price) ? '' : price.toFixed(2));
            $('#editProdStock').val(stock === undefined ? '' : stock);
            $('#editProdImage').val(image);
            const $statusSel = $('#editProdStatus');
            if ($statusSel.find('option[value="' + status + '"]').length) {
                $statusSel.val(status);
            } else {
                $statusSel.val('active');
            }

            const modalEl = document.getElementById('editProductModal');
            if (modalEl && window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
            } else {
                console.error('Edit modal cannot open: modal element or Bootstrap JS missing.');
            }
        });

        // URL Query Parameter
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('action') === 'new') {
            const addModal = document.getElementById('addProductModal');
            if (addModal && window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(addModal).show();
            }
        }
    }

    // =========================================================================
    // 3. Admin User Management Interactivity
    // =========================================================================
    function initUserManagement() {
        const $container = $('#adminUsersContainer');
        if ($container.length === 0) return;

        let currentRoleFilter = 'all';
        let currentStatusFilter = 'all';
        let currentSearchQuery = '';

        function applyUserFilters() {
            const $rows = $('.admin-user-row');
            let visibleCount = 0;

            $rows.each(function () {
                const $row = $(this);
                const role = ($row.data('role') || '').toLowerCase();
                const status = ($row.data('status') || '').toLowerCase();
                const name = ($row.data('name') || '').toLowerCase();
                const email = ($row.data('email') || '').toLowerCase();
                const phone = ($row.data('phone') || '').toLowerCase();
                const address = ($row.data('address') || '').toLowerCase();

                // Role match
                let roleMatch = (currentRoleFilter === 'all') || (role === currentRoleFilter);

                // Status match
                let statusMatch = (currentStatusFilter === 'all') || (status === currentStatusFilter);

                // Search query match
                let searchMatch = true;
                if (currentSearchQuery.trim() !== '') {
                    const q = currentSearchQuery.toLowerCase().trim();
                    searchMatch = name.includes(q) || email.includes(q) || phone.includes(q) || address.includes(q);
                }

                if (roleMatch && statusMatch && searchMatch) {
                    $row.show();
                    visibleCount++;
                } else {
                    $row.hide();
                }
            });

            $('#visibleUserCount').text(visibleCount);
            if (visibleCount === 0) {
                $('#noUserFilteredAlert').removeClass('d-none');
            } else {
                $('#noUserFilteredAlert').addClass('d-none');
            }
        }

        // Role Filter Buttons
        $('.admin-user-filter-btn').on('click', function (e) {
            e.preventDefault();
            const $btn = $(this);
            currentRoleFilter = $btn.data('role') || 'all';

            $('.admin-user-filter-btn').removeClass('active btn-primary text-white').addClass('btn-outline-secondary');
            $btn.addClass('active btn-primary text-white').removeClass('btn-outline-secondary');

            applyUserFilters();
        });

        // Search Input
        $('#userSearchInput').on('input', function () {
            currentSearchQuery = $(this).val() || '';
            applyUserFilters();
        });

        // Status Select Filter
        $('#userStatusFilterSelect').on('change', function () {
            currentStatusFilter = $(this).val() || 'all';
            applyUserFilters();
        });

        // Edit User Modal Opener
        $(document).on('click', '.btn-open-edit-user', function (e) {
            e.preventDefault();
            const $btn = $(this);
            const id = $btn.attr('data-id');
            const name = $btn.attr('data-name') || '';
            const email = $btn.attr('data-email') || '';
            const phone = $btn.attr('data-phone') || '';
            const address = $btn.attr('data-address') || '';
            const role = ($btn.attr('data-role') || 'customer').toLowerCase().trim();
            const status = ($btn.attr('data-status') || 'active').toLowerCase().trim();

            $('#editUserId').val(id);
            $('#editUserIdDisplay').text('#' + id);
            $('#editUserEmailDisplay').text(email);
            $('#editUserRoleDisplay').text(role);
            $('#editFullName').val(name);
            $('#editPhone').val(phone);
            $('#editAddress').val(address);
            const $editStatus = $('#editStatus');
            if ($editStatus.find('option[value="' + status + '"]').length) {
                $editStatus.val(status);
            }

            const modalEl = document.getElementById('editUserModal');
            if (modalEl && window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
            } else {
                console.error('Edit user modal cannot open: modal element or Bootstrap JS missing.');
            }
        });

        // URL Query parameter filtering (e.g. ?role=rider)
        const urlParams = new URLSearchParams(window.location.search);
        const queryRole = urlParams.get('role');
        if (queryRole) {
            const $roleBtn = $('.admin-user-filter-btn[data-role="' + queryRole + '"]');
            if ($roleBtn.length > 0) {
                $roleBtn.trigger('click');
            }
        } else {
            applyUserFilters();
        }
    }

    // =========================================================================
    // 4. Admin Live Rider Tracking Map (order-detail.php)
    //    Uses shared AppMaps engine: polls real rider GPS from
    //    api/location.php (admin is authorized), with simulated approach
    //    fallback until the first fix / for legacy orders without pins.
    // =========================================================================
    function initAdminLiveTrackingMap() {
        if (typeof window.L === 'undefined' || typeof window.AppMaps === 'undefined') return;

        const $mapEl = $('#adminLiveMap');
        if ($mapEl.length === 0 || $mapEl.data('initialized')) return;
        $mapEl.data('initialized', true);

        const orderId = parseInt($mapEl.data('order-id'), 10);
        if (!orderId) return;

        const address = $mapEl.data('address') || 'Delivery Address';
        const customerName = $mapEl.data('customer-name') || 'Customer';
        const riderName = $mapEl.data('rider-name') || 'Delivery Rider';

        const dest = AppMaps.resolveDestination($mapEl.data('lat'), $mapEl.data('lng'));
        const customerCoord = dest || AppMaps.legacyDest(orderId);
        const riderStart = dest ? [dest[0] - 0.014, dest[1] - 0.014] : AppMaps.legacyRider(orderId);

        const map = AppMaps.createMap('adminLiveMap', {
            zoomControl: true,
            scrollWheelZoom: false
        }).setView(customerCoord, 14);

        L.marker(customerCoord, { icon: AppMaps.icons.customer(34) })
            .addTo(map)
            .bindPopup('<strong>📍 Drop-off: ' + AppMaps.escapeHtml(customerName) + '</strong><br>' + AppMaps.escapeHtml(address));

        const riderMarker = L.marker(riderStart, { icon: AppMaps.icons.rider(40, '🛵') })
            .addTo(map)
            .bindPopup('<strong>🛵 ' + AppMaps.escapeHtml(riderName) + '</strong><br>Live GPS Position')
            .openPopup();

        const routeLine = L.polyline([riderStart, customerCoord], {
            color: '#0d9488',
            weight: 5,
            opacity: 0.85,
            dashArray: '8, 8',
            lineCap: 'round'
        }).addTo(map);

        map.fitBounds(L.latLngBounds([riderStart, customerCoord]), { padding: [40, 40] });

        setTimeout(function () { map.invalidateSize(); }, 300);

        AppMaps.startLiveTracking({
            map: map,
            orderId: orderId,
            customerCoord: customerCoord,
            riderStart: riderStart,
            riderMarker: riderMarker,
            routeLine: routeLine,
            riderName: riderName,
            pollMs: 4000,
            initialPollDelayMs: 400,
            simulateTickMs: 1200,
            simulateStep: 0.045,
            fitPadding: [40, 40],
            statusEl: $('#adminMapStatusText'),
            texts: {
                arrivedPopup: function (name) {
                    return '<strong>✅ Rider has arrived!</strong><br>' + AppMaps.escapeHtml(name) + ' is at the destination.';
                }
            },
            onRealFix: function () {
                $('#adminMapStatusText').removeClass('text-primary').addClass('text-success fw-bold')
                    .html('<i class="bi bi-geo-fill me-1"></i>Live GPS fix received &mdash; tracking rider position');
            }
        });
    }

    // =========================================================================
    // DOM Ready Initialization
    // =========================================================================
    $(function () {
        [initOrdersManagement, initInventoryManagement, initUserManagement,
         initAdminLiveTrackingMap
        ].forEach(function (init) {
            try { init(); } catch (err) { console.error('Init failed:', err); }
        });
    });

})(window, window.jQuery);
