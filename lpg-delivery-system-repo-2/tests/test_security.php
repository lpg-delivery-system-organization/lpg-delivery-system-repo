<?php
/**
 * Automated Security & Middleware Test Suite
 * LPG Delivery System v2
 *
 * Tests:
 * 1. Session Security & Lifecycle (init_session, strict mode, inactivity timeout)
 * 2. Authentication State Helpers (login_user, logout_user, is_logged_in, current_user, current_user_role, has_role)
 * 3. CSRF Protection (token generation, csrf_input, POST verification, header verification, timing-safe equality)
 * 4. Rate Limiting (sliding window, lockout threshold, attempt counting, reset)
 * 5. Middleware Guards (require_login, require_role, require_guest, require_csrf, web & AJAX handling)
 * 6. Helper Utilities & XSS Sanitization (e, url, set_flash, get_flash, has_flash, format_currency, format_date)
 * 7. Secure File Upload Validator (validate_id_upload with MIME verification, size limits, format restriction)
 */

// Enable test mode before components load
$GLOBALS['TEST_MODE'] = true;

// Initialize session in CLI before any output is sent
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// Load components
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/middleware.php';

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

function assert_false($actual, string $message = ''): bool {
    if ($actual !== false) {
        throw new Exception(($message ? $message . ': ' : '') . 'Expected false but got ' . var_export($actual, true));
    }
    return true;
}

function assert_not_empty($actual, string $message = ''): bool {
    if (empty($actual)) {
        throw new Exception(($message ? $message . ': ' : '') . 'Expected non-empty value but got ' . var_export($actual, true));
    }
    return true;
}

// Helper to reset request environment between test cases
function reset_test_env(): void {
    $_SESSION = [];
    $_POST = [];
    $_GET = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    unset(
        $_SERVER['HTTP_X_REQUESTED_WITH'],
        $_SERVER['HTTP_X_CSRF_TOKEN'],
        $_SERVER['HTTP_CSRF_TOKEN'],
        $_SERVER['HTTP_ACCEPT'],
        $_SERVER['CONTENT_TYPE']
    );
    unset($GLOBALS['LAST_HTTP_CODE'], $GLOBALS['LAST_REDIRECT'], $GLOBALS['LAST_RESPONSE']);
}

echo "====================================================\n";
echo " LPG Delivery System v2 - Security & Middleware Tests\n";
echo "====================================================\n\n";

// =========================================================================
// Group 1: Session Security & Authentication State
// =========================================================================
echo "Group 1: Session Security & Authentication State\n";

it('login_user establishes authenticated session and strips password', function () {
    reset_test_env();
    $mockUser = [
        'id' => 42,
        'full_name' => 'Juan Dela Cruz',
        'email' => 'juan@example.com',
        'role' => 'customer',
        'password' => '$2y$12$eX4mpL3H4sh3dP4ssw0rd...',
        'phone' => '09171234567',
        'address' => '123 Rizal St, Makati'
    ];

    login_user($mockUser);

    assert_true(is_logged_in(), 'User should be logged in');
    assert_equals(42, current_user_id(), 'User ID mismatch');
    assert_equals('customer', current_user_role(), 'User role mismatch');
    assert_true(has_role('customer'), 'has_role should match single role');
    assert_true(has_role('admin', 'customer'), 'has_role should match multiple roles');
    assert_false(has_role('admin'), 'has_role should return false for unmatched role');

    $currentUser = current_user();
    assert_equals('Juan Dela Cruz', $currentUser['full_name']);
    assert_equals('juan@example.com', $currentUser['email']);
    assert_false(isset($currentUser['password']), 'Password must NOT be present in session');
    assert_not_empty($_SESSION['csrf_token'], 'CSRF token should be created upon login');
    assert_not_empty($_SESSION['last_activity'], 'Last activity timestamp should be set');
});

it('logout_user clears all session authentication state', function () {
    reset_test_env();
    $mockUser = [
        'id' => 10,
        'full_name' => 'Admin User',
        'email' => 'admin@example.com',
        'role' => 'admin'
    ];
    login_user($mockUser);
    assert_true(is_logged_in());

    logout_user();

    assert_false(is_logged_in(), 'User should be logged out');
    assert_equals(null, current_user(), 'current_user should return null');
    assert_equals(null, current_user_id(), 'current_user_id should return null');
    assert_equals(null, current_user_role(), 'current_user_role should return null');
    assert_false(has_role('admin'), 'has_role should return false after logout');
});

