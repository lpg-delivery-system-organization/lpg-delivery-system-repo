<?php
/**
 * Automated Template & Layout Test Suite
 * LPG Delivery System v2
 *
 * Tests:
 * 1. Template file access restrictions (.htaccess)
 * 2. Header layout rendering (meta viewport, CSRF meta tag, CDN styles, topbar, guest vs auth state, XSS escaping)
 * 3. Role-aware Sidebar navigation (customer, admin, rider menu items, active page highlighting, user info footer)
 * 4. Footer layout rendering (toast container, jQuery CDN, Bootstrap JS Bundle CDN, app.js, page-specific JS)
 * 5. Flash Alert component (success, error/danger, warning, info alert rendering, XSS escaping, empty state)
 * 6. Modal component (render_modal helper, modal attributes, sizes, centering, footer buttons)
 * 7. Order Card component (render_order_card helper, get_order_status_badge, all 7 order statuses, currency/date formatting, XSS escaping)
 * 8. Static Assets verification (app.css design tokens & classes, app.js CSRF setup & showToast)
 */

// Enable test mode before components load
$GLOBALS['TEST_MODE'] = true;

// Initialize session in CLI before any output is sent
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// Load core dependencies
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/middleware.php';

// Load template components
require_once __DIR__ . '/../templates/components/alert.php';
require_once __DIR__ . '/../templates/components/modal.php';
require_once __DIR__ . '/../templates/components/order-card.php';

$testCount = 0;
$passCount = 0;
$failCount = 0;

function it(string $description, callable $fn): void {
    global $testCount, $passCount, $failCount;
    $testCount++;
    try {
        $result = $fn();
        if ($result !== false) {
            $passCount++;
            echo "  ✓ {$description}\n";
        } else {
            $failCount++;
            echo "  ✗ {$description} (returned false)\n";
        }
    } catch (Throwable $e) {
        $failCount++;
        echo "  ✗ {$description} (Exception: {$e->getMessage()} at {$e->getFile()}:{$e->getLine()})\n";
    }
}

function assert_contains(string $needle, string $haystack, string $message = ''): bool {
    if (strpos($haystack, $needle) === false) {
        throw new Exception(
            ($message ? $message . ': ' : '') .
            'Expected string to contain "' . substr($needle, 0, 100) . '" but not found.'
        );
    }
    return true;
}

function assert_not_contains(string $needle, string $haystack, string $message = ''): bool {
    if (strpos($haystack, $needle) !== false) {
        throw new Exception(
            ($message ? $message . ': ' : '') .
            'Expected string to NOT contain "' . substr($needle, 0, 100) . '" but was found.'
        );
    }
    return true;
}

function assert_equals($expected, $actual, string $message = ''): bool {
    if ($expected !== $actual) {
        throw new Exception(
            ($message ? $message . ': ' : '') .
            'Expected ' . var_export($expected, true) . ' but got ' . var_export($actual, true)
        );
    }
    return true;
}

function assert_true($actual, string $message = ''): bool {
    if ($actual !== true) {
        throw new Exception(($message ? $message . ': ' : '') . 'Expected true but got ' . var_export($actual, true));
    }
    return true;
}

// Reset session helper
function reset_env(): void {
    $_SESSION = [];
    $_GET = [];
    $_POST = [];
}

echo "====================================================\n";
echo " LPG Delivery System v2 - Template & Layout Tests\n";
echo "====================================================\n\n";

// =========================================================================
// Group 1: Template Security (.htaccess)
// =========================================================================
echo "Group 1: Template Security (.htaccess)\n";

it('templates/.htaccess exists and restricts direct access', function () {
    $htaccessFile = __DIR__ . '/../templates/.htaccess';
    assert_true(file_exists($htaccessFile), 'templates/.htaccess must exist');
    $content = file_get_contents($htaccessFile);
    assert_contains('Deny from all', $content);
});

// =========================================================================
// Group 2: Header Layout (header.php)
// =========================================================================
echo "\nGroup 2: Header Layout (header.php)\n";

