<?php
/**
 * Password Recovery (Forgot / Reset Password) Page
 * LPG Delivery System v2 — Standalone (no header/footer)
 */

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/middleware.php';
require_once __DIR__ . '/classes/User.php';
require_once __DIR__ . '/classes/Mailer.php';

if (is_logged_in()) {
    redirect_by_role();
}

$page_title = 'Password Recovery';
$errors = [];
$email = '';
$emailSent = false;
$userModel = new User();

$token = sanitize_input($_POST['token'] ?? $_GET['token'] ?? '');
$isTokenMode = !empty($token);
$resetRecord = null;

if ($isTokenMode) {
    $resetRecord = $userModel->verifyPasswordResetToken($token);
    $page_title = 'Set New Password';
}

// ── Handle New Password Submission (token mode) ────────────────────────────
if ($isTokenMode && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedCsrf = $_POST['csrf_token'] ?? null;
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (!verify_csrf($submittedCsrf)) {
        $errors[] = 'Your security token is invalid or expired. Please try again.';
    }
    if ($resetRecord === null) {
        $errors[] = 'The password reset token is invalid, expired, or has already been used.';
    }
    if (empty($errors)) {
        if ($password === '') {
            $errors[] = 'New password is required.';
        } else {
            if (strlen($password) < 8)                                    $errors[] = 'Password must be at least 8 characters.';
            if (!preg_match('/[A-Z]/', $password))                       $errors[] = 'Password must contain an uppercase letter.';
            if (!preg_match('/[a-z]/', $password))                       $errors[] = 'Password must contain a lowercase letter.';
            if (!preg_match('/[0-9]/', $password))                       $errors[] = 'Password must contain a number.';
            if (!preg_match('/[\W_]/', $password))                       $errors[] = 'Password must contain a special character.';
        }
        if ($password !== $confirmPassword) {
            $errors[] = 'Passwords do not match.';
        }
    }
    if (empty($errors) && $resetRecord !== null) {
        $updated = $userModel->updatePassword((int)$resetRecord['user_id'], $password);
        if ($updated) {
            $userModel->markPasswordResetUsed($token);
            set_flash('success', 'Your password has been reset! You can now sign in.');
            redirect('/index.php');
        } else {
            $errors[] = 'Failed to update password. Please try again.';
        }
    }
}

