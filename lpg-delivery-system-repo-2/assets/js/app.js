/**
 * LPG Delivery System v2 - Global Application JavaScript
 *
 * Configures:
 * 1. Global jQuery AJAX CSRF token header setup from <meta name="csrf-token">
 * 2. Global toast notification helper: showToast(message, type, title)
 * 3. Mobile sidebar toggle behavior and backdrop handlers
 * 4. Bootstrap tooltips, popovers, and auto-dismiss alerts
 * 5. Global confirmation handlers via data-confirm
 */

(function (window, $) {
    'use strict';

    // =========================================================================
    // 1. AJAX CSRF Setup
    // =========================================================================
    function initCsrf() {
        const token = $('meta[name="csrf-token"]').attr('content');
        if (token && $) {
            $.ajaxSetup({
                headers: {
                    'X-CSRF-Token': token
                }
            });
        }
    }

    // =========================================================================
    // 2. Global Toast Notification Helper
    // =========================================================================
    window.showToast = function (message, type = 'info', title = '') {
        const normalizedType = (type === 'error') ? 'danger' : type;
        const iconMap = {
            success: 'bi-check-circle-fill text-success',
            danger:  'bi-exclamation-triangle-fill text-danger',
            warning: 'bi-exclamation-circle-fill text-warning',
            info:    'bi-info-circle-fill text-primary'
        };

        const titleMap = {
            success: 'Success',
            danger:  'Error',
            warning: 'Warning',
            info:    'Notification'
        };

        const iconClass = iconMap[normalizedType] || iconMap.info;
        const displayTitle = title || titleMap[normalizedType] || 'Notification';

        let $container = $('#toastContainer');
        if ($container.length === 0) {
            $container = $('<div id="toastContainer" class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1090;"></div>');
            $('body').append($container);
        }

        const toastId = 'toast_' + Math.random().toString(36).substring(2, 9);
        const toastHtml = `
            <div id="${toastId}" class="toast border-0 shadow-lg" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="toast-header bg-white border-bottom">
                    <i class="bi ${iconClass} me-2 fs-6"></i>
                    <strong class="me-auto text-dark">${escapeHtml(displayTitle)}</strong>
                    <small class="text-muted">Just now</small>
                    <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
                <div class="toast-body bg-white text-dark py-3">
                    ${escapeHtml(message)}
                </div>
            </div>
        `;

        const $toastElement = $(toastHtml);
        $container.append($toastElement);

        if (window.bootstrap && window.bootstrap.Toast) {
            const toastInstance = new window.bootstrap.Toast($toastElement[0], {
                delay: 4500,
                autohide: true
            });

            $toastElement.on('hidden.bs.toast', function () {
                $(this).remove();
            });

            toastInstance.show();
        } else {
            // Fallback if bootstrap JS is still loading
            $toastElement.fadeIn().delay(4000).fadeOut(function () {
                $(this).remove();
            });
        }
    };

    // Helper for escaping HTML strings
    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // =========================================================================
    // 3. Sidebar Responsive Drawer
    // =========================================================================
    function initSidebar() {
        const $body = $('body');
        const $sidebarToggle = $('#sidebarToggle');
        const $sidebarBackdrop = $('#sidebarBackdrop');

        // Toggle button click
        $sidebarToggle.on('click', function (e) {
            e.preventDefault();
            $body.toggleClass('sidebar-open');
        });

        // Backdrop click closes sidebar
        $sidebarBackdrop.on('click', function () {
            $body.removeClass('sidebar-open');
        });

        // Close sidebar when clicking links on mobile
        $('.app-sidebar .nav-link').on('click', function () {
            if ($(window).width() < 768) {
                $body.removeClass('sidebar-open');
            }
        });

        // Close on ESC key
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && $body.hasClass('sidebar-open')) {
                $body.removeClass('sidebar-open');
            }
        });
    }

    // =========================================================================
    // 4. Global Confirmation Handlers
    // =========================================================================
    function initConfirmations() {
        $(document).on('click', '[data-confirm]', function (e) {
            const message = $(this).attr('data-confirm') || 'Are you sure you want to perform this action?';
            if (!window.confirm(message)) {
                e.preventDefault();
                e.stopImmediatePropagation();
                return false;
            }
        });
    }

    // =========================================================================
    // 5. Bootstrap Tooltips & Auto-dismiss Alerts
    // =========================================================================
    function initBootstrapComponents() {
        // Initialize Tooltips
        if (window.bootstrap && window.bootstrap.Tooltip) {
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.forEach(function (el) {
                new window.bootstrap.Tooltip(el);
            });
        }

        // Auto dismiss flash alerts after 6 seconds
        setTimeout(function () {
            $('.app-flash-alert').fadeOut(400, function () {
                $(this).remove();
            });
        }, 6000);
    }

    // =========================================================================
    // DOM Ready Initialization
    // =========================================================================
    $(function () {
        initCsrf();
        initSidebar();
        initConfirmations();
        initBootstrapComponents();
    });

})(window, window.jQuery);
