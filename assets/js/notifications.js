/**
 * LPG Delivery System v2 - Global Notifications
 *
 * Polls api/notifications.php for new notifications and:
 * 1. Renders a live toast popup for each new notification
 * 2. Maintains the navbar bell badge (unread count)
 * 3. Populates the bell dropdown with recent notifications
 *
 * The "last seen" id is kept in localStorage so old history is never
 * replayed on subsequent page loads, while unread state remains server-side
 * (badge clears when the user opens the bell or clicks "Mark all as read").
 */
(function (window, $) {
    'use strict';

    $(function () {
        const $userIdMeta = $('meta[name="user-id"]');
        if (!$userIdMeta.length) return;

        const userId = parseInt($userIdMeta.attr('content'), 10) || 0;
        if (userId <= 0) return;

        const baseUrl = $('meta[name="base-url"]').attr('content') || '';
        const apiUrl = baseUrl + 'api/notifications.php';
        const seenKey = 'lpg_notif_seen_' + userId;
        const POLL_INTERVAL = 5000;

        let lastSeenId = parseInt(localStorage.getItem(seenKey) || '0', 10);
        let initialized = localStorage.getItem(seenKey) !== null;
        let polling = false;

        function esc(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function typeIcon(type) {
            const icons = {
                chat_message: 'bi-chat-left-text',
                order_assigned: 'bi-truck',
                order_delivered: 'bi-check-circle-fill'
            };
            return icons[type] || 'bi-bell-fill';
        }

        // Skip a live chat toast when the user is actively chatting on that
        // same order (the message is already visible inside the open panel).
        function chatPanelOpenFor(orderId) {
            const openOrder = document.body.getAttribute('data-chat-open-order');
            return !!openOrder && String(orderId || '') === openOrder;
        }

        function showPopup(n) {
            if (!window.showToast) return;
            if (n.type === 'chat_message' && chatPanelOpenFor(n.order_id)) return;
            window.showToast('info', n.message, n.title || 'Notification');
        }

        function setBadge(count) {
            count = parseInt(count, 10) || 0;
            const $badge = $('#notifBadge');
            if (!$badge.length) return;
            if (count > 0) {
                $badge.text(count > 99 ? '99+' : count).removeClass('d-none');
            } else {
                $badge.addClass('d-none');
            }
        }

        function loadRecent() {
            $.getJSON(apiUrl, { action: 'recent', limit: 15 }, function (res) {
                const $list = $('#notifList');
                if (!$list.length) return;

                if (!res || !res.success || !res.data || !res.data.length) {
                    $list.html('<div class="app-notif-empty text-center text-muted py-4 small">No notifications yet.</div>');
                    setBadge(0);
                    return;
                }

                const html = res.data.map(function (n) {
                    const link = (n.link ? baseUrl + '/' + String(n.link).replace(/^\//, '') : null);
                    const href = link || 'javascript:void(0);';
                    const unreadCls = n.is_read ? '' : ' app-notif-unread';
                    return '<a class="dropdown-item app-notif-item' + unreadCls + '" data-notif-id="' + n.id + '"' +
                        (link ? ' href="' + esc(link) + '"' : '') + '>' +
                        '<div class="d-flex gap-2">' +
                        '<i class="bi ' + typeIcon(n.type) + ' text-primary flex-shrink-0 mt-1"></i>' +
                        '<div class="min-w-0">' +
                        '<div class="small fw-semibold text-dark">' + esc(n.title) + '</div>' +
                        '<div class="small app-notif-msg">' + esc(n.message) + '</div>' +
                        '<div class="app-notif-time text-muted">' + esc(n.created_at) + '</div>' +
                        '</div></div></a>';
                }).join('');

                $list.html(html);
                setBadge(res.unread || 0);
            }).fail(function () {
                const $list = $('#notifList');
                if ($list.length) $list.html('<div class="text-center text-muted py-4 small">Could not load notifications.</div>');
            });
        }

        function markAllRead() {
            $.post(apiUrl, { action: 'mark_all_read', csrf_token: $('meta[name="csrf-token"]').attr('content') || '' })
                .done(function (res) {
                    if (res && res.success) setBadge(0);
                });
        }

        function poll() {
            if (polling) return;
            polling = true;

            $.getJSON(apiUrl, { action: 'poll', after_id: lastSeenId }, function (res) {
                if (!res || !res.success) return;

                const items = res.data || [];
                if (initialized) {
                    items.forEach(function (n) {
                        showPopup(n);
                    });
                }

                if (items.length) {
                    lastSeenId = parseInt(items[items.length - 1].id, 10) || lastSeenId;
                    localStorage.setItem(seenKey, String(lastSeenId));
                }

                setBadge(res.unread || 0);
            }).always(function () {
                polling = false;
            });
        }

        // ── Wire up the bell dropdown ────────────────────────────────────
        $('#notifBellBtn').on('show.bs.dropdown', function () {
            loadRecent();
            markAllRead();
        });

        $('#notifMarkAllBtn').on('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            markAllRead();
            $('.app-notif-item', '#notifList').removeClass('app-notif-unread');
        });

        // Clicking a notification navigates to its target.
        $(document).on('click', '.app-notif-item', function (e) {
            const href = $(this).attr('href');
            if (href && href !== '#' && href.indexOf('javascript:') !== 0) {
                return; // default anchor navigation proceeds
            }
            e.preventDefault();
        });

        // ── Bootstrap: baseline on first visit, then start polling ──────
        if (initialized) {
            poll();
            setInterval(poll, POLL_INTERVAL);
        } else {
            // First visit on this device: snapshot the latest id without
            // replaying old notifications as toasts.
            $.getJSON(apiUrl, { action: 'recent', limit: 1 }, function (res) {
                if (res && res.success && res.data && res.data.length) {
                    lastSeenId = parseInt(res.data[0].id, 10) || 0;
                }
            }).always(function () {
                initialized = true;
                localStorage.setItem(seenKey, String(lastSeenId));
                poll();
                setInterval(poll, POLL_INTERVAL);
            });
        }

        // Refresh immediately when the tab regains focus (covers background tabs).
        $(document).on('visibilitychange', function () {
            if (!document.hidden) poll();
        });
    });

})(window, window.jQuery);