it('check_session_timeout retains active session within 30 minutes', function () {
    reset_test_env();
    login_user([
        'id' => 5,
        'full_name' => 'Rider Ken',
        'email' => 'ken@example.com',
        'role' => 'rider'
    ]);

    // Simulate 5 minutes of inactivity (300s < 1800s)
    $_SESSION['last_activity'] = time() - 300;
    $valid = check_session_timeout(1800);

    assert_true($valid, 'Session should remain valid within 30 minutes');
    assert_true(is_logged_in(), 'User should still be logged in');
});

it('check_session_timeout logs out user and sets flash notice after 30-min timeout', function () {
    reset_test_env();
    login_user([
        'id' => 5,
        'full_name' => 'Rider Ken',
        'email' => 'ken@example.com',
        'role' => 'rider'
    ]);

    // Simulate 35 minutes of inactivity (2100s > 1800s)
    $_SESSION['last_activity'] = time() - 2100;
    $valid = check_session_timeout(1800);

    assert_false($valid, 'Session should be flagged as timed out');
    assert_false(is_logged_in(), 'User should be automatically logged out');
    assert_true(has_flash(), 'Warning flash notice should be set');
    $flash = get_flash();
    assert_equals('warning', $flash['type']);
});

// =========================================================================
// Group 2: CSRF Protection
// =========================================================================
echo "\nGroup 2: CSRF Protection\n";

it('csrf_token generates and persists a 64-character hexadecimal token', function () {
    reset_test_env();
    $token1 = csrf_token();
    assert_equals(64, strlen($token1), 'Token should be 64 hex characters');
    assert_true(ctype_xdigit($token1), 'Token should only contain hex characters');

    $token2 = csrf_token();
    assert_equals($token1, $token2, 'Token should persist across calls within the same session');
});

it('csrf_input returns valid escaped HTML hidden input tag', function () {
    reset_test_env();
    $token = csrf_token();
    $html = csrf_input();
    assert_equals('<input type="hidden" name="csrf_token" value="' . $token . '">', $html);
});

it('verify_csrf validates explicit token parameter correctly', function () {
    reset_test_env();
    $token = csrf_token();

    assert_true(verify_csrf($token), 'Valid explicit token should return true');
    assert_false(verify_csrf('invalid_token_string_here_1234567890abcdef'), 'Invalid token should return false');
    assert_false(verify_csrf(''), 'Empty string token should return false');
});

it('verify_csrf validates token from POST request parameter', function () {
    reset_test_env();
    $token = csrf_token();
    $_POST['csrf_token'] = $token;

    assert_true(verify_csrf(), 'verify_csrf should read from $_POST automatically');

    $_POST['csrf_token'] = 'tampered_post_token';
    assert_false(verify_csrf(), 'Tampered POST token must fail');
});

it('verify_csrf validates token from HTTP_X_CSRF_TOKEN header (AJAX)', function () {
    reset_test_env();
    $token = csrf_token();
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;

    assert_true(verify_csrf(), 'verify_csrf should accept HTTP_X_CSRF_TOKEN header');

    $_SERVER['HTTP_X_CSRF_TOKEN'] = 'invalid_header_token';
    assert_false(verify_csrf(), 'Tampered header token must fail');
});

// =========================================================================
// Group 3: Rate Limiting
// =========================================================================
echo "\nGroup 3: Rate Limiting\n";

it('check_rate_limit enforces sliding window attempt count and lockout', function () {
    reset_test_env();
    $action = 'login_attempt';
    $maxAttempts = 3;
    $windowSeconds = 60;

    assert_true(check_rate_limit($action, $maxAttempts, $windowSeconds), 'Attempt 1 should pass');
    assert_equals(2, get_rate_limit_remaining($action, $maxAttempts, $windowSeconds), '2 attempts remaining');

    assert_true(check_rate_limit($action, $maxAttempts, $windowSeconds), 'Attempt 2 should pass');
    assert_equals(1, get_rate_limit_remaining($action, $maxAttempts, $windowSeconds), '1 attempt remaining');

    assert_true(check_rate_limit($action, $maxAttempts, $windowSeconds), 'Attempt 3 should pass');
    assert_equals(0, get_rate_limit_remaining($action, $maxAttempts, $windowSeconds), '0 attempts remaining');

    assert_false(check_rate_limit($action, $maxAttempts, $windowSeconds), 'Attempt 4 should be blocked');
});

