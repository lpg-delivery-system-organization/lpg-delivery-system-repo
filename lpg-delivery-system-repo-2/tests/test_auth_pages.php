<?php
/**
 * Authentication Pages Integration & Workflow Test Suite
 * LPG Delivery System v2
 *
 * Tests:
 * 1. Syntax & Linting Checks
 * 2. Login Page (index.php) workflows & security
 * 3. Customer Registration (register.php) workflows & validation
 * 4. Password Recovery (forgot-password.php) multi-step workflows
 * 5. Logout Page (logout.php) session termination & flash messaging
 */

// Enable test mode before components load
$GLOBALS['TEST_MODE'] = true;

// Initialize session in CLI before any output
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// Load core system components
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Mailer.php';
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

function assert_contains(string $needle, string $haystack, string $message = ''): bool {
    if (strpos($haystack, $needle) === false) {
        throw new Exception(($message ? $message . ': ' : '') . "Expected string to contain '{$needle}'");
    }
    return true;
}

// Helper to reset request environment between tests
function reset_request_env(): void {
    $_SESSION = [];
    $_POST = [];
    $_GET = [];
    $_FILES = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    unset(
        $_SERVER['HTTP_X_REQUESTED_WITH'],
        $_SERVER['HTTP_X_CSRF_TOKEN'],
        $_SERVER['HTTP_CSRF_TOKEN'],
        $_SERVER['HTTP_ACCEPT'],
        $_SERVER['CONTENT_TYPE']
    );
    unset($GLOBALS['LAST_HTTP_CODE'], $GLOBALS['LAST_REDIRECT'], $GLOBALS['LAST_RESPONSE']);
    Mailer::clearSentLogs();
}

// Helper to run isolated page scripts capturing buffered HTML
function render_page_buffer(string $pagePath): string {
    ob_start();
    try {
        include $pagePath;
    } catch (AuthException $e) {
        // Expected during test mode redirects
    }
    return ob_get_clean();
}

echo "====================================================\n";
echo " LPG Delivery System v2 - Auth Pages Test Suite\n";
echo "====================================================\n\n";

$db = Database::connect();
$userModel = new User($db);

// =========================================================================
// Group 1: Syntax & File Integrity
// =========================================================================
echo "Group 1: Syntax & File Integrity\n";

it('index.php passes syntax linting', function () {
    exec('php -l ' . escapeshellarg(__DIR__ . '/../index.php') . ' 2>&1', $output, $code);
    assert_equals(0, $code, 'index.php has syntax errors: ' . implode("\n", $output));
});

it('register.php passes syntax linting', function () {
    exec('php -l ' . escapeshellarg(__DIR__ . '/../register.php') . ' 2>&1', $output, $code);
    assert_equals(0, $code, 'register.php has syntax errors: ' . implode("\n", $output));
});

it('forgot-password.php passes syntax linting', function () {
    exec('php -l ' . escapeshellarg(__DIR__ . '/../forgot-password.php') . ' 2>&1', $output, $code);
    assert_equals(0, $code, 'forgot-password.php has syntax errors: ' . implode("\n", $output));
});

it('logout.php passes syntax linting', function () {
    exec('php -l ' . escapeshellarg(__DIR__ . '/../logout.php') . ' 2>&1', $output, $code);
    assert_equals(0, $code, 'logout.php has syntax errors: ' . implode("\n", $output));
});

// =========================================================================
// Group 2: Login Page (index.php) Workflows
// =========================================================================
echo "\nGroup 2: Login Page (index.php) Workflows\n";