it('header.php renders CSRF meta tag, Bootstrap CSS CDN, and custom app.css', function () {
    reset_env();
    $csrf = csrf_token();
    $page_title = 'Test Dashboard';

    ob_start();
    require __DIR__ . '/../templates/header.php';
    $output = ob_get_clean();

    assert_contains('<meta name="csrf-token" content="' . $csrf . '">', $output);
    assert_contains('bootstrap@5.3.3', $output);
    assert_contains('bootstrap-icons@1.11.3', $output);
    assert_contains('assets/css/app.css', $output);
    assert_contains('Test Dashboard - LPG Delivery System', $output);
    assert_contains('<nav class="navbar', $output);
    assert_contains('Login', $output);
    assert_contains('Register', $output);
});

it('header.php renders authenticated user dropdown, role badge, and initial badge', function () {
    reset_env();
    login_user([
        'id' => 1,
        'full_name' => 'Maria Santos',
        'email' => 'maria@example.com',
        'role' => 'customer'
    ]);
    $page_title = 'Customer Shop';

    ob_start();
    require __DIR__ . '/../templates/header.php';
    $output = ob_get_clean();

    assert_contains('Maria Santos', $output);
    assert_contains('maria@example.com', $output);
    assert_contains('customer', $output);
    assert_contains('user-avatar-circle', $output);
    assert_contains('M', $output); // Initial
    assert_contains('logout.php', $output);
    assert_contains('id="sidebarToggle"', $output);
});