it('reset_rate_limit clears counter for specific action', function () {
    reset_test_env();
    $action = 'password_reset';
    check_rate_limit($action, 2, 60);
    check_rate_limit($action, 2, 60);
    assert_false(check_rate_limit($action, 2, 60), 'Should be locked out');

    reset_rate_limit($action);
    assert_true(check_rate_limit($action, 2, 60), 'Should be allowed after reset');
});

it('check_rate_limit prunes expired attempts outside window', function () {
    reset_test_env();
    $action = 'sms_otp';
    $now = time();

    // Seed attempts older than 100 seconds
    $_SESSION['rate_limits'][$action] = [
        $now - 120,
        $now - 110,
        $now - 105
    ];

    // Window is 60 seconds, so all 3 previous attempts are expired
    assert_true(check_rate_limit($action, 3, 60), 'Old attempts outside window should not block new request');
});

// =========================================================================
// Group 4: Middleware & Route Guards
// =========================================================================
echo "\nGroup 4: Middleware & Route Guards\n";

it('is_ajax detects AJAX request variations and ignores standard requests', function () {
    reset_test_env();
    assert_false(is_ajax(), 'Standard GET should not be AJAX');

    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'xmlhttprequest';
    assert_true(is_ajax(), 'HTTP_X_REQUESTED_WITH should be detected as AJAX');
    unset($_SERVER['HTTP_X_REQUESTED_WITH']);

    $_SERVER['HTTP_X_CSRF_TOKEN'] = 'some_token';
    assert_true(is_ajax(), 'HTTP_X_CSRF_TOKEN should be detected as AJAX');
    unset($_SERVER['HTTP_X_CSRF_TOKEN']);

    $_SERVER['HTTP_ACCEPT'] = 'application/json, text/plain, */*';
    assert_true(is_ajax(), 'Accept application/json should be detected as AJAX');
    unset($_SERVER['HTTP_ACCEPT']);

    $_SERVER['CONTENT_TYPE'] = 'application/json; charset=UTF-8';
    assert_true(is_ajax(), 'Content-Type application/json should be detected as AJAX');
    unset($_SERVER['CONTENT_TYPE']);
});

it('require_login allows authenticated user through', function () {
    reset_test_env();
    login_user(['id' => 1, 'role' => 'customer', 'full_name' => 'Alice']);

    // Should complete without throwing an exception
    require_login();
    assert_true(true);
});

it('require_login intercepts unauthenticated web request with 302 and flash error', function () {
    reset_test_env();
    $caught = false;

    try {
        require_login();
    } catch (AuthException $e) {
        $caught = true;
        assert_equals(302, $e->getStatusCode());
        assert_equals(url('/index.php'), $e->getRedirectUrl());
    }

    assert_true($caught, 'AuthException must be thrown in test mode for unauthenticated web request');
    assert_true(has_flash(), 'Flash error message should be set');
    $flash = get_flash();
    assert_equals('error', $flash['type']);
});

it('require_login intercepts unauthenticated AJAX request with JSON 401 response', function () {
    reset_test_env();
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'xmlhttprequest';
    $caught = false;

    try {
        require_login();
    } catch (AuthException $e) {
        $caught = true;
        assert_equals(401, $e->getStatusCode());
        $resp = $e->getJsonResponse();
        assert_equals(false, $resp['success']);
        assert_equals(401, $resp['code']);
    }

    assert_true($caught, 'AuthException with 401 must be thrown for unauthenticated AJAX request');
});

