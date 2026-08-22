/**
 * LPG Delivery System v2 - Shared Map & Chat Modules
 *
 * Single source of truth for behavior previously duplicated across
 * customer.js / rider.js / admin.js:
 *
 * AppMaps
 *   - createMap()            Leaflet map + OSM tiles
 *   - icons                  customer / rider / pin div markers
 *   - resolveDestination()   saved order coordinates from data attributes
 *   - legacy*Coord()         simulated fallbacks for orders without saved pins
 *   - geocode()              Nominatim address lookup
 *   - initPinLocationMaps()  static "Exact Location Pin" maps (.pin-location-map)
 *   - startLiveTracking()    universal live rider tracking engine
 *                            (GPS polling -> marker/route updates -> arrival state,
 *                             with simulated approach until the first real fix)
 *
 * AppChat
 *   - initPanel()            rider-customer chat offcanvas panel
 *
 * Requires: jQuery, Leaflet (both loaded globally via templates/footer.php).
 */

(function (window, $) {
    'use strict';

    const TILE_URL = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
    const TILE_ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';
    const DEFAULT_CENTER = [14.5995, 120.9842];

    function escapeHtml(text) {
        return $('<div>').text(text || '').html();
    }

    window.AppMaps = {
        DEFAULT_CENTER: DEFAULT_CENTER,

        /** HTML-escape helper shared with page scripts. */
        escapeHtml: escapeHtml,

        /**
         * Create a Leaflet map with OSM tiles.
         * @param {string|HTMLElement} container
         * @param {object} leafletOpts options passed straight to L.map
         * @returns {L.Map|null}
         */
        createMap: function (container, leafletOpts) {
            if (typeof window.L === 'undefined') return null;
            const map = L.map(container, leafletOpts || { zoomControl: true });
            L.tileLayer(TILE_URL, {
                maxZoom: 19,
                attribution: TILE_ATTRIBUTION
            }).addTo(map);
            return map;
        },

        icons: {
            /**
             * Red circular customer destination marker.
             * @param {number} size pixel size (34 or 36 in existing pages)
             */
            customer: function (size) {
                size = size || 34;
                return L.divIcon({
                    className: 'customer-marker-wrapper',
                    html: '<div class="customer-marker-icon" style="width:' + size + 'px;height:' + size + 'px;background:#ef4444;color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;border:2px solid #fff;box-shadow:0 3px 6px rgba(0,0,0,0.3);font-size:' + Math.round(size * 0.47) + 'px;">📍</div>',
                    iconSize: [size, size],
                    iconAnchor: [size / 2, size],
                    popupAnchor: [0, -(size - 4)]
                });
            },

            /**
             * Blue circular rider position marker.
             * @param {number} size pixel size (36 customer pages, 40 rider page)
             * @param {string} emoji glyph (🚴 or 🛵)
             */
            rider: function (size, emoji) {
                size = size || 36;
                emoji = emoji || '🚴';
                return L.divIcon({
                    className: 'rider-marker-wrapper',
                    html: '<div class="rider-marker-icon" style="width:' + size + 'px;height:' + size + 'px;background:#2563eb;color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;border:2px solid #fff;box-shadow:0 3px 6px rgba(0,0,0,0.3);font-size:' + Math.round(size * 0.5) + 'px;">' + emoji + '</div>',
                    iconSize: [size, size],
                    iconAnchor: [size / 2, size / 2],
                    popupAnchor: [0, -(size / 2 + 2)]
                });
            },

            /** Red pin marker used by static pin maps & checkout picker. */
            pin: function () {
                return L.divIcon({
                    className: 'pin-marker-wrapper',
                    html: '<div style="width:32px;height:32px;background:#ef4444;color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid #fff;box-shadow:0 3px 8px rgba(0,0,0,0.35);font-size:15px;">📍</div>',
                    iconSize: [32, 32],
                    iconAnchor: [16, 32],
                    popupAnchor: [0, -28]
                });
            }
        },

        /**
         * Parse saved delivery coordinates.
         * @returns {Array|null} [lat, lng] or null when absent/invalid
         */
        resolveDestination: function (lat, lng) {
            const la = parseFloat(lat);
            const ln = parseFloat(lng);
            if (!isNaN(la) && !isNaN(ln)) {
                return [la, ln];
            }
            return null;
        },

        /** Simulated destination for legacy orders (customer pages formula). */
        legacyDest: function (orderId) {
            const offset = (orderId % 10) * 0.004;
            return [14.6091 + offset, 120.9822 + offset];
        },

        /** Simulated rider start for legacy orders (customer pages formula). */
        legacyRider: function (orderId) {
            const offset = (orderId % 10) * 0.004;
            return [14.5950 + offset, 120.9680 + offset];
        },

        /** Simulated destination for legacy orders (rider page formula). */
        riderLegacyDest: function (orderId) {
            const offset = (orderId % 10) * 0.005;
            return [14.5995 + offset, 120.9842 + offset];
        },

        /** Simulated rider start for legacy orders (rider page formula). */
        riderLegacyRider: function (orderId) {
            const offset = (orderId % 10) * 0.005;
            return [14.5850 + offset, 120.9750 + offset];
        },

        /**
         * Geocode an address via Nominatim.
         * @returns {Promise-like} resolves {lat, lng} or null (never rejects)
         */
        geocode: function (address) {
            return $.ajax({
                url: 'https://nominatim.openstreetmap.org/search',
                type: 'GET',
                data: { q: address, format: 'json', limit: 1 },
                headers: { 'Accept-Language': 'en' },
                dataType: 'json'
            }).then(function (results) {
                if (results && results.length > 0) {
                    return { lat: parseFloat(results[0].lat), lng: parseFloat(results[0].lon) };
                }
                return null;
            }, function () {
                return null;
            });
        },

        /**
         * Initialize all static ".pin-location-map" elements:
         * geocode their data-address and drop an exact pin.
         */
        initPinLocationMaps: function () {
            if (typeof window.L === 'undefined') return;

            $('.pin-location-map').each(function () {
                const $el = $(this);
                if ($el.data('pin-initialized')) return;
                $el.data('pin-initialized', true);

                const address = $el.data('address');
                if (!address) return;

                const map = AppMaps.createMap(this, {
                    zoomControl: true,
                    scrollWheelZoom: false,
                    dragging: true
                }).setView(DEFAULT_CENTER, 15);

                $.ajax({
                    url: 'https://nominatim.openstreetmap.org/search',
                    type: 'GET',
                    data: { q: address, format: 'json', limit: 1 },
                    headers: { 'Accept-Language': 'en' },
                    dataType: 'json',
                    success: function (results) {
                        if (results && results.length > 0) {
                            const lat = parseFloat(results[0].lat);
                            const lng = parseFloat(results[0].lon);
                            map.setView([lat, lng], 17);
                            L.marker([lat, lng], { icon: AppMaps.icons.pin() })
                                .addTo(map)
                                .bindPopup('<strong>' + escapeHtml(address) + '</strong>')
                                .openPopup();
                        } else {
                            map.setView(DEFAULT_CENTER, 15);
                            L.marker(DEFAULT_CENTER, { icon: AppMaps.icons.pin() })
                                .addTo(map)
                                .bindPopup('<strong>' + escapeHtml(address) + '</strong><br><em class="text-muted small">Exact location not found on map</em>')
                                .openPopup();
                        }
                    },
                    error: function () {
                        map.setView(DEFAULT_CENTER, 15);
                        L.marker(DEFAULT_CENTER, { icon: AppMaps.icons.pin() })
                            .addTo(map)
                            .bindPopup('<strong>' + escapeHtml(address) + '</strong><br><em class="text-muted small">Map lookup unavailable</em>')
                            .openPopup();
                    }
                });
            });
        },

        /**
         * Universal live rider tracking engine.
         *
         * Polls api/location.php?action=get for the rider's real GPS fix and moves
         * the rider marker + route line accordingly. Until the first real fix
         * arrives, the rider marker advances toward the destination with a
         * simulated approach animation (legacy behavior).
         *
         * @param {object} config
         *   map               {L.Map} required
         *   orderId           {number} required
         *   customerCoord     {[lat,lng]} required destination
         *   riderStart        {[lat,lng]} required initial rider position
         *   riderMarker       {L.Marker} required
         *   routeLine         {L.Polyline} required
         *   riderName         {string} label used in popups/status
         *   fitPadding        {[x,y]} fitBounds padding (default [40,40])
         *   pollMs            polling interval (default 4000)
         *   initialPollDelayMs first poll delay (default 500)
         *   simulateTickMs    simulation tick interval (default 1200)
         *   simulateStep      fraction of remaining distance per tick (default 0.045)
         *   statusEl          {jQuery} optional element receiving enroute/arrived text
         *   texts             {enroute(name,pct), arrived(), arrivedPopup(name)}
         *   onRealFix()       optional callback on first real GPS fix
         *   onArrive()        optional extra arrival UI hook
         *   onTick(html)      optional custom per-tick renderer (used instead of statusEl)
         * @returns {{stop: Function}} handle
         */
        startLiveTracking: function (config) {
            const map = config.map;
            if (!map || typeof window.L === 'undefined') return { stop: function () {} };

            const customerCoord = config.customerCoord;
            let riderCoord = config.riderCoord || config.riderStart;
            const riderMarker = config.riderMarker;
            const routeLine = config.routeLine;
            const padding = config.fitPadding || [40, 40];
            const pollMs = config.pollMs || 4000;
            const initialDelayMs = (config.initialPollDelayMs == null) ? 500 : config.initialPollDelayMs;
            const tickMs = config.simulateTickMs || 1200;
            const stepRatio = config.simulateStep || 0.045;

            const texts = $.extend({
                enroute: function (name, pct) {
                    return '<i class="bi bi-bicycle me-1"></i>' + escapeHtml(name) + ' is on the way (' + pct + '% arrived)';
                },
                arrived: function () {
                    return '<i class="bi bi-check-circle-fill me-1"></i>Rider has arrived at your location!';
                },
                arrivedPopup: function (name) {
                    return '<strong>✅ Rider has arrived!</strong><br>' + escapeHtml(name) + ' is at your doorstep.';
                }
            }, config.texts || {});

            const $statusEl = config.statusEl || null;
            let hasRealLocation = false;
            let stopped = false;
            let pollIntervalId = null;
            let simulateIntervalId = null;

            function setRider(coord) {
                riderCoord = coord;
                riderMarker.setLatLng(riderCoord);
                routeLine.setLatLngs([riderCoord, customerCoord]);
            }

            function renderStatus(html) {
                if ($statusEl && $statusEl.length) {
                    $statusEl.html(html);
                } else if (config.onTick) {
                    config.onTick(html);
                }
            }

            function pollOnce() {
                if (stopped) return;
                const baseUrl = $('meta[name="base-url"]').attr('content') || '';
                $.ajax({
                    url: baseUrl + 'api/location.php',
                    type: 'GET',
                    data: { action: 'get', order_id: config.orderId },
                    dataType: 'json',
                    success: function (res) {
                        if (stopped || !res || !res.success || !res.data) return;
                        hasRealLocation = true;
                        setRider([parseFloat(res.data.latitude), parseFloat(res.data.longitude)]);
                        map.fitBounds(L.latLngBounds([riderCoord, customerCoord]), { padding: padding });
                        if (config.onRealFix) config.onRealFix();
                    }
                });
            }

            pollIntervalId = setInterval(pollOnce, pollMs);
            setTimeout(pollOnce, initialDelayMs);

            simulateIntervalId = setInterval(function () {
                if (stopped || hasRealLocation) return;

                const latDiff = customerCoord[0] - riderCoord[0];
                const lngDiff = customerCoord[1] - riderCoord[1];
                const distanceRemaining = Math.sqrt(latDiff * latDiff + lngDiff * lngDiff);

                if (distanceRemaining < 0.0006) {
                    stop();
                    setRider([customerCoord[0], customerCoord[1]]);
                    riderMarker.bindPopup(texts.arrivedPopup(config.riderName)).openPopup();
                    if ($statusEl && $statusEl.length) {
                        $statusEl.removeClass('text-primary').addClass('text-success fw-bold').html(texts.arrived());
                    }
                    if (config.onArrive) config.onArrive();
                    return;
                }

                riderCoord = [riderCoord[0] + latDiff * stepRatio, riderCoord[1] + lngDiff * stepRatio];
                riderMarker.setLatLng(riderCoord);
                routeLine.setLatLngs([riderCoord, customerCoord]);

                const progressPercent = Math.min(95, Math.round((1 - (distanceRemaining / 0.022)) * 100));
                renderStatus(texts.enroute(config.riderName, Math.max(5, progressPercent)));
            }, tickMs);

            function stop() {
                stopped = true;
                if (pollIntervalId) {
                    clearInterval(pollIntervalId);
                    pollIntervalId = null;
                }
                if (simulateIntervalId) {
                    clearInterval(simulateIntervalId);
                    simulateIntervalId = null;
                }
            }

            return { stop: stop, setRider: setRider };
        }
    };

    /**
     * Rider-Customer chat offcanvas panel.
     * Shared by customer & rider portals (identical API contract).
     *
     * @param {object} options
     *   emptyStateHint {string} hint shown when a conversation has no messages
     */
    window.AppChat = {
        initPanel: function (options) {
            options = options || {};
            const emptyStateHint = options.emptyStateHint || 'Start the conversation';

            const $chatPanel = $('#chatPanel');
            const $chatForm = $('#chatForm');
            const $chatInput = $('#chatInput');
            const $chatMessages = $('#chatMessages');
            const $chatLoading = $('#chatLoading');
            const $unreadBadge = $('#chatUnreadBadge');
            const $btnSend = $('#btnSendChat');
            if (!$chatForm.length || !$chatMessages.length) return;

            let chatPollInterval = null;
            let chatLastSeenId = 0;
            let chatIsOpen = false;

            const orderId = $chatForm.find('input[name="order_id"]').val();
            const csrfToken = $chatForm.find('input[name="csrf_token"]').val();
            const baseUrl = $('meta[name="base-url"]').attr('content') || '';
            const currentUserId = $('meta[name="user-id"]').length ? parseInt($('meta[name="user-id"]').attr('content'), 10) : 0;

            function getChatApiUrl() {
                return baseUrl + 'api/chat.php';
            }

            function formatChatTime(dateStr) {
                if (!dateStr) return '';
                const d = new Date(dateStr.replace(' ', 'T') + (dateStr.includes('+') ? '' : 'Z'));
                const now = new Date();
                const isToday = d.toDateString() === now.toDateString();
                const time = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                return isToday ? time : d.toLocaleDateString([], { month: 'short', day: 'numeric' }) + ' ' + time;
            }

            function renderChatMessage(msg) {
                const isSent = (currentUserId > 0 && parseInt(msg.sender_id, 10) === currentUserId);
                const bubbleClass = isSent ? 'chat-bubble-sent' : 'chat-bubble-received';
                const senderLabel = isSent ? 'You' : escapeHtml(msg.sender_name || (msg.sender_role === 'rider' ? 'Rider' : 'Customer'));
                const html = '<div class="chat-bubble ' + bubbleClass + '">'
                    + '<span class="chat-sender-label">' + senderLabel + '</span>'
                    + '<div class="chat-bubble-text">' + escapeHtml(msg.message) + '</div>'
                    + '<div class="chat-bubble-meta">' + formatChatTime(msg.created_at) + '</div>'
                    + '</div>';
                return html;
            }

            function scrollChatToBottom() {
                $chatMessages.scrollTop($chatMessages[0].scrollHeight);
            }

            function loadInitialMessages() {
                $.ajax({
                    url: getChatApiUrl(),
                    type: 'GET',
                    data: { action: 'poll', order_id: orderId, after_id: 0 },
                    dataType: 'json',
                    success: function (res) {
                        $chatLoading.hide();
                        if (res && res.success && res.data && res.data.length > 0) {
                            let html = '';
                            res.data.forEach(function (msg) {
                                html += renderChatMessage(msg);
                            });
                            $chatMessages.append(html);
                            chatLastSeenId = parseInt(res.data[res.data.length - 1].id, 10);
                        } else {
                            $chatMessages.append(
                                '<div class="chat-empty-state">'
                                + '<i class="bi bi-chat-left-text"></i>'
                                + '<div class="small fw-semibold">No messages yet</div>'
                                + '<div class="extra-small text-muted">' + escapeHtml(emptyStateHint) + '</div>'
                                + '</div>'
                            );
                        }
                        scrollChatToBottom();
                    },
                    error: function () {
                        $chatLoading.hide();
                        $chatMessages.append(
                            '<div class="chat-empty-state">'
                            + '<i class="bi bi-exclamation-circle"></i>'
                            + '<div class="small fw-semibold">Could not load messages</div>'
                            + '</div>'
                        );
                    }
                });
            }

            function pollNewMessages() {
                $.ajax({
                    url: getChatApiUrl(),
                    type: 'GET',
                    data: { action: 'poll', order_id: orderId, after_id: chatLastSeenId },
                    dataType: 'json',
                    success: function (res) {
                        if (res && res.success && res.data && res.data.length > 0) {
                            $chatMessages.find('.chat-empty-state').remove();
                            let html = '';
                            res.data.forEach(function (msg) {
                                html += renderChatMessage(msg);
                            });
                            $chatMessages.append(html);
                            chatLastSeenId = parseInt(res.data[res.data.length - 1].id, 10);
                            scrollChatToBottom();

                            if (!chatIsOpen) {
                                updateUnreadBadge();
                            }
                        }
                    }
                });
            }

            function updateUnreadBadge() {
                if (!$unreadBadge.length) return;
                $.ajax({
                    url: getChatApiUrl(),
                    type: 'GET',
                    data: { action: 'unread', order_id: orderId, last_seen_id: chatLastSeenId },
                    dataType: 'json',
                    success: function (res) {
                        if (res && res.success && res.data) {
                            const count = parseInt(res.data.unread, 10) || 0;
                            if (count > 0) {
                                $unreadBadge.text(count > 99 ? '99+' : count).removeClass('d-none');
                            } else {
                                $unreadBadge.addClass('d-none');
                            }
                        }
                    }
                });
            }

            function sendMessage() {
                const message = $.trim($chatInput.val());
                if (!message) return;

                $btnSend.prop('disabled', true);

                $.ajax({
                    url: getChatApiUrl(),
                    type: 'POST',
                    data: {
                        action: 'send',
                        order_id: orderId,
                        message: message,
                        csrf_token: csrfToken
                    },
                    dataType: 'json',
                    success: function (res) {
                        if (res && res.success) {
                            $chatInput.val('');
                            pollNewMessages();
                        } else if (res && res.error) {
                            if (window.showToast) window.showToast(res.error, 'error');
                        }
                    },
                    error: function () {
                        if (window.showToast) window.showToast('Failed to send message.', 'error');
                    },
                    complete: function () {
                        $btnSend.prop('disabled', false);
                        $chatInput.focus();
                    }
                });
            }

            $chatForm.on('submit', function (e) {
                e.preventDefault();
                sendMessage();
            });

            $chatPanel.on('show.bs.offcanvas', function () {
                chatIsOpen = true;
                $unreadBadge.addClass('d-none');
                $chatMessages.empty();
                $chatLoading.show();
                loadInitialMessages();
                chatPollInterval = setInterval(pollNewMessages, 3000);
            });

            $chatPanel.on('hide.bs.offcanvas', function () {
                chatIsOpen = false;
                if (chatPollInterval) {
                    clearInterval(chatPollInterval);
                    chatPollInterval = null;
                }
            });
        }
    };

})(window, window.jQuery);
