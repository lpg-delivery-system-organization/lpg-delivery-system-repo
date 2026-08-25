<?php
/**
 * Login Page
 * LPG Delivery System v2
 */

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/middleware.php';
require_once __DIR__ . '/classes/User.php';

// Redirect if already authenticated
if (is_logged_in()) {
    redirect_by_role();
}

$page_title = 'Sign In';
$errors = [];
$email = '';
$userModel = new User();

// Check if user just logged out
if (isset($_GET['logged_out']) && !has_flash()) {
    set_flash('info', 'You have been successfully signed out.');
}

// Handle Login POST Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitize_input($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $submittedCsrf = $_POST['csrf_token'] ?? null;

    // 1. Verify CSRF Token
    if (!verify_csrf($submittedCsrf)) {
        $errors[] = 'Your security token is invalid or expired. Please refresh and try again.';
    }

    // 2. Sliding Window Rate Limiting (5 attempts / 15 minutes)
    if (empty($errors) && !check_rate_limit('login', 5, 900)) {
        $errors[] = 'Too many failed login attempts. For security reasons, please try again in 15 minutes.';
    }

    // 3. Form Validation
    if (empty($errors)) {
        if ($email === '') {
            $errors[] = 'Email address is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please provide a valid email address.';
        }

        if ($password === '') {
            $errors[] = 'Password is required.';
        }
    }

    // 4. Authenticate User Credentials
    if (empty($errors)) {
        $user = $userModel->findByEmail($email);

        if (!$user || !password_verify($password, $user['password'])) {
            $remaining = get_rate_limit_remaining('login', 5, 900);
            $warning = ($remaining <= 2 && $remaining > 0)
                ? " ({$remaining} attempt" . ($remaining === 1 ? '' : 's') . " remaining before temporary lockout)"
                : "";
            $errors[] = 'Invalid email or password.' . $warning;
        } elseif ($user['status'] !== 'active') {
            $statusText = ($user['status'] === 'suspended') ? 'suspended' : 'inactive';
            $errors[] = "Your account has been {$statusText}. Please contact support for assistance.";
        } else {
            // Successful Login
            reset_rate_limit('login');
            login_user($user);
            set_flash('success', 'Welcome back, ' . $user['full_name'] . '!');
            redirect_by_role($user['role']);
        }
    }
}

require_once __DIR__ . '/templates/header.php';
?>

<div class="auth-login-page">
    <div class="auth-login-container">
        <!-- Branding Header -->
        <div class="text-center mb-4" data-aos="fade-down">
            <div class="auth-brand-logo mx-auto">
                <i class="bi bi-fire text-warning"></i>
            </div>
            <h1 class="h3 fw-bold text-dark mb-1">LPG Delivery System</h1>
            <p class="text-muted small">Fast, safe, and reliable LPG cylinder delivery</p>
        </div>

        <!-- Login Card -->
        <div class="auth-card p-4 p-md-5" data-aos="fade-up" data-aos-delay="100">
            
            <h2 class="h5 fw-bold text-dark mb-1">Sign In</h2>
            <p class="text-muted small mb-3">Enter your credentials to access your account</p>

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

            <form action="<?= url('index.php') ?>" method="POST" novalidate id="loginForm">
                <?= csrf_input() ?>

                <!-- Email Input -->
                <div class="mb-3">
                    <label for="email" class="form-label fw-semibold small text-dark">
                        Email Address <span class="text-danger">*</span>
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
                            autocomplete="username"
                        >
                    </div>
                </div>

                <!-- Password Input -->
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label for="password" class="form-label fw-semibold small text-dark mb-0">
                            Password <span class="text-danger">*</span>
                        </label>
                        <a href="<?= url('forgot-password.php') ?>" class="text-decoration-none small fw-medium" style="color: var(--app-primary);">
                            Forgot password?
                        </a>
                    </div>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0 text-muted">
                            <i class="bi bi-lock"></i>
                        </span>
                        <input
                            type="password"
                            class="form-control border-start-0 border-end-0 px-0"
                            id="password"
                            name="password"
                            placeholder="Enter your password"
                            required
                            autocomplete="current-password"
                        >
                        <button class="btn btn-outline-secondary border-start-0 bg-light text-muted toggle-password-btn" type="button" data-target="#password" aria-label="Toggle password visibility">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>

                <!-- Remember Me -->
                <div class="mb-4 form-check">
                    <input type="checkbox" class="form-check-input" id="rememberMe" name="remember_me" value="1">
                    <label class="form-check-label small text-muted user-select-none" for="rememberMe">
                        Keep me signed in on this device
                    </label>
                </div>

                <!-- Submit Button -->
                <div class="d-grid mb-3">
                    <button type="submit" class="btn btn-primary py-2 fw-semibold" id="loginBtn">
                        <span class="btn-text"><i class="bi bi-box-arrow-in-right me-2"></i>Sign In</span>
                    </button>
                </div>
            </form>

            <!-- Registration Link -->
            <div class="text-center pt-3 border-top mt-3">
                <p class="text-muted small mb-0">
                    Don't have an account yet?
                    <a href="<?= url('register.php') ?>" class="fw-semibold text-decoration-none ms-1" style="color: var(--app-primary);">
                        Create an account
                    </a>
                </p>
            </div>

            <!-- Demo Credentials -->
            <div class="mt-3 p-2 rounded-3" style="background: rgba(37, 99, 235, 0.04); border: 1px solid rgba(37, 99, 235, 0.08);">
                <div class="d-flex align-items-center mb-1 text-dark fw-semibold small">
                    <i class="bi bi-info-circle me-1" style="color: var(--app-primary);"></i>
                    Demo Credentials
                </div>
                <div class="extra-small text-muted">
                    <div class="mb-1"><span class="badge bg-primary me-1" style="font-size: 0.65rem;">Admin</span><code>admin@lpg.com</code> / <code>password</code></div>
                    <div class="mb-1"><span class="badge bg-success me-1" style="font-size: 0.65rem;">Rider</span><code>rider@lpg.com</code> / <code>password</code></div>
                    <div><span class="badge bg-info text-dark me-1" style="font-size: 0.65rem;">Customer</span><code>customer@lpg.com</code> / <code>password</code></div>
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
});
</script>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