it('require_role allows permitted user and blocks unpermitted user', function () {
    reset_test_env();
    login_user(['id' => 2, 'role' => 'admin', 'full_name' => 'Admin Boss']);

    // Admin accessing admin-only page -> allowed
    require_role('admin');
    assert_true(true);

    // Switch to customer
    login_user(['id' => 3, 'role' => 'customer', 'full_name' => 'Customer Charlie']);

    // Customer accessing customer page -> allowed
    require_role('customer');
    assert_true(true);

    // Customer accessing admin or rider page -> denied
    $caughtWeb = false;
    try {
        require_role('admin', 'rider');
    } catch (AuthException $e) {
        $caughtWeb = true;
        assert_equals(403, $e->getStatusCode());
        assert_equals(url('/pages/customer/shop.php'), $e->getRedirectUrl());
    }
    assert_true($caughtWeb, 'Customer attempting admin role should be redirected with 403');
    assert_true(has_flash(), 'Flash error should be set for denied role');

    // Customer accessing via AJAX -> JSON 403
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'xmlhttprequest';
    $caughtAjax = false;
    try {
        require_role('admin');
    } catch (AuthException $e) {
        $caughtAjax = true;
        assert_equals(403, $e->getStatusCode());
        $resp = $e->getJsonResponse();
        assert_equals(false, $resp['success']);
        assert_equals(403, $resp['code']);
    }
    assert_true($caughtAjax, 'Customer attempting admin role via AJAX should receive JSON 403');
});

it('require_guest allows unauthenticated visitor and redirects authenticated user', function () {
    reset_test_env();

    // Guest accessing login page -> allowed
    require_guest();
    assert_true(true);

    // Rider accessing login page -> redirected to rider dashboard
    login_user(['id' => 7, 'role' => 'rider', 'full_name' => 'Rider John']);
    $caught = false;
    try {
        require_guest();
    } catch (AuthException $e) {
        $caught = true;
        assert_equals(302, $e->getStatusCode());
        assert_equals(url('/pages/rider/deliveries.php'), $e->getRedirectUrl());
    }
    assert_true($caught, 'Authenticated rider should be redirected to rider deliveries');
});

it('require_csrf validates matching token and denies invalid token', function () {
    reset_test_env();
    $token = csrf_token();
    $_POST['csrf_token'] = $token;

    // Matching CSRF -> passes
    require_csrf();
    assert_true(true);

    // Invalid CSRF -> blocked
    $_POST['csrf_token'] = 'invalid_csrf';
    $caught = false;
    try {
        require_csrf();
    } catch (AuthException $e) {
        $caught = true;
        assert_equals(403, $e->getStatusCode());
    }
    assert_true($caught, 'Invalid CSRF must trigger 403');
});

// =========================================================================
// Group 5: Helpers & Sanitization (XSS)
// =========================================================================
echo "\nGroup 5: Helpers & Sanitization (XSS)\n";

it('e helper escapes XSS payloads and handles null values', function () {
    assert_equals('', e(null), 'Null should return empty string');
    assert_equals('Hello World', e('Hello World'));

    $xssScript = "<script>alert('XSS')</script>";
    $escapedScript = e($xssScript);
    // ENT_QUOTES | ENT_HTML5 encodes single quote as &apos;
    assert_true(
        $escapedScript === "&lt;script&gt;alert(&apos;XSS&apos;)&lt;/script&gt;" ||
        $escapedScript === "&lt;script&gt;alert(&#039;XSS&#039;)&lt;/script&gt;",
        'XSS script must be escaped'
    );

    $xssImg = '<img src=x onerror="alert(1)">';
    assert_equals('&lt;img src=x onerror=&quot;alert(1)&quot;&gt;', e($xssImg));

    $symbols = '"\'<>&';
    $escapedSymbols = e($symbols);
    assert_true(
        $escapedSymbols === '&quot;&apos;&lt;&gt;&amp;' ||
        $escapedSymbols === '&quot;&#039;&lt;&gt;&amp;',
        'Symbols must be escaped'
    );
});

it('url helper formats paths and preserves absolute URLs', function () {
    assert_equals('/lpg-delivery-system-repo-2/customer/orders.php', url('/customer/orders.php'));
    assert_equals('/lpg-delivery-system-repo-2/login.php', url('login.php'));
    assert_equals('/lpg-delivery-system-repo-2/', url('/'));
    assert_equals('https://maps.googleapis.com/maps/api', url('https://maps.googleapis.com/maps/api'));
    assert_equals('http://example.com/webhook', url('http://example.com/webhook'));
});