// ── Handle Reset Link Request (no token) ───────────────────────────────────
if (!$isTokenMode && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitize_input($_POST['email'] ?? '');
    $submittedCsrf = $_POST['csrf_token'] ?? null;

    if (!verify_csrf($submittedCsrf)) {
        $errors[] = 'Your security token is invalid or expired. Please try again.';
    }
    if (empty($errors) && !check_rate_limit('password_reset', 5, 900)) {
        $errors[] = 'Too many reset requests. Please wait 15 minutes.';
    }
    if (empty($errors)) {
        if ($email === '') {
            $errors[] = 'Email address is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please provide a valid email address.';
        }
    }
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_token() ?>">
    <title><?= e($page_title) ?> — <?= defined('APP_NAME') ? APP_NAME : 'LPG Delivery System' ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://unpkg.com/aos@2.3.4/dist/aos.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css">
    <style>
        body.auth-body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: #042f2e;
            background-image:
                radial-gradient(700px 380px at 85% -8%, rgba(251, 191, 36, 0.16), transparent 60%),
                radial-gradient(560px 320px at 8% 108%, rgba(20, 184, 166, 0.18), transparent 60%),
                linear-gradient(150deg, #042f2e 0%, #0b3b36 35%, #0d9488 78%, #14b8a6 100%);
            background-attachment: fixed;
            padding: 1.5rem 1rem;
            font-family: 'Inter', sans-serif;
            color: #f0fdfa;
        }
        .auth-wrapper {
            width: 100%;
            max-width: 440px;
        }
        .auth-brand {
            text-align: center;
            margin-bottom: 1.5rem;
        }
        .auth-brand h1 { color: #fff !important; }
        .auth-brand p { color: rgba(255,255,255,0.7) !important; }
        .auth-brand-icon {
            width: 72px;
            height: 72px;
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 1.25rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 2.25rem;
            color: #fbbf24;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25);
            margin-bottom: 1rem;
        }
        .auth-card {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.16);
            border-radius: 1.5rem;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.18);
            overflow: hidden;
            color: #f0fdfa;
        }
        /* Light text over the transparent card */
        .auth-card .text-dark { color: #f0fdfa !important; }
        .auth-card .text-muted { color: rgba(255,255,255,0.65) !important; }
        .auth-card .bg-light {
            background-color: rgba(255,255,255,0.08) !important;
            border-color: rgba(255,255,255,0.12) !important;
        }
        .auth-card .input-group-text { color: rgba(255,255,255,0.7); }
        .auth-card .input-group-text .text-muted { color: rgba(255,255,255,0.7) !important; }
        .auth-card .btn-outline-secondary {
            background: rgba(255,255,255,0.08);
            border-color: rgba(255,255,255,0.16);
            color: #f0fdfa;
        }
        .auth-card .btn-outline-secondary:hover {
            background: rgba(255,255,255,0.16);
            border-color: rgba(255,255,255,0.25);
            color: #fff;
        }
        .auth-form-body {
            padding: 1.75rem 1.75rem 2rem;
        }
        .auth-form-body .form-control,
        .auth-form-body .form-select {
            border-radius: 0.625rem;
            padding: 0.65rem 0.85rem;
            border-color: #e2e8f0;
            font-size: 0.9rem;
        }
        .auth-form-body .form-control:focus {
            border-color: #0d9488;
            box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.1);
        }
        .auth-form-body .input-group-text {
            border-color: #e2e8f0;
            font-size: 0.9rem;
        }
        .auth-form-body .btn-primary {
            border-radius: 0.625rem;
            padding: 0.7rem;
            font-weight: 700;
            background: linear-gradient(135deg, #0d9488, #14b8a6);
            border: none;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.25);
            transition: transform 0.15s ease, box-shadow 0.2s ease;
        }
        .auth-form-body .btn-primary:hover {
            background: linear-gradient(135deg, #0f766e, #0d9488);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.35);
            transform: translateY(-1px);
        }
        .pw-rule { font-size: 0.8rem; padding: 2px 0; }
        .pw-rule .rule-icon { font-size: 0.75rem; width: 1rem; text-align: center; }
        .auth-back-link {
            text-align: center;
            margin-top: 1rem;
        }
        .auth-back-link a {
            color: #fbbf24;
            font-size: 0.85rem;
            font-weight: 600;
            text-decoration: none;
        }
        .auth-back-link a:hover {
            color: #fcd34d;
            text-decoration: underline;
        }
        @media (max-width: 575.98px) {
            .auth-form-body { padding: 1.25rem 1rem 1.5rem; }
            .auth-brand-icon { width: 60px; height: 60px; font-size: 1.85rem; border-radius: 1rem; }
        }
        [data-theme="dark"] body.auth-body { background: linear-gradient(135deg, #020617 0%, #0b3b36 50%, #020617 100%); }
        [data-theme="dark"] .auth-card { background: rgba(255, 255, 255, 0.06); border-color: rgba(255,255,255,0.12); box-shadow: 0 8px 32px rgba(0,0,0,0.35); }
        [data-theme="dark"] .auth-brand h1, [data-theme="dark"] .auth-brand p { color: #e2e8f0; }
        [data-theme="dark"] .auth-brand p { color: #94a3b8; }
        [data-theme="dark"] .form-floating > .form-control, [data-theme="dark"] .form-floating > .form-select { background: #0f172a; border-color: #334155; color: #e2e8f0; }
        [data-theme="dark"] .form-floating > label { color: #94a3b8; }
        [data-theme="dark"] .form-floating > .form-control:focus ~ label, [data-theme="dark"] .form-floating > .form-control:not(:placeholder-shown) ~ label { color: #14b8a6; }
        [data-theme="dark"] .auth-back-link { color: #94a3b8; }
        [data-theme="dark"] .auth-back-link a { color: #14b8a6; }
        [data-theme="dark"] .auth-brand-icon { box-shadow: 0 8px 24px rgba(20, 184, 166, 0.25); }
        [data-theme="dark"] .form-control { background: #0f172a; border-color: #334155; color: #e2e8f0; }
        [data-theme="dark"] .form-control:focus { background: #0f172a; color: #e2e8f0; border-color: #14b8a6; box-shadow: 0 0 0 3px rgba(20,184,166,0.15); }
    </style>
</head>
<body class="auth-body">

<div class="auth-wrapper" data-aos="fade-up" data-aos-duration="600">
    <!-- Branding -->
    <div class="auth-brand" data-aos="fade-down" data-aos-delay="100">
        <div class="auth-brand-icon"><i class="bi bi-shield-lock"></i></div>
        <h1 class="h4 fw-bold text-dark mb-0">Account Recovery</h1>
        <p class="text-muted small mt-1 mb-0">Recover access to your LPG delivery account</p>
    </div>

    <div class="auth-card">
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show d-flex align-items-start mx-3 mt-3 mb-0 shadow-sm" role="alert">
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

        <div class="auth-form-body">

            <?php if ($isTokenMode): ?>

                <?php if ($resetRecord === null): ?>
                    <!-- Invalid / Expired Token -->
                    <div class="text-center py-2">
                        <div class="text-danger mb-3">
                            <i class="bi bi-exclamation-octagon-fill" style="font-size:2.5rem;"></i>
                        </div>
                        <h2 class="h5 fw-bold text-dark mb-2">Invalid or Expired Link</h2>
                        <p class="text-muted small mb-4">
                            This reset link is invalid, has expired (valid 60 min), or was already used. Please request a new one.
                        </p>
                        <a href="<?= url('forgot-password.php') ?>" class="btn btn-primary w-100 py-2 fw-semibold">
                            <i class="bi bi-arrow-repeat me-2"></i>Request New Reset Link
                        </a>
                    </div>

                <?php else: ?>
                    <!-- Set New Password -->
                    <h2 class="h5 fw-bold text-dark mb-1">Set New Password</h2>
                    <p class="text-muted small mb-3">Resetting password for: <strong class="text-dark"><?= e($resetRecord['email']) ?></strong></p>

                    <form action="<?= url('forgot-password.php?token=' . urlencode($token)) ?>" method="POST" novalidate>
                        <input type="hidden" name="token" value="<?= e($token) ?>">
                        <?= csrf_input() ?>

                        <div class="mb-3">
                            <label for="newPw" class="form-label fw-semibold small text-dark">New Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-lock text-muted"></i></span>
                                <input type="password" class="form-control border-start-0 border-end-0 px-0" id="newPw" name="password" placeholder="Enter new password" required autocomplete="new-password" autofocus>
                                <button class="btn btn-outline-secondary border-start-0 bg-light text-muted toggle-pw" type="button" data-target="#newPw"><i class="bi bi-eye"></i></button>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="confirmPw" class="form-label fw-semibold small text-dark">Confirm Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-lock-fill text-muted"></i></span>
                                <input type="password" class="form-control border-start-0 border-end-0 px-0" id="confirmPw" name="confirm_password" placeholder="Confirm new password" required autocomplete="new-password">
                                <button class="btn btn-outline-secondary border-start-0 bg-light text-muted toggle-pw" type="button" data-target="#confirmPw"><i class="bi bi-eye"></i></button>
                            </div>
                        </div>

                        <div class="mb-3 p-2 rounded-3 border" style="background:rgba(13,148,136,0.03);">
                            <div class="fw-semibold text-dark mb-1 small">Password Requirements</div>
                            <div class="row g-1">
                                <div class="col-6"><div id="rule-length" class="pw-rule d-flex align-items-center text-muted"><i class="bi bi-circle rule-icon me-1 text-muted"></i><span>8+ characters</span></div></div>
                                <div class="col-6"><div id="rule-upper" class="pw-rule d-flex align-items-center text-muted"><i class="bi bi-circle rule-icon me-1 text-muted"></i><span>1 uppercase</span></div></div>
                                <div class="col-6"><div id="rule-lower" class="pw-rule d-flex align-items-center text-muted"><i class="bi bi-circle rule-icon me-1 text-muted"></i><span>1 lowercase</span></div></div>
                                <div class="col-6"><div id="rule-number" class="pw-rule d-flex align-items-center text-muted"><i class="bi bi-circle rule-icon me-1 text-muted"></i><span>1 number</span></div></div>
                                <div class="col-6"><div id="rule-special" class="pw-rule d-flex align-items-center text-muted"><i class="bi bi-circle rule-icon me-1 text-muted"></i><span>1 special char</span></div></div>
                                <div class="col-6"><div id="rule-match" class="pw-rule d-flex align-items-center text-muted"><i class="bi bi-circle rule-icon me-1 text-muted"></i><span>Passwords match</span></div></div>
                            </div>
                        </div>

                        <div class="d-grid mb-3">
                            <button type="submit" class="btn btn-primary py-2 fw-semibold"><i class="bi bi-check2-circle me-2"></i>Update Password</button>
                        </div>
                    </form>
                <?php endif; ?>

            <?php elseif ($emailSent): ?>
                <!-- Email Sent Confirmation -->
                <div class="text-center py-2">
                    <div class="text-success mb-3">
                        <i class="bi bi-envelope-check-fill" style="font-size:2.5rem;"></i>
                    </div>
                    <h2 class="h5 fw-bold text-dark mb-2">Check Your Inbox</h2>
                    <p class="text-muted small mb-4">
                        If an account exists for <strong class="text-dark"><?= e($email) ?></strong>, we sent a secure reset link. Check your inbox and spam folder.
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
                <!-- Request Reset Form -->
                <h2 class="h5 fw-bold text-dark mb-1">Forgot Password</h2>
                <p class="text-muted small mb-3">Enter the email associated with your account and we'll send you a reset link.</p>

                <form action="<?= url('forgot-password.php') ?>" method="POST" novalidate>
                    <?= csrf_input() ?>

                    <div class="mb-4">
                        <label for="resetEmail" class="form-label fw-semibold small text-dark">Email Address <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="bi bi-envelope text-muted"></i></span>
                            <input type="email" class="form-control border-start-0 ps-0" id="resetEmail" name="email" value="<?= e($email) ?>" placeholder="name@example.com" required autofocus autocomplete="email">
                        </div>
                    </div>

                    <div class="d-grid mb-3">
                        <button type="submit" class="btn btn-primary py-2 fw-semibold"><i class="bi bi-send-fill me-2"></i>Send Reset Link</button>
                    </div>

                    <div class="text-center">
                        <a href="<?= url('index.php') ?>" class="btn btn-outline-secondary px-4 fw-semibold">
                            <i class="bi bi-arrow-left me-1"></i>Back to Sign In
                        </a>
                    </div>
                </form>
            <?php endif; ?>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="https://unpkg.com/aos@2.3.4/dist/aos.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    AOS.init({ duration: 500, easing: 'ease-out-cubic', once: true });

    // Password visibility toggler
    document.querySelectorAll('.toggle-pw').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = document.querySelector(this.getAttribute('data-target'));
            var icon = this.querySelector('i');
            if (input) {
                input.type = input.type === 'password' ? 'text' : 'password';
                icon.classList.toggle('bi-eye');
                icon.classList.toggle('bi-eye-slash');
            }
        });
    });

    // Password strength checklist
    function updateRule(id, valid) {
        var el = document.querySelector(id);
        if (!el) return;
        var icon = el.querySelector('.rule-icon');
        if (valid) {
            el.className = 'pw-rule d-flex align-items-center text-success fw-medium';
            icon.className = 'bi bi-check-circle-fill rule-icon me-1 text-success';
        } else {
            el.className = 'pw-rule d-flex align-items-center text-muted';
            icon.className = 'bi bi-circle rule-icon me-1 text-muted';
        }
    }
    function checkPw() {
        var p = document.getElementById('newPw');
        var c = document.getElementById('confirmPw');
        if (!p) return;
        var pv = p.value, cv = c ? c.value : '';
        updateRule('#rule-length',  pv.length >= 8);
        updateRule('#rule-upper',   /[A-Z]/.test(pv));
        updateRule('#rule-lower',   /[a-z]/.test(pv));
        updateRule('#rule-number',  /[0-9]/.test(pv));
        updateRule('#rule-special', /[\W_]/.test(pv));
        updateRule('#rule-match',   pv.length > 0 && pv === cv);
    }
    ['newPw','confirmPw'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('input', checkPw);
    });
    checkPw();
});
</script>
</body>
</html>