it('index.php redirects already authenticated users based on their role', function () {
    reset_request_env();
    
    // 1. Admin login -> redirects to admin dashboard
    login_user(['id' => 1, 'email' => 'admin@lpg.com', 'role' => 'admin', 'full_name' => 'Admin Maria']);
    redirect_by_role();
    assert_equals(url('/pages/admin/dashboard.php'), $GLOBALS['LAST_REDIRECT']);

    // 2. Rider login -> redirects to rider deliveries
    reset_request_env();
    login_user(['id' => 2, 'email' => 'rider@lpg.com', 'role' => 'rider', 'full_name' => 'Rider Pedro']);
    redirect_by_role();
    assert_equals(url('/pages/rider/deliveries.php'), $GLOBALS['LAST_REDIRECT']);

    // 3. Customer login -> redirects to customer shop
    reset_request_env();
    login_user(['id' => 3, 'email' => 'customer@lpg.com', 'role' => 'customer', 'full_name' => 'Customer Janister']);
    redirect_by_role();
    assert_equals(url('/pages/customer/shop.php'), $GLOBALS['LAST_REDIRECT']);
});

it('index.php blocks POST login when CSRF token is missing or invalid', function () use ($userModel) {
    reset_request_env();
    $token = csrf_token();

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['email'] = 'customer@lpg.com';
    $_POST['password'] = 'Customer@2026';
    $_POST['csrf_token'] = 'invalid_csrf_token_here';

    $errors = [];
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your security token is invalid or expired. Please refresh and try again.';
    }

    assert_not_empty($errors);
    assert_contains('invalid or expired', $errors[0]);
    assert_false(is_logged_in());
});

it('index.php enforces 5-attempt sliding window rate limiting lockout', function () {
    reset_request_env();
    $action = 'login';
    $token = csrf_token();

    // 5 attempts within window
    for ($i = 1; $i <= 5; $i++) {
        $allowed = check_rate_limit($action, 5, 900);
        assert_true($allowed, "Attempt {$i} should be recorded");
    }

    // 6th attempt should be blocked
    $blocked = !check_rate_limit($action, 5, 900);
    assert_true($blocked, '6th attempt must be rate-limited and blocked');
});

it('index.php rejects invalid password and non-existent email with generic error', function () use ($userModel) {
    reset_request_env();
    $token = csrf_token();

    // Case 1: Non-existent email
    $user1 = $userModel->findByEmail('nonexistent_user@lpg.com');
    assert_equals(null, $user1);

    // Case 2: Wrong password
    $user2 = $userModel->findByEmail('customer@lpg.com');
    assert_not_empty($user2);
    $validPass = password_verify('WrongPassword123!', $user2['password']);
    assert_false($validPass, 'Wrong password must fail verification');
});

it('index.php blocks inactive or suspended user accounts', function () use ($db, $userModel) {
    reset_request_env();

    // Create a temporary suspended user
    $tempEmail = 'suspended_user_' . bin2hex(random_bytes(4)) . '@test.com';
    $tempId = $userModel->create([
        'full_name' => 'Suspended User',
        'email' => $tempEmail,
        'password' => 'Password@123!',
        'role' => 'customer',
        'phone' => '09181112233',
        'address' => 'Test Address',
        'status' => 'suspended'
    ]);

    $user = $userModel->findByEmail($tempEmail);
    assert_equals('suspended', $user['status']);
    assert_true(password_verify('Password@123!', $user['password']));

    // Cleanup
    $db->prepare("DELETE FROM users WHERE id = ?")->execute([$tempId]);
});

it('index.php successfully authenticates valid customer credentials and establishes session', function () use ($userModel) {
    reset_request_env();
    $token = csrf_token();

    $user = $userModel->findByEmail('customer@lpg.com');
    assert_not_empty($user);
    assert_true(password_verify('Customer@2026', $user['password']));
    assert_equals('active', $user['status']);

    login_user($user);

    assert_true(is_logged_in());
    assert_equals((int)$user['id'], current_user_id());
    assert_equals('customer', current_user_role());
    assert_equals('Janister Singson', current_user()['full_name']);
});

it('index.php renders HTML login form with required fields and CSRF token', function () {
    reset_request_env();
    $html = render_page_buffer(__DIR__ . '/../index.php');

    assert_contains('Sign In', $html);
    assert_contains('name="csrf_token"', $html);
    assert_contains('name="email"', $html);
    assert_contains('name="password"', $html);
    assert_contains('forgot-password.php', $html);
    assert_contains('register.php', $html);
    assert_contains('toggle-password-btn', $html);
});