it('flash messaging system sets, checks, and single-consumes flash notifications', function () {
    reset_test_env();
    assert_false(has_flash());
    assert_equals(null, get_flash());

    set_flash('success', 'Profile updated successfully.');
    assert_true(has_flash());

    $flash = get_flash();
    assert_equals('success', $flash['type']);
    assert_equals('Profile updated successfully.', $flash['message']);

    // Consumed: subsequent calls return null
    assert_false(has_flash());
    assert_equals(null, get_flash());
});

it('format_currency and format_date format display outputs properly', function () {
    assert_equals('₱950.00', format_currency(950));
    assert_equals('₱1,250.75', format_currency(1250.75));
    assert_equals('₱0.00', format_currency(0));

    assert_equals('N/A', format_date(null));
    assert_equals('N/A', format_date(''));
    $formatted = format_date('2026-08-20 14:30:00', 'Y-m-d');
    assert_equals('2026-08-20', $formatted);
});

it('sanitize_input trims whitespace and strips null bytes', function () {
    assert_equals('safe input', sanitize_input("  safe input \t\n "));
    assert_equals('null stripped', sanitize_input("null\0 stripped\0"));
    assert_equals('', sanitize_input(null));
});

// =========================================================================
// Group 6: File Upload Security (validate_id_upload)
// =========================================================================
echo "\nGroup 6: File Upload Security (validate_id_upload)\n";

// Create temp directory for upload testing
$testUploadDir = sys_get_temp_dir() . '/lpg_test_uploads_' . bin2hex(random_bytes(6));
if (!is_dir($testUploadDir)) {
    mkdir($testUploadDir, 0755, true);
}

// 1. Valid PNG upload test
it('validate_id_upload accepts valid PNG image file', function () use ($testUploadDir) {
    // Minimal valid 1x1 PNG binary data
    $pngData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    $tmpFile = tempnam(sys_get_temp_dir(), 'lpg_png_');
    file_put_contents($tmpFile, $pngData);

    $mockUpload = [
        'name' => 'my_id.png',
        'type' => 'image/png',
        'tmp_name' => $tmpFile,
        'error' => UPLOAD_ERR_OK,
        'size' => strlen($pngData)
    ];

    $result = validate_id_upload($mockUpload, $testUploadDir);

    assert_true($result['success'], 'Upload should succeed');
    assert_true(str_starts_with($result['filename'], 'id_'), 'Filename should start with id_');
    assert_true(str_ends_with($result['filename'], '.png'), 'Filename should end with .png');
    assert_equals('image/png', $result['mime_type']);
    assert_true(file_exists($result['filepath']), 'Saved file must exist on disk');

    @unlink($tmpFile);
    @unlink($result['filepath']);
});

// 2. Valid JPEG upload test
it('validate_id_upload accepts valid JPEG image file', function () use ($testUploadDir) {
    // Minimal valid JPEG binary data
    $jpegData = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
    $tmpFile = tempnam(sys_get_temp_dir(), 'lpg_jpg_');
    file_put_contents($tmpFile, $jpegData);

    $mockUpload = [
        'name' => 'valid_id.jpg',
        'type' => 'image/jpeg',
        'tmp_name' => $tmpFile,
        'error' => UPLOAD_ERR_OK,
        'size' => strlen($jpegData)
    ];

    $result = validate_id_upload($mockUpload, $testUploadDir);

    assert_true($result['success'], 'Upload should succeed');
    assert_equals('image/jpeg', $result['mime_type']);
    assert_true(str_ends_with($result['filename'], '.jpg'));
    assert_true(file_exists($result['filepath']));

    @unlink($tmpFile);
    @unlink($result['filepath']);
});

