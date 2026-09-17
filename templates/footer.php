<?php
/**
 * Main Layout Footer
 * LPG Delivery System v2
 *
 * Closes the main layout container, includes global libraries (jQuery 3.7.1, Bootstrap 5.3.3 Bundle),
 * custom app JS, page-specific JS, and renders the toast notification container.
 */

$appName = defined('APP_NAME') ? APP_NAME : 'LPG Delivery System';
?>
            </main>

            <!-- Sticky/Clean App Footer -->
            <footer class="app-footer py-3 bg-white border-top text-center text-muted small mt-auto">
                <div class="container-fluid px-3">
                    <span>&copy; <?= date('Y') ?> <strong><?= e($appName) ?></strong>. Secure LPG Ordering & Delivery Management.</span>
                </div>
            </footer>
        </div>
    </div>

    <!-- Shared Confirmation Modal -->
    <?php require_once __DIR__ . '/components/confirmation-modal.php'; ?>

    <!-- Bootstrap 5 Toast Notification Container -->
    <div class="toast-container position-fixed bottom-0 end-0 p-3" id="toastContainer" style="z-index: 1090;"></div>

    <!-- Logout Transition Overlay ("Logging out..." loading screen) -->
    <div id="logoutOverlay" class="logout-overlay" aria-hidden="true">
        <div class="logout-overlay-content">
            <div class="logout-overlay-logo"><i class="bi bi-fire"></i></div>
            <div class="logout-spinner" role="status" aria-label="Logging out"></div>
            <div class="logout-overlay-text">Logging out<span class="logout-dots"></span></div>
            <small>See you soon!</small>
        </div>
    </div>

    <!-- Theme Toggle Script (runs immediately to prevent flash) -->
    <script>
    (function() {
        var saved = localStorage.getItem('app-theme');
        if (saved === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
    })();
    </script>

    <!-- jQuery 3.7.1 CDN -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>

    <!-- Bootstrap 5.3.3 JS Bundle CDN (includes Popper) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>

    <!-- Leaflet Map JS CDN -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

    <!-- AOS.js CDN (Animate On Scroll) -->
    <script src="https://unpkg.com/aos@2.3.4/dist/aos.js"></script>

    <!-- Global Application JS -->
    <script src="<?= asset_v('assets/js/app.js') ?>"></script>

    <!-- Session flash -> popup toast (e.g. "Stock updated for 'Phoenix 50kg'")
         so every save/edit/toggle across the app pops a notification on arrival -->
    <script>
    (function() {
        function popFlash() {
            var el = document.querySelector('.app-flash-alert');
            if (!el || !window.showToast) return;
            var type = el.getAttribute('data-flash-type') || 'info';
            var msg = el.getAttribute('data-flash-message') || '';
            if (!msg) return;
            var titles = { success: 'Updated', danger: 'Error', warning: 'Warning', info: 'Notice' };
            window.showToast(msg, type, titles[type] || 'Notice');
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', popFlash);
        } else {
            popFlash();
        }
    })();
    </script>

    <!-- Theme Toggle Handler -->
    <script>
    (function() {
        var toggle = document.getElementById('themeToggle');
        if (!toggle) return;

        function isDark() { return document.documentElement.getAttribute('data-theme') === 'dark'; }

        function updateIcon() {
            var icon = toggle.querySelector('i');
            if (isDark()) {
                icon.classList.remove('bi-moon-fill');
                icon.classList.add('bi-sun-fill');
            } else {
                icon.classList.remove('bi-sun-fill');
                icon.classList.add('bi-moon-fill');
            }
        }

        toggle.addEventListener('click', function() {
            if (isDark()) {
                document.documentElement.removeAttribute('data-theme');
                localStorage.setItem('app-theme', 'light');
            } else {
                document.documentElement.setAttribute('data-theme', 'dark');
                localStorage.setItem('app-theme', 'dark');
            }
            updateIcon();
        });

        updateIcon();
    })();
    </script>

    <!-- Shared Map & Chat Modules (AppMaps / AppChat) -->
    <script src="<?= asset_v('assets/js/shared-maps.js') ?>"></script>

    <!-- Page Specific JS (if defined) -->
    <?php if (!empty($page_js)): ?>
        <script src="<?= asset_v('assets/js/' . $page_js) ?>"></script>
    <?php endif; ?>

</body>
</html>