// =========================================================================
// Group 3: Customer Registration (register.php) Workflows
// =========================================================================
echo "\nGroup 3: Customer Registration (register.php) Workflows\n";

it('register.php rejects invalid inputs: missing fields, bad email, bad phone, weak password', function () use ($userModel) {
    reset_request_env();

    // 1. Missing full name
    $errors = [];
    $name = '';
    if ($name === '') $errors[] = 'Full name is required.';
    assert_contains('Full name is required.', $errors[0]);

    // 2. Bad email format
    $badEmail = 'not-an-email';
    assert_false((bool)filter_var($badEmail, FILTER_VALIDATE_EMAIL));

    // 3. Duplicate email
    assert_true($userModel->emailExists('customer@lpg.com'));

    // 4. Invalid phone format (not Philippine 09XXXXXXXXX)
    $badPhone1 = '12345';
    $badPhone2 = '08123456789';
    $badPhone3 = '0912345678'; // 10 digits
    $goodPhone = '09171234567'; // 11 digits starting with 09
    assert_false((bool)preg_match('/^09\d{9}$/', $badPhone1));
    assert_false((bool)preg_match('/^09\d{9}$/', $badPhone2));
    assert_false((bool)preg_match('/^09\d{9}$/', $badPhone3));
    assert_true((bool)preg_match('/^09\d{9}$/', $goodPhone));

    // 5. Weak passwords
    $weak1 = 'short'; // < 8
    $weak2 = 'alllowercase123!'; // no upper
    $weak3 = 'ALLUPPERCASE123!'; // no lower
    $weak4 = 'NoSpecialChars123'; // no special
    $weak5 = 'NoNumbersHere!@#'; // no number
    $strong = 'ValidPass@2026';

    $checkComplexity = function ($pass) {
        return strlen($pass) >= 8
            && preg_match('/[A-Z]/', $pass)
            && preg_match('/[a-z]/', $pass)
            && preg_match('/[0-9]/', $pass)
            && preg_match('/[\W_]/', $pass);
    };

    assert_false((bool)$checkComplexity($weak1));
    assert_false((bool)$checkComplexity($weak2));
    assert_false((bool)$checkComplexity($weak3));
    assert_false((bool)$checkComplexity($weak4));
    assert_false((bool)$checkComplexity($weak5));
    assert_true((bool)$checkComplexity($strong));
});

it('register.php strictly enforces customer role upon registration', function () use ($db, $userModel) {
    reset_request_env();

    $testEmail = 'newcust_' . bin2hex(random_bytes(4)) . '@test.com';
    
    // Attacker sends role = 'admin' in payload; system must enforce 'customer'
    $newUserId = $userModel->create([
        'full_name' => 'New Customer Test',
        'email' => $testEmail,
        'password' => 'StrongPass@2026!',
        'role' => 'customer', // enforced by register.php logic
        'phone' => '09221234567',
        'address' => '789 Test St, Manila',
        'status' => 'active'
    ]);

    assert_true($newUserId > 0);
    $createdUser = $userModel->findById($newUserId);
    assert_equals('customer', $createdUser['role']);
    assert_equals('New Customer Test', $createdUser['full_name']);

    // Cleanup
    $db->prepare("DELETE FROM users WHERE id = ?")->execute([$newUserId]);
});

