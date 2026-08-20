<?php
/**
 * Password Recovery (Forgot / Reset Password) Page
 * LPG Delivery System v2
 */

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/middleware.php';
require_once __DIR__ . '/classes/User.php';
require_once __DIR__ . '/classes/Mailer.php';

// Redirect if already authenticated
if (is_logged_in()) {
    redirect_by_role();
}

$page_title = 'Password Recovery';
$errors = [];
$email = '';
$emailSent = false;
$userModel = new User();

// Determine flow step: Reset Token Verification or Email Request
$token = sanitize_input($_POST['token'] ?? $_GET['token'] ?? '');
$isTokenMode = !empty($token);
$resetRecord = null;

if ($isTokenMode) {
    $resetRecord = $userModel->verifyPasswordResetToken($token);
    $page_title = 'Set New Password';
}

// -------------------------------------------------------------------------
// Flow 1: Step 3 - Handle New Password Submission (when token is valid)
// -------------------------------------------------------------------------
if ($isTokenMode && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedCsrf = $_POST['csrf_token'] ?? null;
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // 1. Verify CSRF
    if (!verify_csrf($submittedCsrf)) {
        $errors[] = 'Your security token is invalid or expired. Please try again.';
    }

    // 2. Re-verify Token
    if ($resetRecord === null) {
        $errors[] = 'The password reset token is invalid, expired, or has already been used.';
    }

    // 3. Password Complexity & Match Validation
    if (empty($errors)) {
        if ($password === '') {
            $errors[] = 'New password is required.';
        } else {
            if (strlen($password) < 8) {
                $errors[] = 'Password must be at least 8 characters long.';
            }
            if (!preg_match('/[A-Z]/', $password)) {
                $errors[] = 'Password must contain at least one uppercase letter (A-Z).';
            }
            if (!preg_match('/[a-z]/', $password)) {
                $errors[] = 'Password must contain at least one lowercase letter (a-z).';
            }
            if (!preg_match('/[0-9]/', $password)) {
                $errors[] = 'Password must contain at least one number (0-9).';
            }
            if (!preg_match('/[\W_]/', $password)) {
                $errors[] = 'Password must contain at least one special character (!@#$%^&* etc.).';
            }
        }

        if ($password !== $confirmPassword) {
            $errors[] = 'Password confirmation does not match.';
        }
    }

    // 4. Update Password and Invalidate Token
    if (empty($errors) && $resetRecord !== null) {
        $updated = $userModel->updatePassword((int)$resetRecord['user_id'], $password);
        if ($updated) {
            $userModel->markPasswordResetUsed($token);
            set_flash('success', 'Your password has been successfully reset! You can now sign in with your new password.');
            redirect('/index.php');
        } else {
            $errors[] = 'Failed to update password. Please try again.';
        }
    }
}

// -------------------------------------------------------------------------
// Flow 2: Step 1 - Handle Reset Link Request (when no token)
// -------------------------------------------------------------------------
if (!$isTokenMode && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitize_input($_POST['email'] ?? '');
    $submittedCsrf = $_POST['csrf_token'] ?? null;

    // 1. Verify CSRF
    if (!verify_csrf($submittedCsrf)) {
        $errors[] = 'Your security token is invalid or expired. Please try again.';
    }

    // 2. Sliding Window Rate Limiting (5 requests / 15 minutes)
    if (empty($errors) && !check_rate_limit('password_reset', 5, 900)) {
        $errors[] = 'Too many password reset requests. Please wait 15 minutes before trying again.';
    }

    // 3. Email Format Validation
    if (empty($errors)) {
        if ($email === '') {
            $errors[] = 'Email address is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please provide a valid email address.';
        }
    }

    // 4. Generate Token & Send Email (Anti-enumeration: show confirmation regardless)
    if (empty($errors)) {
        $user = $userModel->findByEmail($email);

        if ($user && $user['status'] === 'active') {
            $resetToken = bin2hex(random_bytes(32));
            $userModel->createPasswordResetToken((int)$user['id'], $resetToken, 60);

            $mailer = new Mailer();
            $mailer->sendResetEmail($user['email'], $resetToken, $user['full_name']);
        }

        $emailSent = true;
    }
}