// 3. Valid PDF document upload test
it('validate_id_upload accepts valid PDF document', function () use ($testUploadDir) {
    // Minimal valid PDF binary header
    $pdfData = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj 3 0 obj<</Type/Page/MediaBox[0 0 3 3]>>endobj\nxref\n0 4\n0000000000 65535 f\n0000000010 00000 n\n0000000053 00000 n\n0000000102 00000 n\ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n149\n%%EOF\n";
    $tmpFile = tempnam(sys_get_temp_dir(), 'lpg_pdf_');
    file_put_contents($tmpFile, $pdfData);

    $mockUpload = [
        'name' => 'government_id.pdf',
        'type' => 'application/pdf',
        'tmp_name' => $tmpFile,
        'error' => UPLOAD_ERR_OK,
        'size' => strlen($pdfData)
    ];

    $result = validate_id_upload($mockUpload, $testUploadDir);

    assert_true($result['success'], 'PDF upload should succeed');
    assert_equals('application/pdf', $result['mime_type']);
    assert_true(str_ends_with($result['filename'], '.pdf'));
    assert_true(file_exists($result['filepath']));

    @unlink($tmpFile);
    @unlink($result['filepath']);
});

// 4. Valid WebP image upload test
it('validate_id_upload accepts valid WebP image file', function () use ($testUploadDir) {
    // Minimal valid 1x1 WebP binary data
    $webpData = base64_decode('UklGRkAAAABXRUJQVlA4IDQAAADwAQCdASoBAAEAAQAcJaACdLoB+AAA/v6fAP//78gA//+Vf/8gAP//lX//IAD//5V//yAA');
    $tmpFile = tempnam(sys_get_temp_dir(), 'lpg_webp_');
    file_put_contents($tmpFile, $webpData);

    $mockUpload = [
        'name' => 'national_id.webp',
        'type' => 'image/webp',
        'tmp_name' => $tmpFile,
        'error' => UPLOAD_ERR_OK,
        'size' => strlen($webpData)
    ];

    $result = validate_id_upload($mockUpload, $testUploadDir);

    assert_true($result['success'], 'WebP upload should succeed');
    assert_equals('image/webp', $result['mime_type']);
    assert_true(str_ends_with($result['filename'], '.webp'));
    assert_true(file_exists($result['filepath']));

    @unlink($tmpFile);
    @unlink($result['filepath']);
});

// 5. Reject disallowed MIME type disguised with image extension (PHP backdoor test)
it('validate_id_upload detects and rejects executable script disguised as image', function () use ($testUploadDir) {
    $phpScript = "<?php echo 'malicious backdoor'; ?>";
    $tmpFile = tempnam(sys_get_temp_dir(), 'lpg_fake_');
    file_put_contents($tmpFile, $phpScript);

    $mockUpload = [
        'name' => 'id_card.png', // Fake extension
        'type' => 'image/png',   // Fake client MIME
        'tmp_name' => $tmpFile,
        'error' => UPLOAD_ERR_OK,
        'size' => strlen($phpScript)
    ];

    $result = validate_id_upload($mockUpload, $testUploadDir);

    assert_false($result['success'], 'Disguised PHP file must be rejected via finfo MIME verification');
    assert_not_empty($result['error']);

    @unlink($tmpFile);
});

// 6. Reject oversized file (> 5 MB)
it('validate_id_upload rejects files exceeding 5 MB limit', function () use ($testUploadDir) {
    $tmpFile = tempnam(sys_get_temp_dir(), 'lpg_huge_');
    file_put_contents($tmpFile, 'dummy content');

    $mockUpload = [
        'name' => 'large_scan.jpg',
        'type' => 'image/jpeg',
        'tmp_name' => $tmpFile,
        'error' => UPLOAD_ERR_OK,
        'size' => 6 * 1024 * 1024 // 6 MB
    ];

    $result = validate_id_upload($mockUpload, $testUploadDir);

    assert_false($result['success'], 'Oversized file should be rejected');
    assert_not_empty($result['error']);

    @unlink($tmpFile);
});

// 7. Handle upload error codes properly
it('validate_id_upload handles UPLOAD_ERR_NO_FILE and other error codes', function () use ($testUploadDir) {
    $mockUpload = [
        'name' => '',
        'type' => '',
        'tmp_name' => '',
        'error' => UPLOAD_ERR_NO_FILE,
        'size' => 0
    ];

    $result = validate_id_upload($mockUpload, $testUploadDir);
    assert_false($result['success']);
    assert_equals('No file was uploaded. Please select a valid ID document.', $result['error']);
});

// Clean up test directory
@rmdir($testUploadDir);

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