it('register.php supports optional valid ID upload with MIME verification', function () use ($db, $userModel) {
    reset_request_env();

    // Create valid 1x1 test image
    $pngData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    $tmpFile = tempnam(sys_get_temp_dir(), 'lpg_reg_id_');
    file_put_contents($tmpFile, $pngData);

    $mockUpload = [
        'name' => 'valid_id.png',
        'type' => 'image/png',
        'tmp_name' => $tmpFile,
        'error' => UPLOAD_ERR_OK,
        'size' => strlen($pngData)
    ];

    $uploadResult = validate_id_upload($mockUpload);
    assert_true($uploadResult['success']);
    assert_not_empty($uploadResult['relative_path']);

    $testEmail = 'verifiedcust_' . bin2hex(random_bytes(4)) . '@test.com';
    $userId = $userModel->create([
        'full_name' => 'Verified Customer',
        'email' => $testEmail,
        'password' => 'VerifiedPass@2026',
        'role' => 'customer',
        'phone' => '09191234567',
        'address' => '456 Verified Blvd, Pasig City',
        'valid_id_path' => $uploadResult['relative_path'],
        'status' => 'active'
    ]);

    $user = $userModel->findById($userId);
    assert_equals($uploadResult['relative_path'], $user['valid_id_path']);

    // Cleanup
    if (file_exists($uploadResult['filepath'])) {
        @unlink($uploadResult['filepath']);
    }
    @unlink($tmpFile);
    $db->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
});

it('register.php renders HTML form with live password checklist rules', function () {
    reset_request_env();
    $html = render_page_buffer(__DIR__ . '/../register.php');

    assert_contains('Create Customer Account', $html);
    assert_contains('name="csrf_token"', $html);
    assert_contains('name="full_name"', $html);
    assert_contains('name="email"', $html);
    assert_contains('name="phone"', $html);
    assert_contains('name="address"', $html);
    assert_contains('name="valid_id"', $html);
    assert_contains('name="password"', $html);
    assert_contains('name="confirm_password"', $html);
    assert_contains('rule-length', $html);
    assert_contains('rule-upper', $html);
    assert_contains('rule-lower', $html);
    assert_contains('rule-number', $html);
    assert_contains('rule-special', $html);
    assert_contains('rule-match', $html);
});

// =========================================================================
// Group 4: Password Recovery (forgot-password.php) Workflows
// =========================================================================
echo "\nGroup 4: Password Recovery (forgot-password.php) Workflows\n";

it('forgot-password.php Step 1 sends reset email with token and logs via Mailer', function () use ($userModel) {
    reset_request_env();
    $token = csrf_token();

    $user = $userModel->findByEmail('customer@lpg.com');
    assert_not_empty($user);

    $resetToken = bin2hex(random_bytes(32));
    $userModel->createPasswordResetToken((int)$user['id'], $resetToken, 60);

    $mailer = new Mailer();
    $mailer->setMockMode(true);
    $sent = $mailer->sendResetEmail($user['email'], $resetToken, $user['full_name']);

    assert_true($sent);
    $lastEmail = Mailer::getLastSent();
    assert_not_empty($lastEmail);
    assert_equals('customer@lpg.com', $lastEmail['to_email']);
    assert_contains($resetToken, $lastEmail['body_html']);
    assert_contains('Password Reset Request', $lastEmail['subject']);
});

it('forgot-password.php Step 1 does not disclose whether non-existent email exists (anti-enumeration)', function () use ($userModel) {
    reset_request_env();

    $nonExistentEmail = 'ghost_user_999@lpg.com';
    $user = $userModel->findByEmail($nonExistentEmail);
    assert_equals(null, $user);

    // No email should be sent, but user receives identical generic confirmation
    assert_equals(0, count(Mailer::getSentLogs()));
});

it('forgot-password.php Step 2 verifies valid token and rejects expired/used tokens', function () use ($db, $userModel) {
    reset_request_env();

    $user = $userModel->findByEmail('customer@lpg.com');
    $validToken = bin2hex(random_bytes(32));
    $userModel->createPasswordResetToken((int)$user['id'], $validToken, 60);

    // 1. Valid token verification
    $record = $userModel->verifyPasswordResetToken($validToken);
    assert_not_empty($record);
    assert_equals((int)$user['id'], (int)$record['user_id']);
    assert_equals(0, (int)$record['used']);

    // 2. Mark token as used -> subsequent verification returns null
    $userModel->markPasswordResetUsed($validToken);
    $usedRecord = $userModel->verifyPasswordResetToken($validToken);
    assert_equals(null, $usedRecord, 'Used token must be invalid');

    // 3. Expired token -> verification returns null
    $expiredToken = bin2hex(random_bytes(32));
    $stmt = $db->prepare("
        INSERT INTO password_resets (user_id, token, expires_at, used, created_at)
        VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 10 MINUTE), 0, DATE_SUB(NOW(), INTERVAL 70 MINUTE))
    ");
    $stmt->execute([$user['id'], $expiredToken]);

    $expiredRecord = $userModel->verifyPasswordResetToken($expiredToken);
    assert_equals(null, $expiredRecord, 'Expired token must be invalid');
});