require_once __DIR__ . '/templates/header.php';
?>

<div class="container py-4 py-md-5">
    <div class="row justify-content-center">
        <div class="col-12 col-sm-10 col-md-8 col-lg-5 col-xl-4">

            <!-- App Branding Header -->
            <div class="text-center mb-4">
                <div class="d-inline-flex align-items-center justify-content-center bg-primary text-white rounded-circle mb-3 shadow-sm" style="width: 56px; height: 56px;">
                    <i class="bi bi-shield-lock text-warning fs-3"></i>
                </div>
                <h1 class="h3 fw-bold text-dark mb-1">Account Recovery</h1>
                <p class="text-muted small">Recover access to your LPG delivery account</p>
            </div>

            <!-- Card Container -->
            <div class="card shadow-sm border-0 rounded-3">
                <div class="card-body p-4 p-md-4">

                    <?php if ($isTokenMode): ?>
                        
                        <?php if ($resetRecord === null): ?>
                            <!-- Case A: Invalid or Expired Token -->
                            <div class="text-center py-3">
                                <div class="text-danger mb-3">
                                    <i class="bi bi-exclamation-octagon-fill fs-1"></i>
                                </div>
                                <h2 class="h5 fw-bold text-dark mb-2">Invalid or Expired Link</h2>
                                <p class="text-muted small mb-4">
                                    This password reset link is invalid, has expired (valid for 60 minutes), or has already been used. Please request a new link.
                                </p>
                                <a href="<?= url('forgot-password.php') ?>" class="btn btn-primary w-100 py-2 fw-semibold">
                                    <i class="bi bi-arrow-repeat me-2"></i>Request New Reset Link
                                </a>
                                <div class="mt-3">
                                    <a href="<?= url('index.php') ?>" class="text-decoration-none small text-muted">
                                        Back to Sign In
                                    </a>
                                </div>
                            </div>

                        <?php else: ?>
                            <!-- Case B: Valid Token - New Password Form (Step 2/3) -->
                            <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
                                <h2 class="h5 fw-bold mb-0 text-dark">Set New Password</h2>
                                <span class="badge bg-success text-white px-2 py-1 small">Verified</span>
                            </div>

                            <div class="mb-3 p-2 bg-light rounded text-muted extra-small">
                                Resetting password for: <strong class="text-dark"><?= e($resetRecord['email']) ?></strong>
                            </div>

                            <?php if (!empty($errors)): ?>
                                <div class="alert alert-danger alert-dismissible fade show d-flex align-items-start shadow-sm mb-3" role="alert">
                                    <i class="bi bi-exclamation-triangle-fill fs-5 me-2 flex-shrink-0 mt-1"></i>
                                    <div class="flex-grow-1 small">
                                        <?php if (count($errors) === 1): ?>
                                            <span><?= e($errors[0]) ?></span>
                                        <?php else: ?>
                                            <ul class="mb-0 ps-3">
                                                <?php foreach ($errors as $err): ?>
                                                    <li><?= e($err) ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </div>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                </div>
                            <?php endif; ?>

                            <form action="<?= url('forgot-password.php?token=' . urlencode($token)) ?>" method="POST" novalidate id="resetPasswordForm">
                                <?= csrf_input() ?>
                                <input type="hidden" name="token" value="<?= e($token) ?>">

                                <!-- New Password -->
                                <div class="mb-3">
                                    <label for="password" class="form-label fw-semibold small text-dark">
                                        New Password <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-end-0 text-muted">
                                            <i class="bi bi-lock"></i>
                                        </span>
                                        <input
                                            type="password"
                                            class="form-control border-start-0 border-end-0 px-0"
                                            id="password"
                                            name="password"
                                            placeholder="Enter new password"
                                            required
                                            autocomplete="new-password"
                                            autofocus
                                        >
                                        <button class="btn btn-outline-secondary border-start-0 bg-light text-muted toggle-password-btn" type="button" data-target="#password" aria-label="Toggle password visibility">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                </div>

                                <!-- Confirm Password -->
                                <div class="mb-3">
                                    <label for="confirmPassword" class="form-label fw-semibold small text-dark">
                                        Confirm New Password <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-end-0 text-muted">
                                            <i class="bi bi-lock-fill"></i>
                                        </span>
                                        <input
                                            type="password"
                                            class="form-control border-start-0 border-end-0 px-0"
                                            id="confirmPassword"
                                            name="confirm_password"
                                            placeholder="Confirm new password"
                                            required
                                            autocomplete="new-password"
                                        >
                                        <button class="btn btn-outline-secondary border-start-0 bg-light text-muted toggle-password-btn" type="button" data-target="#confirmPassword" aria-label="Toggle password confirmation visibility">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                </div>

                                <!-- Password Checklist -->
                                <div class="mb-3 p-3 bg-light rounded-3 border small">
                                    <div class="fw-semibold text-dark mb-2">Password Requirements:</div>
                                    <div class="row g-2">
                                        <div class="col-12">
                                            <div id="rule-length" class="d-flex align-items-center text-muted">
                                                <i class="bi bi-circle me-2 text-muted rule-icon"></i>
                                                <span>At least 8 characters</span>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div id="rule-upper" class="d-flex align-items-center text-muted">
                                                <i class="bi bi-circle me-2 text-muted rule-icon"></i>
                                                <span>At least 1 uppercase letter (A-Z)</span>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div id="rule-lower" class="d-flex align-items-center text-muted">
                                                <i class="bi bi-circle me-2 text-muted rule-icon"></i>
                                                <span>At least 1 lowercase letter (a-z)</span>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div id="rule-number" class="d-flex align-items-center text-muted">
                                                <i class="bi bi-circle me-2 text-muted rule-icon"></i>
                                                <span>At least 1 number (0-9)</span>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div id="rule-special" class="d-flex align-items-center text-muted">
                                                <i class="bi bi-circle me-2 text-muted rule-icon"></i>
                                                <span>At least 1 special character (!@#$%^&*)</span>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div id="rule-match" class="d-flex align-items-center text-muted">
                                                <i class="bi bi-circle me-2 text-muted rule-icon"></i>
                                                <span>Passwords match</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="d-grid mb-3">
                                    <button type="submit" class="btn btn-primary py-2 fw-semibold shadow-sm" id="savePasswordBtn">
                                        <i class="bi bi-check2-circle me-2"></i>Update Password
                                    </button>
                                </div>
                            </form>

                        <?php endif; ?>

                    <?php elseif ($emailSent): ?>
                        <!-- Case C: Reset Email Sent Confirmation -->
                        <div class="text-center py-3">
                            <div class="text-success mb-3">
                                <i class="bi bi-envelope-check-fill fs-1"></i>
                            </div>
                            <h2 class="h5 fw-bold text-dark mb-2">Check Your Inbox</h2>
                            <p class="text-muted small mb-4">
                                If an account exists for <strong class="text-dark"><?= e($email) ?></strong>, we have sent a secure password reset link. Please check your inbox and spam folder.
                            </p>
                            <div class="d-grid gap-2">
                                <a href="<?= url('index.php') ?>" class="btn btn-primary py-2 fw-semibold">
                                    <i class="bi bi-box-arrow-in-right me-2"></i>Return to Sign In
                                </a>
                                <a href="<?= url('forgot-password.php') ?>" class="btn btn-outline-secondary py-2 small">
                                    Try another email address
                                </a>
                            </div>
                        </div>

                    <?php else: ?>
                        <!-- Case D: Step 1 - Request Reset Form -->
                        <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
                            <h2 class="h5 fw-bold mb-0 text-dark">Forgot Password</h2>
                            <span class="badge bg-light text-muted border px-2 py-1 small">Step 1 of 2</span>
                        </div>

                        <p class="text-muted small mb-4">
                            Enter the email address associated with your account. We will send you a secure link to reset your password.
                        </p>

                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger alert-dismissible fade show d-flex align-items-start shadow-sm mb-3" role="alert">
                                <i class="bi bi-exclamation-triangle-fill fs-5 me-2 flex-shrink-0 mt-1"></i>
                                <div class="flex-grow-1 small">
                                    <?php if (count($errors) === 1): ?>
                                        <span><?= e($errors[0]) ?></span>
                                    <?php else: ?>
                                        <ul class="mb-0 ps-3">
                                            <?php foreach ($errors as $err): ?>
                                                <li><?= e($err) ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>

                        <form action="<?= url('forgot-password.php') ?>" method="POST" novalidate id="forgotForm">
                            <?= csrf_input() ?>

                            <div class="mb-4">
                                <label for="email" class="form-label fw-semibold small text-dark">
                                    Registered Email Address <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0 text-muted">
                                        <i class="bi bi-envelope"></i>
                                    </span>
                                    <input
                                        type="email"
                                        class="form-control border-start-0 ps-0"
                                        id="email"
                                        name="email"
                                        value="<?= e($email) ?>"
                                        placeholder="name@example.com"
                                        required
                                        autofocus
                                        autocomplete="email"
                                    >
                                </div>
                            </div>

                            <div class="d-grid mb-3">
                                <button type="submit" class="btn btn-primary py-2 fw-semibold shadow-sm" id="sendResetBtn">
                                    <i class="bi bi-send-fill me-2"></i>Send Reset Link
                                </button>
                            </div>
                        </form>

                        <div class="text-center pt-3 border-top mt-3">
                            <a href="<?= url('index.php') ?>" class="text-decoration-none small text-muted fw-medium">
                                <i class="bi bi-arrow-left me-1"></i>Back to Sign In
                            </a>
                        </div>

                    <?php endif; ?>

                </div>
            </div>

        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Password visibility toggler
    document.querySelectorAll('.toggle-password-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const targetSelector = this.getAttribute('data-target');
            const targetInput = document.querySelector(targetSelector);
            const icon = this.querySelector('i');
            
            if (targetInput) {
                if (targetInput.type === 'password') {
                    targetInput.type = 'text';
                    icon.classList.remove('bi-eye');
                    icon.classList.add('bi-eye-slash');
                } else {
                    targetInput.type = 'password';
                    icon.classList.remove('bi-eye-slash');
                    icon.classList.add('bi-eye');
                }
            }
        });
    });

    // Live jQuery Password Checklist for Reset Form
    if (window.jQuery && $('#password').length) {
        (function ($) {
            function updateRule(elementId, isValid) {
                const $rule = $(elementId);
                const $icon = $rule.find('.rule-icon');
                
                if (isValid) {
                    $rule.removeClass('text-muted text-danger').addClass('text-success fw-medium');
                    $icon.removeClass('bi-circle bi-x-circle-fill text-muted text-danger')
                         .addClass('bi-check-circle-fill text-success');
                } else {
                    $rule.removeClass('text-success fw-medium').addClass('text-muted');
                    $icon.removeClass('bi-check-circle-fill text-success bi-x-circle-fill text-danger')
                         .addClass('bi-circle text-muted');
                }
            }

            function validatePasswordFields() {
                const pass = $('#password').val() || '';
                const confirmPass = $('#confirmPassword').val() || '';

                updateRule('#rule-length', pass.length >= 8);
                updateRule('#rule-upper', /[A-Z]/.test(pass));
                updateRule('#rule-lower', /[a-z]/.test(pass));
                updateRule('#rule-number', /[0-9]/.test(pass));
                updateRule('#rule-special', /[\W_]/.test(pass));
                updateRule('#rule-match', pass.length > 0 && pass === confirmPass);
            }

            $('#password, #confirmPassword').on('input keyup change', validatePasswordFields);
            validatePasswordFields();
        })(window.jQuery);
    }
});
</script>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
