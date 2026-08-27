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
    // 4. Global Confirmation Modal System
    // =========================================================================
    /**
     * Reusable confirmation modal helper
     * options: {
     *   title: string,
     *   message: string,
     *   icon: string (e.g. 'bi-check-circle', 'bi-exclamation-triangle', 'bi-trash'),
     *   iconColor: string (e.g. 'text-primary', 'text-success', 'text-danger', 'text-warning'),
     *   iconBg: string (e.g. 'bg-primary-subtle', 'bg-success-subtle', 'bg-danger-subtle', 'bg-warning-subtle'),
     *   confirmText: string,
     *   confirmClass: string (e.g. 'btn-primary', 'btn-success', 'btn-danger'),
     *   onConfirm: function(closeModal)
     * }
     */
    window.confirmAction = function (options) {
        const modalEl = document.getElementById('globalConfirmModal');
        if (!modalEl) {
            if (window.confirm(options.message || 'Are you sure you want to proceed?')) {
                if (typeof options.onConfirm === 'function') options.onConfirm(function(){});
            }
            return;
        }

        const $modal = $(modalEl);
        const title = options.title || 'Confirm Action';
        const message = options.message || 'Are you sure you want to proceed?';
        const icon = options.icon || 'bi-question-circle';
        const iconColor = options.iconColor || 'text-primary';
        const iconBg = options.iconBg || 'bg-light';
        const confirmText = options.confirmText || 'Proceed';
        const confirmClass = options.confirmClass || 'btn-primary';

        $('#globalConfirmTitle').text(title);
        $('#globalConfirmMessage').html(message);
        $('#globalConfirmIconBox').attr('class', 'modal-confirm-icon-box mx-auto ' + iconBg + ' ' + iconColor);
        $('#globalConfirmIcon').attr('class', 'bi ' + icon);
        
        const $btnProceed = $('#globalConfirmProceedBtn');
        $btnProceed.text(confirmText).attr('class', 'btn px-4 fw-semibold shadow-sm ' + confirmClass).prop('disabled', false);

        const bsModal = window.bootstrap && window.bootstrap.Modal ? window.bootstrap.Modal.getOrCreateInstance(modalEl) : null;

        $btnProceed.off('click').on('click', function () {
            if (typeof options.onConfirm === 'function') {
                $btnProceed.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Processing...');
                options.onConfirm(function () {
                    if (bsModal) bsModal.hide();
                });
            } else {
                if (bsModal) bsModal.hide();
            }
        });

        if (bsModal) {
            bsModal.show();
        }
    };

    /**
     * Global AJAX Action Handler with optional confirmation modal
     */
    window.ajaxAction = function (options) {
        const doAjax = function (closeModal) {
            const url = options.url || window.location.href;
            const type = options.type || 'POST';
            const data = options.data || {};

            $.ajax({
                url: url,
                type: type,
                data: data,
                dataType: 'json',
                success: function (res) {
                    if (typeof closeModal === 'function') closeModal();
                    if (res && res.success) {
                        window.showToast(res.message || 'Action completed successfully.', 'success');
                        if (typeof options.onSuccess === 'function') {
                            options.onSuccess(res);
                        } else if (options.reload !== false) {
                            setTimeout(function() { window.location.reload(); }, 600);
                        }
                    } else {
                        const errMsg = (res && res.error) ? res.error : ((res && res.message) ? res.message : 'Action failed.');
                        window.showToast(errMsg, 'danger');
                        if (typeof options.onError === 'function') {
                            options.onError(res);
                        }
                    }
                },
                error: function (xhr) {
                    if (typeof closeModal === 'function') closeModal();
                    let errMsg = 'An unexpected server error occurred.';
                    try {
                        const parsed = JSON.parse(xhr.responseText);
                        if (parsed && (parsed.error || parsed.message)) {
                            errMsg = parsed.error || parsed.message;
                        }
                    } catch(e){}
                    window.showToast(errMsg, 'danger');
                    if (typeof options.onError === 'function') {
                        options.onError(xhr);
                    }
                }
            });
        };

        if (options.confirm) {
            window.confirmAction($.extend({}, options.confirm, {
                onConfirm: function (closeModal) {
                    doAjax(closeModal);
                }
            }));
        } else {
            doAjax();
        }
    };

    function initConfirmations() {
        // Native data-confirm attribute integration with global modal
        $(document).on('click', '[data-confirm]', function (e) {
            const $this = $(this);
            if ($this.data('confirmed')) {
                $this.removeData('confirmed');
                return true;
            }

            e.preventDefault();
            e.stopImmediatePropagation();

            const message = $this.attr('data-confirm') || 'Are you sure you want to perform this action?';
            const title = $this.attr('data-confirm-title') || 'Confirm Action';
            const icon = $this.attr('data-confirm-icon') || 'bi-exclamation-circle';
            const confirmClass = $this.attr('data-confirm-btn-class') || 'btn-primary';

            window.confirmAction({
                title: title,
                message: message,
                icon: icon,
                confirmClass: confirmClass,
                onConfirm: function (closeModal) {
                    closeModal();
                    $this.data('confirmed', true);
                    if ($this.is('button[type="submit"]') || $this.is('input[type="submit"]')) {
                        $this.closest('form').submit();
                    } else if ($this.is('a')) {
                        window.location.href = $this.attr('href');
                    } else {
                        $this.trigger('click');
                    }
                }
            });
            return false;
        });

        // Clickable table rows and card handlers
        $(document).on('click', '.app-clickable-row, .app-clickable-card', function (e) {
            if ($(e.target).closest('a, button, input, form, select, textarea, .dropdown-menu').length) {
                return;
            }
            const href = $(this).data('href') || $(this).attr('data-href');
            if (href) {
                window.location.href = href;
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
    // 6. AOS (Animate On Scroll) Initialization
    // =========================================================================
    function initAOS() {
        if (typeof AOS !== 'undefined') {
            AOS.init({
                duration: 500,
                easing: 'ease-out-cubic',
                once: true,
                offset: 30,
                disable: function () {
                    return window.innerWidth < 768;
                }
            });
        }
    }

    // =========================================================================
    // 7. Animated Stat Counters
    // =========================================================================
    function initAnimatedCounters() {
        const counters = document.querySelectorAll('[data-counter-target]');
        if (!counters.length) return;

        const observerCallback = function (entries, observer) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    const el = entry.target;
                    const target = parseInt(el.getAttribute('data-counter-target'), 10) || 0;
                    const duration = parseInt(el.getAttribute('data-counter-duration'), 10) || 1200;
                    animateCounter(el, target, duration);
                    observer.unobserve(el);
                }
            });
        };

        const observer = new IntersectionObserver(observerCallback, { threshold: 0.3 });
        counters.forEach(function (c) { observer.observe(c); });
    }

    function animateCounter(el, target, duration) {
        el.classList.add('counting');
        var startTime = null;
        var startValue = 0;

        function step(timestamp) {
            if (!startTime) startTime = timestamp;
            var progress = Math.min((timestamp - startTime) / duration, 1);
            var eased = 1 - Math.pow(1 - progress, 3); // ease-out cubic
            var current = Math.floor(eased * target);
            el.textContent = current.toLocaleString();
            if (progress < 1) {
                requestAnimationFrame(step);
            } else {
                el.textContent = target.toLocaleString();
                el.classList.remove('counting');
            }
        }

        requestAnimationFrame(step);
    }

    // =========================================================================
    // 8. Skeleton Loader Utility
    // =========================================================================
    function showSkeleton(targetSelector, type) {
        var $target = $(targetSelector);
        if (!$target.length) return;

        type = type || 'card';
        var count = parseInt($target.data('skeleton-count'), 10) || 4;
        var html = '';

        for (var i = 0; i < count; i++) {
            if (type === 'card') {
                html += '<div class="col-md-3 mb-3"><div class="app-stat-card p-3">' +
                    '<div class="skeleton skeleton-circle mb-3"></div>' +
                    '<div class="skeleton skeleton-title"></div>' +
                    '<div class="skeleton skeleton-text"></div>' +
                    '<div class="skeleton skeleton-text-sm"></div>' +
                    '</div></div>';
            } else if (type === 'row') {
                html += '<div class="app-order-card p-3 mb-3">' +
                    '<div class="d-flex align-items-center gap-3">' +
                    '<div class="skeleton skeleton-circle"></div>' +
                    '<div class="flex-grow-1">' +
                    '<div class="skeleton skeleton-text" style="width:70%"></div>' +
                    '<div class="skeleton skeleton-text-sm" style="width:50%"></div>' +
                    '</div></div></div>';
            } else if (type === 'list') {
                html += '<div class="app-order-card p-3 mb-2">' +
                    '<div class="d-flex justify-content-between align-items-center">' +
                    '<div class="skeleton skeleton-text" style="width:40%"></div>' +
                    '<div class="skeleton skeleton-text-sm" style="width:20%"></div>' +
                    '</div></div>';
            }
        }

        $target.html(html);
    }

    function hideSkeleton(targetSelector, realContent) {
        $(targetSelector).html(realContent);
    }

    // =========================================================================
    // 9. Enhanced Toast Notifications
    // =========================================================================
    function showToast(type, message, title) {
        type = type || 'info';
        title = title || type.charAt(0).toUpperCase() + type.slice(1);

        var iconMap = {
            success: 'bi-check-circle-fill',
            danger: 'bi-x-circle-fill',
            warning: 'bi-exclamation-triangle-fill',
            info: 'bi-info-circle-fill'
        };

        var toastId = 'app-toast-' + Date.now();
        var html = '<div id="' + toastId + '" class="toast align-items-center text-bg-' + type + ' border-0 shadow-lg toast-' + type + '" role="alert" aria-live="assertive" aria-atomic="true">' +
            '<div class="toast-header">' +
            '<i class="bi ' + (iconMap[type] || iconMap.info) + ' me-2 text-' + type + '"></i>' +
            '<strong class="me-auto">' + title + '</strong>' +
            '<button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>' +
            '</div>' +
            '<div class="toast-body">' + message + '</div>' +
            '<div class="toast-progress-bar"></div>' +
            '</div>';

        var $container = $('#app-toast-container');
        if (!$container.length) {
            $('body').append('<div id="app-toast-container" class="toast-container position-fixed top-0 end-0 p-3" style="z-index:9999;"></div>');
            $container = $('#app-toast-container');
        }

        $container.append(html);

        var $toast = $('#' + toastId);
        if (window.bootstrap && window.bootstrap.Toast) {
            var bsToast = new window.bootstrap.Toast($toast[0], { delay: 4500 });
            bsToast.show();
            $toast.on('hidden.bs.toast', function () { $(this).remove(); });
        } else if ($.fn.toast) {
            $toast.toast({ delay: 4500 }).toast('show');
            $toast.on('hidden.bs.toast', function () { $(this).remove(); });
        } else {
            setTimeout(function () { $toast.remove(); }, 5000);
        }
    }

    // =========================================================================
    // 10. Form Animations — Shake on Invalid, Focus Highlight
    // =========================================================================
    function initFormAnimations() {
        // Add shake class on invalid submission
        $(document).on('invalid', '.form-control, .form-select', function () {
            var $el = $(this);
            $el.addClass('form-shake');
            setTimeout(function () { $el.removeClass('form-shake'); }, 500);
        });

        // Floating label effect: add 'filled' class when input has value
        $(document).on('input change', '.form-control, .form-select', function () {
            var $input = $(this);
            if ($input.val()) {
                $input.addClass('filled');
            } else {
                $input.removeClass('filled');
            }
        });

        // Trigger initial state on page load
        $('.form-control, .form-select').each(function () {
            if ($(this).val()) {
                $(this).addClass('filled');
            }
        });
    }

    // =========================================================================
    // 11. Button Loading State Utility
    // =========================================================================
    function setBtnLoading($btn, loading) {
        if (loading) {
            $btn.addClass('btn-loading').attr('disabled', true);
        } else {
            $btn.removeClass('btn-loading').removeAttr('disabled');
        }
    }

    // =========================================================================
    // 12. Page Transition (optional: fade main content on navigation)
    // =========================================================================
    function initPageTransitions() {
        // Add page-load class to main content
        var $main = $('.app-main');
        if ($main.length) {
            $main.css('opacity', 0);
            setTimeout(function () { $main.css('opacity', 1); }, 50);
        }

        // Fade out on internal link clicks
        $(document).on('click', 'a.app-nav-link', function (e) {
            var href = $(this).attr('href');
            if (href && href !== '#' && !href.startsWith('javascript:') && !href.startsWith('mailto:')) {
                e.preventDefault();
                $main.css({ opacity: 0, transition: 'opacity 0.15s ease' });
                setTimeout(function () {
                    window.location.href = href;
                }, 150);
            }
        });
    }

    // =========================================================================
    // 13. Sidebar User Footer — Show on Scroll
    // =========================================================================
    function initSidebarFooterScroll() {
        var $footer = $('#sidebarUserFooter');
        if (!$footer.length) return;

        var $main = $('.app-main');
        var threshold = 50;

        function checkScroll() {
            var scrollTop = $(window).scrollTop();
            if (scrollTop > threshold) {
                $footer.slideDown(200);
            } else {
                $footer.slideUp(200);
            }
        }

        $(window).on('scroll', checkScroll);
        checkScroll();
    }

    // =========================================================================
    // 13. Sidebar Mobile Drawer
    // =========================================================================
    function initSidebar() {
        var $body = $('body');
        var $toggle = $('#sidebarToggle');
        var $backdrop = $('#sidebarBackdrop');

        if ($toggle.length) {
            $toggle.on('click', function (e) {
                e.preventDefault();
                $body.toggleClass('sidebar-open');
            });
        }

        if ($backdrop.length) {
            $backdrop.on('click', function () {
                $body.removeClass('sidebar-open');
            });
        }

        // Close sidebar on nav link click (mobile only)
        $('.app-sidebar .nav-link').on('click', function () {
            if ($(window).width() < 992) {
                $body.removeClass('sidebar-open');
            }
        });
    }

    function initSplash() {
        var $splash = $('#appSplash');
        if (!$splash.length) return;
        setTimeout(function () {
            $splash.addClass('fade-out');
            setTimeout(function () { $splash.remove(); }, 500);
        }, 2200);
    }

    // =========================================================================
    // DOM Ready Initialization
    // =========================================================================
    $(function () {
        initSplash();
        initCsrf();
        initSidebar();
        initConfirmations();
        initBootstrapComponents();
        initAOS();
        initAnimatedCounters();
        initFormAnimations();
        initPageTransitions();
        initSidebarFooterScroll();
    });

    // Expose utilities for inline usage
    window.AppUI = {
        showToast: showToast,
        setBtnLoading: setBtnLoading,
        showSkeleton: showSkeleton,
        hideSkeleton: hideSkeleton
    };

})(window, window.jQuery);