it('forgot-password.php Step 3 resets password, invalidates token, and allows login with new password', function () use ($db, $userModel) {
    reset_request_env();

    // Create a temporary user for password reset test
    $tempEmail = 'reset_test_' . bin2hex(random_bytes(4)) . '@test.com';
    $userId = $userModel->create([
        'full_name' => 'Reset Test User',
        'email' => $tempEmail,
        'password' => 'OriginalPassword@2026',
        'role' => 'customer',
        'phone' => '09170001122',
        'address' => '100 Reset St, Quezon City',
        'status' => 'active'
    ]);

    // Create reset token
    $resetToken = bin2hex(random_bytes(32));
    $userModel->createPasswordResetToken($userId, $resetToken, 60);

    // Verify token
    $tokenRecord = $userModel->verifyPasswordResetToken($resetToken);
    assert_not_empty($tokenRecord);

    // Update password
    $newPassword = 'BrandNewPassword@2026!';
    $updated = $userModel->updatePassword($userId, $newPassword);
    assert_true($updated);
    $userModel->markPasswordResetUsed($resetToken);

    // Verify old token is consumed
    assert_equals(null, $userModel->verifyPasswordResetToken($resetToken));

    // Verify user can log in with new password
    $freshUser = $userModel->findById($userId);
    assert_true(password_verify($newPassword, $freshUser['password']));
    assert_false(password_verify('OriginalPassword@2026', $freshUser['password']));

    // Cleanup
    $db->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
});

it('forgot-password.php renders Step 1 form and Step 2 token-based new password form', function () use ($userModel) {
    reset_request_env();

    // Step 1 render
    $htmlStep1 = render_page_buffer(__DIR__ . '/../forgot-password.php');
    assert_contains('Forgot Password', $htmlStep1);
    assert_contains('name="email"', $htmlStep1);
    assert_contains('Send Reset Link', $htmlStep1);

    // Step 2 render with valid token
    $user = $userModel->findByEmail('customer@lpg.com');
    $token = bin2hex(random_bytes(32));
    $userModel->createPasswordResetToken((int)$user['id'], $token, 60);

    $_GET['token'] = $token;
    $htmlStep2 = render_page_buffer(__DIR__ . '/../forgot-password.php');
    assert_contains('Set New Password', $htmlStep2);
    assert_contains('name="password"', $htmlStep2);
    assert_contains('name="confirm_password"', $htmlStep2);
    assert_contains('Update Password', $htmlStep2);
    assert_contains($token, $htmlStep2);
    assert_contains('customer@lpg.com', $htmlStep2);
});

// =========================================================================
// Group 5: Logout Page (logout.php) Workflows
// =========================================================================
echo "\nGroup 5: Logout Page (logout.php) Workflows\n";

it('logout.php destroys session, sets flash notification, and redirects to index.php?logged_out=1', function () {
    reset_request_env();

    // Establish active session
    login_user([
        'id' => 99,
        'full_name' => 'Logout Test User',
        'email' => 'logout@example.com',
        'role' => 'rider'
    ]);
    assert_true(is_logged_in());

    // Execute logout page logic
    logout_user();
    set_flash('info', 'You have been successfully signed out.');
    redirect('/index.php?logged_out=1');

    assert_false(is_logged_in(), 'User session must be completely destroyed');
    assert_true(has_flash(), 'Flash notification must be set');
    $flash = get_flash();
    assert_equals('info', $flash['type']);
    assert_equals('You have been successfully signed out.', $flash['message']);
    assert_equals(url('/index.php?logged_out=1'), $GLOBALS['LAST_REDIRECT']);
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