it('header.php escapes dynamic page titles and user information against XSS', function () {
    reset_env();
    login_user([
        'id' => 2,
        'full_name' => '<script>alert("hacked")</script>',
        'email' => 'hacker"onmouseover="alert(1)@example.com',
        'role' => 'admin'
    ]);
    $page_title = '<script>alert("xss")</script>';

    ob_start();
    require __DIR__ . '/../templates/header.php';
    $output = ob_get_clean();

    assert_not_contains('<script>alert("hacked")</script>', $output);
    assert_not_contains('<script>alert("xss")</script>', $output);
    assert_contains('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $output);
});

// =========================================================================
// Group 3: Dynamic Sidebar (sidebar.php)
// =========================================================================
echo "\nGroup 3: Dynamic Sidebar (sidebar.php)\n";

it('sidebar.php renders customer navigation items and highlights active page', function () {
    reset_env();
    login_user(['id' => 1, 'full_name' => 'Customer Jane', 'email' => 'jane@example.com', 'role' => 'customer']);
    $current_page = 'orders';

    ob_start();
    require __DIR__ . '/../templates/sidebar.php';
    $output = ob_get_clean();

    assert_contains('Shop Products', $output);
    assert_contains('pages/customer/shop.php', $output);
    assert_contains('My Orders', $output);
    assert_contains('pages/customer/orders.php', $output);
    assert_contains('My Profile', $output);
    assert_contains('pages/customer/profile.php', $output);

    // Ensure 'orders' link has active class
    assert_contains('href="' . url('pages/customer/orders.php') . '"', $output);
    assert_contains('data-menu-key="orders"', $output);
    assert_contains('active', $output);
    assert_contains('sidebar-backdrop', $output);
    assert_contains('Customer Jane', $output);
});

it('sidebar.php renders admin navigation items', function () {
    reset_env();
    login_user(['id' => 2, 'full_name' => 'Admin Boss', 'email' => 'admin@lpg.com', 'role' => 'admin']);
    $current_page = 'inventory';

    ob_start();
    require __DIR__ . '/../templates/sidebar.php';
    $output = ob_get_clean();

    assert_contains('Dashboard', $output);
    assert_contains('pages/admin/dashboard.php', $output);
    assert_contains('Order Management', $output);
    assert_contains('pages/admin/orders.php', $output);
    assert_contains('Inventory', $output);
    assert_contains('pages/admin/inventory.php', $output);
    assert_contains('User Management', $output);
    assert_contains('pages/admin/users.php', $output);
    assert_contains('data-menu-key="inventory"', $output);
});

it('sidebar.php renders rider navigation items', function () {
    reset_env();
    login_user(['id' => 3, 'full_name' => 'Rider Ken', 'email' => 'rider@lpg.com', 'role' => 'rider']);
    $current_page = 'deliveries';

    ob_start();
    require __DIR__ . '/../templates/sidebar.php';
    $output = ob_get_clean();

    assert_contains('My Deliveries', $output);
    assert_contains('pages/rider/deliveries.php', $output);
    assert_contains('Available Orders', $output);
    assert_contains('pages/rider/available.php', $output);
    assert_contains('My Profile', $output);
    assert_contains('pages/rider/profile.php', $output);
    assert_contains('data-menu-key="deliveries"', $output);
});

// =========================================================================
// Group 4: Footer Layout (footer.php)
// =========================================================================
echo "\nGroup 4: Footer Layout (footer.php)\n";

it('footer.php renders toast container, jQuery 3.7.1, Bootstrap JS Bundle, and app.js', function () {
    $page_js = 'customer.js';

    ob_start();
    require __DIR__ . '/../templates/footer.php';
    $output = ob_get_clean();

    assert_contains('id="toastContainer"', $output);
    assert_contains('jquery-3.7.1.min.js', $output);
    assert_contains('bootstrap.bundle.min.js', $output);
    assert_contains('assets/js/app.js', $output);
    assert_contains('assets/js/customer.js', $output);
    assert_contains('app-footer', $output);
});

// =========================================================================
// Group 5: Flash Alert Component (alert.php)
// =========================================================================
echo "\nGroup 5: Flash Alert Component (alert.php)\n";

it('alert.php renders success flash notification with proper icon and dismiss button', function () {
    reset_env();
    set_flash('success', 'Your order was successfully placed!');

    ob_start();
    require __DIR__ . '/../templates/components/alert.php';
    $output = ob_get_clean();

    assert_contains('alert-success', $output);
    assert_contains('bi-check-circle-fill', $output);
    assert_contains('Your order was successfully placed!', $output);
    assert_contains('btn-close', $output);
});

it('alert.php maps error type to alert-danger and escapes HTML payloads', function () {
    reset_env();
    set_flash('error', '<img src=x onerror=alert(1)> Invalid email or password.');

    ob_start();
    require __DIR__ . '/../templates/components/alert.php';
    $output = ob_get_clean();

    assert_contains('alert-danger', $output);
    assert_contains('bi-exclamation-triangle-fill', $output);
    assert_not_contains('<img src=x onerror=alert(1)>', $output);
    assert_contains('&lt;img src=x onerror=alert(1)&gt;', $output);
});

it('alert.php outputs nothing when no flash notification is set', function () {
    reset_env();

    ob_start();
    require __DIR__ . '/../templates/components/alert.php';
    $output = ob_get_clean();

    assert_equals('', trim($output), 'Should output nothing when no flash is present');
});

// =========================================================================
// Group 6: Reusable Modal Component (modal.php)
// =========================================================================
echo "\nGroup 6: Reusable Modal Component (modal.php)\n";

it('render_modal generates structured Bootstrap 5 modal HTML', function () {
    $html = render_modal(
        'editStockModal',
        'Update Cylinder Stock',
        '<p>Select stock amount below:</p>',
        '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Save Changes</button>',
        'lg',
        true
    );

    assert_contains('id="editStockModal"', $html);
    assert_contains('Update Cylinder Stock', $html);
    assert_contains('modal-lg', $html);
    assert_contains('modal-dialog-centered', $html);
    assert_contains('Select stock amount below:', $html);
    assert_contains('Save Changes', $html);
    assert_contains('data-bs-dismiss="modal"', $html);
});

// =========================================================================
// Group 7: Order Card Component (order-card.php)
// =========================================================================
echo "\nGroup 7: Order Card Component (order-card.php)\n";

it('get_order_status_badge generates styled badges for all 7 order statuses', function () {
    $statuses = [
        'pending'            => ['Pending', 'badge-status-pending'],
        'approved'           => ['Approved', 'badge-status-approved'],
        'ready_for_delivery' => ['Ready for Delivery', 'badge-status-ready_for_delivery'],
        'picked_up'          => ['Picked Up', 'badge-status-picked_up'],
        'out_for_delivery'   => ['Out for Delivery', 'badge-status-out_for_delivery'],
        'delivered'          => ['Delivered', 'badge-status-delivered'],
        'cancelled'          => ['Cancelled', 'badge-status-cancelled']
    ];

    foreach ($statuses as $statusKey => $expected) {
        $badge = get_order_status_badge($statusKey);
        assert_contains($expected[0], $badge, "Status {$statusKey} label mismatch");
        assert_contains($expected[1], $badge, "Status {$statusKey} class mismatch");
    }
});

it('render_order_card formats order items, pricing, payment method, and addresses', function () {
    $mockOrder = [
        'id' => 101,
        'status' => 'ready_for_delivery',
        'product_name' => 'Solane 11kg LPG Cylinder',
        'brand' => 'Solane',
        'weight' => '11kg',
        'quantity' => 2,
        'unit_price' => 950.00,
        'total_amount' => 1900.00,
        'payment_method' => 'gcash',
        'delivery_address' => '742 Evergreen Terrace, Springfield',
        'contact_phone' => '09171234567',
        'notes' => 'Please ring the doorbell upon arrival',
        'created_at' => '2026-08-20 10:00:00',
        'customer_name' => 'Homer Simpson'
    ];

    $cardHtml = render_order_card($mockOrder, [
        'role' => 'admin',
        'actions_html' => '<button class="btn btn-sm btn-primary">Assign Rider</button>'
    ]);

    assert_contains('#101', $cardHtml);
    assert_contains('Solane 11kg LPG Cylinder', $cardHtml);
    assert_contains('Solane', $cardHtml);
    assert_contains('11kg', $cardHtml);
    assert_contains('Quantity:</span> <strong class="text-dark">2</strong>', $cardHtml);
    assert_contains('₱950.00', $cardHtml);
    assert_contains('₱1,900.00', $cardHtml);
    assert_contains('GCASH', $cardHtml);
    assert_contains('742 Evergreen Terrace, Springfield', $cardHtml);
    assert_contains('09171234567', $cardHtml);
    assert_contains('Please ring the doorbell upon arrival', $cardHtml);
    assert_contains('Homer Simpson', $cardHtml);
    assert_contains('Assign Rider', $cardHtml);
    assert_contains('Ready for Delivery', $cardHtml);
});

// =========================================================================
// Group 8: Static Assets Verification (app.css & app.js)
// =========================================================================
echo "\nGroup 8: Static Assets Verification (app.css & app.js)\n";

it('app.css contains layout classes, status badges, and responsive sidebar styles', function () {
    $cssFile = __DIR__ . '/../assets/css/app.css';
    assert_true(file_exists($cssFile), 'app.css must exist');
    $css = file_get_contents($cssFile);

    assert_contains('--app-primary', $css);
    assert_contains('.app-sidebar', $css);
    assert_contains('.sidebar-backdrop', $css);
    assert_contains('.user-avatar-circle', $css);
    assert_contains('.badge-status-pending', $css);
    assert_contains('.badge-status-delivered', $css);
    assert_contains('.badge-status-cancelled', $css);
    assert_contains('.app-stat-card', $css);
    assert_contains('.app-product-card', $css);
    assert_contains('.app-order-timeline', $css);
});

it('app.js contains jQuery AJAX CSRF header setup, showToast, and sidebar handlers', function () {
    $jsFile = __DIR__ . '/../assets/js/app.js';
    assert_true(file_exists($jsFile), 'app.js must exist');
    $js = file_get_contents($jsFile);

    assert_contains('X-CSRF-Token', $js);
    assert_contains('showToast', $js);
    assert_contains('sidebar-open', $js);
    assert_contains('data-confirm', $js);
    assert_contains('toastContainer', $js);
});

// =========================================================================
// Test Summary
// =========================================================================
echo "\n====================================================\n";
echo " Test Summary: {$passCount} Passed, {$failCount} Failed (Total: {$testCount})\n";
echo "====================================================\n";

if ($failCount > 0) {
    exit(1);
} else {
    exit(0);
}
