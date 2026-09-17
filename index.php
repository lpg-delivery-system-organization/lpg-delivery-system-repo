<?php
/**
 * Combined Auth Page — Sign In & Register
 * LPG Delivery System v2
 */

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/middleware.php';
require_once __DIR__ . '/classes/User.php';

if (is_logged_in()) {
    redirect_by_role();
}

$userModel = new User();
$page_title = 'Sign In';
$errors = [];
$activeTab = 'login';

// ── Login POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['auth_action'] ?? '') === 'login') {
    $activeTab = 'login';
    $page_title = 'Sign In';
    $loginEmail = sanitize_input($_POST['email'] ?? '');
    $loginPassword = $_POST['password'] ?? '';
    $submittedCsrf = $_POST['csrf_token'] ?? null;

    if (!verify_csrf($submittedCsrf)) {
        $errors[] = 'Your security token is invalid or expired. Please refresh and try again.';
    }

    if (empty($errors) && !check_rate_limit('login', 5, 900)) {
        $errors[] = 'Too many failed login attempts. Please try again in 15 minutes.';
    }

    if (empty($errors)) {
        if ($loginEmail === '') {
            $errors[] = 'Email address is required.';
        } elseif (!filter_var($loginEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please provide a valid email address.';
        }
        if ($loginPassword === '') {
            $errors[] = 'Password is required.';
        }
    }

    if (empty($errors)) {
        $user = $userModel->findByEmail($loginEmail);
        if (!$user || !password_verify($loginPassword, $user['password'])) {
            $remaining = get_rate_limit_remaining('login', 5, 900);
            $warning = ($remaining <= 2 && $remaining > 0)
                ? " ({$remaining} attempt" . ($remaining === 1 ? '' : 's') . " remaining)"
                : "";
            $errors[] = 'Invalid email or password.' . $warning;
        } elseif ($user['status'] !== 'active') {
            $statusText = ($user['status'] === 'suspended') ? 'suspended' : 'inactive';
            $errors[] = "Your account has been {$statusText}. Please contact support.";
        } else {
            reset_rate_limit('login');
            login_user($user);
            set_flash('success', 'Welcome back, ' . $user['full_name'] . '!');
            redirect_by_role($user['role'], true);
        }
    }
}

// ── Register POST ───────────────────────────────────────────────────────────
$regFullName = '';
$regEmail = '';
$regPhone = '';
$regAddress = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['auth_action'] ?? '') === 'register') {
    $activeTab = 'register';
    $page_title = 'Register';
    $regFullName = sanitize_input($_POST['full_name'] ?? '');
    $regEmail = sanitize_input($_POST['email'] ?? '');
    $regPhone = sanitize_input($_POST['phone'] ?? '');
    $regAddress = sanitize_input($_POST['address'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $submittedCsrf = $_POST['csrf_token'] ?? null;

    if (!verify_csrf($submittedCsrf)) {
        $errors[] = 'Your security token is invalid or expired. Please refresh and try again.';
    }
    if ($regFullName === '') {
        $errors[] = 'Full name is required.';
    } elseif (mb_strlen($regFullName) < 2 || mb_strlen($regFullName) > 100) {
        $errors[] = 'Full name must be between 2 and 100 characters.';
    }
    if ($regEmail === '') {
        $errors[] = 'Email address is required.';
    } elseif (!filter_var($regEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please provide a valid email address.';
    } elseif ($userModel->emailExists($regEmail)) {
        $errors[] = 'An account with this email already exists.';
    }
    if ($regPhone === '') {
        $errors[] = 'Contact phone number is required.';
    } elseif (!preg_match('/^09\d{9}$/', $regPhone)) {
        $errors[] = 'Phone number must be an 11-digit Philippine mobile (09XXXXXXXXX).';
    }
    if ($regAddress === '') {
        $errors[] = 'Delivery address is required.';
    } elseif (mb_strlen($regAddress) < 5) {
        $errors[] = 'Please provide a complete delivery address.';
    }
    if ($password === '') {
        $errors[] = 'Password is required.';
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

    $validIdPath = null;
    if (isset($_FILES['valid_id']) && $_FILES['valid_id']['error'] !== UPLOAD_ERR_NO_FILE) {
        $uploadResult = validate_id_upload($_FILES['valid_id']);
        if (!$uploadResult['success']) {
            $errors[] = 'ID upload failed: ' . $uploadResult['error'];
        } else {
            $validIdPath = $uploadResult['relative_path'];
        }
    } else {
        $errors[] = 'A valid government ID photo is required.';
    }

    if (empty($errors)) {
        try {
            $userModel->create([
                'full_name'     => $regFullName,
                'email'         => $regEmail,
                'password'      => $password,
                'role'          => 'customer',
                'phone'         => $regPhone,
                'address'       => $regAddress,
                'valid_id_path' => $validIdPath,
                'status'        => 'active'
            ]);
            set_flash('success', 'Registration successful! You may now sign in.');
            redirect('/index.php');
        } catch (Throwable $e) {
            $errors[] = 'Registration failed: ' . $e->getMessage();
        }
    }
}

// Auto-select register tab if linked from register.php or had errors
if (isset($_GET['tab']) && $_GET['tab'] === 'register') {
    $activeTab = 'register';
    $page_title = 'Register';
}

// Consume any flash message (e.g. "signed out" after logout, or
// "registration successful") so it can pop up as a toast on arrival.
$flash = get_flash();
$flashType = $flash['type'] ?? '';
if ($flashType === 'error') $flashType = 'danger';
if (!in_array($flashType, ['success', 'danger', 'warning', 'info'], true)) $flashType = 'info';
$flashIcons = [
    'success' => 'bi-check-circle-fill',
    'danger'  => 'bi-exclamation-triangle-fill',
    'warning' => 'bi-exclamation-circle-fill',
    'info'    => 'bi-info-circle-fill',
];
$flashIcon = $flashIcons[$flashType];
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
    <script>
    (function(){try{var s=localStorage.getItem('app-theme');if(s==='dark')document.documentElement.setAttribute('data-theme','dark');}catch(e){}})();
    </script>
    <style>
        body.auth-body {
            min-height: 100vh;
            font-family: 'Inter', sans-serif;
            background-color: #042f2e;
            background-image:
                radial-gradient(700px 380px at 85% -8%, rgba(251, 191, 36, 0.16), transparent 60%),
                radial-gradient(560px 320px at 8% 108%, rgba(20, 184, 166, 0.18), transparent 60%),
                linear-gradient(150deg, #042f2e 0%, #0b3b36 35%, #0d9488 78%, #14b8a6 100%);
            background-attachment: fixed;
            color: #f0fdfa;
        }
        /* ── Split layout ─────────────────────────────── */
        .auth-split {
            display: flex;
            min-height: 100vh;
            width: 100%;
        }
        /* ── Brand showcase (left) ────────────────────── */
        .auth-showcase {
            flex: 1.05;
            position: relative;
            overflow: hidden;
            display: none;
            flex-direction: column;
            justify-content: space-between;
            gap: 2rem;
            padding: 3rem 3rem 2.5rem;
            color: #fff;
            background: transparent;
            border-right: 1px solid rgba(255,255,255,0.12);
        }
        @media (min-width: 992px) {
            .auth-showcase { display: flex; }
        }
        .auth-showcase::before,
        .auth-showcase::after {
            content: '';
            position: absolute;
            border-radius: 50%;
            pointer-events: none;
        }
        .auth-showcase::before {
            width: 480px; height: 480px;
            top: -140px; right: -140px;
            background: radial-gradient(circle, rgba(251,191,36,0.28) 0%, rgba(251,191,36,0.06) 45%, transparent 70%);
        }
        .auth-showcase::after {
            width: 420px; height: 420px;
            bottom: -160px; left: -120px;
            background: radial-gradient(circle, rgba(255,255,255,0.14) 0%, transparent 65%);
        }
        .auth-showcase-dots {
            position: absolute; inset: 0;
            background-image: radial-gradient(rgba(255,255,255,0.13) 1px, transparent 1px);
            background-size: 24px 24px;
            -webkit-mask-image: linear-gradient(to bottom, black 0%, transparent 80%);
            mask-image: linear-gradient(to bottom, black 0%, transparent 80%);
            pointer-events: none;
        }
        .auth-showcase > *:not(.auth-showcase-dots):not(.auth-showcase-flame) {
            position: relative;
            z-index: 1;
        }
        .auth-showcase-brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .auth-showcase-logo {
            width: 52px; height: 52px;
            border-radius: 1rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
            color: #fbbf24;
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.2);
            box-shadow: 0 8px 24px rgba(0,0,0,0.25);
        }
        .auth-showcase-hero h2 {
            font-weight: 800;
            font-size: clamp(1.75rem, 2.6vw, 2.5rem);
            line-height: 1.15;
            margin-bottom: 0.75rem;
        }
        .auth-showcase-hero h2 .text-accent { color: #fbbf24; }
        .auth-showcase-hero p {
            color: rgba(255,255,255,0.75);
            font-size: 1rem;
            max-width: 26rem;
            margin-bottom: 0;
        }
        .auth-feature-list {
            list-style: none;
            padding: 0;
            margin: 1.75rem 0 0;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .auth-feature-list li {
            display: flex;
            align-items: flex-start;
            gap: 0.85rem;
        }
        .auth-feature-icon {
            width: 44px; height: 44px;
            flex-shrink: 0;
            border-radius: 0.85rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.18);
        }
        .auth-feature-list strong { display: block; font-size: 0.95rem; }
        .auth-feature-list span { font-size: 0.83rem; color: rgba(255,255,255,0.7); }
        .auth-showcase-stats {
            display: flex;
            gap: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(255,255,255,0.18);
        }
        .auth-showcase-stats .stat b { display: block; font-size: 1.35rem; }
        .auth-showcase-stats .stat small { color: rgba(255,255,255,0.65); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.06em; }
        .auth-showcase-flame {
            position: absolute;
            right: -40px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 22rem;
            line-height: 1;
            color: rgba(255,255,255,0.05);
            pointer-events: none;
        }
        /* ── Form side (right) ────────────────────────── */
        .auth-form-side {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1.25rem;
            min-width: 0;
        }
        @media (min-width: 992px) {
            .auth-form-side {
                max-height: 100vh;
                overflow-y: auto;
                padding: 2rem clamp(1.5rem, 4vw, 4rem);
            }
        }
        .auth-wrapper {
            width: 100%;
            max-width: 440px;
            margin: auto;
        }
        .auth-mobile-brand {
            text-align: center;
            margin-bottom: 1.25rem;
        }
        @media (min-width: 992px) {
            .auth-mobile-brand { display: none; }
        }
        .auth-brand {
            text-align: center;
            margin-bottom: 1.5rem;
        }
        .auth-brand-icon {
            width: 72px;
            height: 72px;
            background: linear-gradient(135deg, #0d9488, #14b8a6);
            border-radius: 1.25rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 2.25rem;
            color: #fbbf24;
            box-shadow: 0 8px 24px rgba(13, 148, 136, 0.3);
            margin-bottom: 1rem;
        }
        .auth-card {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.16);
            border-radius: 1.5rem;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.18);
            overflow: hidden;
            color: #f0fdfa;
            container-type: inline-size;
        }
        /* Register 2-column fields follow CARD width (not viewport) so they
           stack single-column instead of squeezing inside the 440px card.
           Older browsers ignore this and keep default Bootstrap behavior. */
        @container (max-width: 499.98px) {
            .auth-card #registerForm .row.g-3 > .col-md-6 { width: 100%; }
        }
        /* Light text over the dark glass card */
        body.auth-body .auth-card .text-dark { color: #f0fdfa !important; }
        body.auth-body .auth-card .text-muted { color: rgba(255,255,255,0.65) !important; }
        body.auth-body .auth-card .form-text { color: rgba(255,255,255,0.6) !important; }
        body.auth-body .auth-card .bg-light {
            background-color: rgba(255,255,255,0.08) !important;
            border-color: rgba(255,255,255,0.12) !important;
        }
        body.auth-body .auth-card .input-group-text { color: rgba(255,255,255,0.7); }
        body.auth-body .auth-card .input-group-text .text-muted { color: rgba(255,255,255,0.7) !important; }
        body.auth-body .auth-card .btn-outline-secondary {
            background: rgba(255,255,255,0.08);
            border-color: rgba(255,255,255,0.12);
            color: rgba(255,255,255,0.75);
        }
        body.auth-body .auth-mobile-brand h1 { color: #fff !important; }
        body.auth-body .auth-mobile-brand p { color: rgba(255,255,255,0.7) !important; }
        body.auth-body .auth-form-body a[href*="forgot-password"] { color: #5eead4 !important; }
        /* Segmented Sign in / Register switcher */
        .auth-segment {
            display: flex;
            gap: 0.35rem;
            padding: 0.4rem;
            margin: 1rem 1rem 0;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 1rem;
        }
        .auth-segment .nav-item { flex: 1; }
        .auth-segment .nav-link {
            width: 100%;
            font-weight: 600;
            font-size: 0.9rem;
            color: rgba(255,255,255,0.65);
            border: none;
            border-radius: 0.7rem;
            padding: 0.65rem 1rem;
            transition: all 0.2s ease;
        }
        .auth-segment .nav-link:hover:not(.active) { color: #fff; }
        .auth-segment .nav-link.active {
            color: #0b3b36;
            background: #fff;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.25);
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
            box-shadow: 0 4px 14px rgba(13, 148, 136, 0.3);
            transition: transform 0.15s ease, box-shadow 0.2s ease;
        }
        .auth-form-body .btn-primary:hover {
            background: linear-gradient(135deg, #0f766e, #0d9488);
            box-shadow: 0 6px 20px rgba(13, 148, 136, 0.4);
            transform: translateY(-1px);
        }
        .auth-demo-box {
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 0.75rem;
            padding: 0.75rem;
        }
        body.auth-body .auth-demo-box .text-muted { color: rgba(255,255,255,0.65) !important; }
        .auth-footer-links {
            text-align: center;
            margin-top: 1rem;
            font-size: 0.85rem;
            color: rgba(255,255,255,0.65);
        }
        .auth-footer-links a {
            color: #fbbf24;
            font-weight: 600;
            text-decoration: none;
        }
        .auth-footer-links a:hover {
            text-decoration: underline;
        }
        .pw-rule { font-size: 0.8rem; padding: 2px 0; }
        .pw-rule .rule-icon { font-size: 0.75rem; width: 1rem; text-align: center; }
        .id-camera-container { border-radius: 0.5rem; }
        @media (max-width: 575.98px) {
            .auth-form-body { padding: 1.25rem 1rem 1.5rem; }
            .auth-brand-icon { width: 60px; height: 60px; font-size: 1.85rem; border-radius: 1rem; }
        }

        /* Auth page dark mode */
        [data-theme="dark"] body.auth-body { background: linear-gradient(135deg, #020617 0%, #0b3b36 50%, #020617 100%); background-size: 300% 300%; animation: bgShift 15s ease infinite; overflow: hidden; }
        [data-theme="dark"] .auth-card { background: rgba(255, 255, 255, 0.06); border-color: rgba(255,255,255,0.12); box-shadow: 0 8px 32px rgba(0,0,0,0.35); }
        [data-theme="dark"] .auth-brand h1,
        [data-theme="dark"] .auth-brand p { color: #e2e8f0; }
        [data-theme="dark"] .auth-brand p { color: #94a3b8; }
        [data-theme="dark"] .nav-tabs .nav-link { color: #94a3b8; border-color: #334155; background: transparent; }
        [data-theme="dark"] .nav-tabs .nav-link.active { background: #1e293b; color: #14b8a6; border-color: #334155; border-bottom-color: #1e293b; }
        [data-theme="dark"] .nav-tabs { border-color: #334155; }
        [data-theme="dark"] .form-floating > .form-control,
        [data-theme="dark"] .form-floating > .form-select { background: #0f172a; border-color: #334155; color: #e2e8f0; }
        [data-theme="dark"] .form-floating > label { color: #94a3b8; }
        [data-theme="dark"] .form-floating > .form-control:focus ~ label,
        [data-theme="dark"] .form-floating > .form-control:not(:placeholder-shown) ~ label { color: #14b8a6; }
        [data-theme="dark"] .pw-meter { background: #0f172a; }
        [data-theme="dark"] .pw-rule { color: #94a3b8; }
        [data-theme="dark"] .auth-footer-links { color: #94a3b8; }
        [data-theme="dark"] .auth-footer-links a { color: #14b8a6; }
        [data-theme="dark"] .reg-scroll::-webkit-scrollbar-thumb { background: #475569; }
        [data-theme="dark"] .form-control { background: #0f172a; border-color: #334155; color: #e2e8f0; }
        [data-theme="dark"] .form-control:focus { background: #0f172a; color: #e2e8f0; border-color: #14b8a6; box-shadow: 0 0 0 3px rgba(20,184,166,0.15); }
        [data-theme="dark"] .form-select { background: #0f172a; border-color: #334155; color: #e2e8f0; }
        [data-theme="dark"] .id-camera-container { border-color: #334155; }
        [data-theme="dark"] .auth-brand-icon { box-shadow: 0 8px 24px rgba(20, 184, 166, 0.25); }

        [data-theme="dark"] .auth-segment {
            background: #0f172a;
            border: 1px solid #334155;
        }
        [data-theme="dark"] .auth-segment .nav-link { color: #94a3b8; }
        [data-theme="dark"] .auth-segment .nav-link:hover:not(.active) { color: #14b8a6; }
        [data-theme="dark"] .auth-segment .nav-link.active { background: #1e293b; color: #14b8a6; box-shadow: 0 2px 8px rgba(0,0,0,0.4); }
        [data-theme="dark"] .auth-demo-box { background: rgba(20,184,166,0.06); border-color: rgba(20,184,166,0.15); }
        [data-theme="dark"] .auth-form-body .input-group-text { background: #0f172a; border-color: #334155; color: #94a3b8; }
        [data-theme="dark"] .auth-form-body .btn-outline-secondary { background: #0f172a; border-color: #334155; color: #94a3b8; }

        /* === Dark mode glow effects === */
        @keyframes bgShift {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }

        [data-theme="dark"] body.auth-body::before,
        [data-theme="dark"] body.auth-body::after {
            content: '';
            position: fixed;
            border-radius: 50%;
            z-index: -1;
            pointer-events: none;
        }

        [data-theme="dark"] body.auth-body::before {
            width: 600px; height: 600px;
            top: -10%; left: -10%;
            background: radial-gradient(circle, rgba(20,184,166,0.15) 0%, rgba(20,184,166,0.05) 40%, transparent 70%);
            animation: glowOrbLeft 8s ease-in-out infinite;
        }

        [data-theme="dark"] body.auth-body::after {
            width: 500px; height: 500px;
            bottom: -10%; right: -10%;
            background: radial-gradient(circle, rgba(251,191,36,0.12) 0%, rgba(251,191,36,0.04) 40%, transparent 70%);
            animation: glowOrbRight 10s ease-in-out infinite;
        }

        @keyframes glowOrbLeft {
            0%, 100% { transform: translate(0, 0) scale(1); opacity: 0.6; }
            50% { transform: translate(60px, 40px) scale(1.15); opacity: 1; }
        }

        @keyframes glowOrbRight {
            0%, 100% { transform: translate(0, 0) scale(1); opacity: 0.5; }
            50% { transform: translate(-50px, -30px) scale(1.1); opacity: 0.9; }
        }

        /* Pulsing card glow */
        [data-theme="dark"] .auth-card {
            position: relative; z-index: 1;
            animation: cardPulse 4s ease-in-out infinite;
        }

        @keyframes cardPulse {
            0%, 100% { box-shadow: 0 8px 32px rgba(0,0,0,0.4), 0 0 0 0 rgba(20,184,166,0); }
            50% { box-shadow: 0 8px 32px rgba(0,0,0,0.4), 0 0 40px 4px rgba(20,184,166,0.15); }
        }
    </style>
</head>
<body class="auth-body">

<div class="auth-split">
    <!-- ═══════════ Brand showcase (desktop) ═══════════ -->
    <aside class="auth-showcase" data-aos="fade-right" data-aos-duration="700">
        <div class="auth-showcase-dots"></div>
        <i class="bi bi-fire auth-showcase-flame"></i>
        <div class="auth-showcase-brand">
            <span class="auth-showcase-logo"><i class="bi bi-fire"></i></span>
            <div>
                <div class="fw-bold fs-5 lh-1">LPG Delivery System</div>
                <small style="color: rgba(255,255,255,0.65);">Fast • Safe • Reliable</small>
            </div>
        </div>

        <div class="auth-showcase-hero">
            <h2>Cylinder refills, <span class="text-accent">delivered</span> to your door.</h2>
            <p>Order genuine LPG cylinders in seconds and track your rider in real time — from warehouse to doorstep.</p>
            <ul class="auth-feature-list">
                <li>
                    <span class="auth-feature-icon"><i class="bi bi-lightning-charge-fill"></i></span>
                    <div><strong>Same-day delivery</strong><span>Dispatch to nearby riders in seconds.</span></div>
                </li>
                <li>
                    <span class="auth-feature-icon"><i class="bi bi-geo-alt-fill"></i></span>
                    <div><strong>Live order tracking</strong><span>Watch your cylinder arrive on the map.</span></div>
                </li>
                <li>
                    <span class="auth-feature-icon"><i class="bi bi-shield-check"></i></span>
                    <div><strong>Verified & secure</strong><span>ID-verified accounts, safe cashless payment.</span></div>
                </li>
            </ul>
        </div>

        <div class="auth-showcase-stats">
            <div class="stat"><b>11kg–50kg</b><small>Cylinder sizes</small></div>
            <div class="stat"><b>24/7</b><small>Ordering</small></div>
            <div class="stat"><b>100%</b><small>Genuine LPG</small></div>
        </div>
    </aside>

    <!-- ═══════════ Form side ═══════════ -->
    <div class="auth-form-side">
<div class="auth-wrapper" data-aos="fade-up" data-aos-duration="600">
    <!-- Branding (mobile / tablet only) -->
    <div class="auth-mobile-brand" data-aos="fade-down" data-aos-delay="100">
        <div class="auth-brand-icon"><i class="bi bi-fire"></i></div>
        <h1 class="h4 fw-bold text-dark mb-0">LPG Delivery System</h1>
        <p class="text-muted small mt-1 mb-0">Fast, safe, and reliable LPG cylinder delivery</p>
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

        <!-- Segmented switcher -->
        <ul class="nav auth-tabs auth-segment" role="tablist">
            <li class="nav-item flex-fill text-center" role="presentation">
                <button class="nav-link <?= $activeTab === 'login' ? 'active' : '' ?>" id="tab-login" data-bs-toggle="tab" data-bs-target="#panel-login" type="button" role="tab">
                    <i class="bi bi-box-arrow-in-right me-1"></i>Sign In
                </button>
            </li>
            <li class="nav-item flex-fill text-center" role="presentation">
                <button class="nav-link <?= $activeTab === 'register' ? 'active' : '' ?>" id="tab-register" data-bs-toggle="tab" data-bs-target="#panel-register" type="button" role="tab">
                    <i class="bi bi-person-plus me-1"></i>Register
                </button>
            </li>
        </ul>

        <div class="tab-content">
            <!-- ═══════════════════════════════════════════════════════════════
                 LOGIN PANEL
                 ═══════════════════════════════════════════════════════════════ -->
            <div class="tab-pane fade <?= $activeTab === 'login' ? 'show active' : '' ?>" id="panel-login" role="tabpanel">
                <div class="auth-form-body">
                    <h2 class="h5 fw-bold text-dark mb-1">Welcome back</h2>
                    <p class="text-muted small mb-3">Enter your credentials to access your account</p>

                    <form action="<?= url('index.php') ?>" method="POST" novalidate>
                        <input type="hidden" name="auth_action" value="login">
                        <?= csrf_input() ?>

                        <div class="mb-3">
                            <label for="loginEmail" class="form-label fw-semibold small text-dark">Email Address <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-envelope text-muted"></i></span>
                                <input type="email" class="form-control border-start-0 ps-0" id="loginEmail" name="email" placeholder="name@example.com" required autofocus autocomplete="username">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="loginPassword" class="form-label fw-semibold small text-dark mb-1">Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-lock text-muted"></i></span>
                                <input type="password" class="form-control border-start-0 border-end-0 px-0" id="loginPassword" name="password" placeholder="Enter your password" required autocomplete="current-password">
                                <button class="btn btn-outline-secondary border-start-0 bg-light text-muted toggle-pw" type="button" data-target="#loginPassword"><i class="bi bi-eye"></i></button>
                            </div>
                        </div>

                        <div class="mb-4 form-check">
                            <input type="checkbox" class="form-check-input" id="rememberMe" name="remember_me" value="1">
                            <label class="form-check-label small text-muted user-select-none" for="rememberMe">Keep me signed in</label>
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary py-2"><i class="bi bi-box-arrow-in-right me-2"></i>Sign In</button>
                        </div>

                        <div class="text-center mt-3">
                            <a href="<?= url('forgot-password.php') ?>" class="small fw-medium text-decoration-none" style="color:#0d9488;">Forgot password?</a>
                        </div>
                    </form>

                    <!-- Demo Credentials -->
                    <div class="auth-demo-box mt-3">
                        <div class="d-flex align-items-center mb-1 text-dark fw-semibold small">
                            <i class="bi bi-info-circle me-1" style="color:#0d9488;"></i> Demo Credentials
                        </div>
                        <div class="extra-small text-muted">
                            <div class="mb-1"><span class="badge bg-primary me-1" style="font-size:.6rem;">Admin</span><code>admin@lpg.com</code> / <code>password</code></div>
                            <div class="mb-1"><span class="badge bg-success me-1" style="font-size:.6rem;">Rider</span><code>rider@lpg.com</code> / <code>password</code></div>
                            <div><span class="badge bg-info text-dark me-1" style="font-size:.6rem;">Customer</span><code>customer@lpg.com</code> / <code>password</code></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════════════════════
                 REGISTER PANEL
                 ═══════════════════════════════════════════════════════════════ -->
            <div class="tab-pane fade <?= $activeTab === 'register' ? 'show active' : '' ?>" id="panel-register" role="tabpanel">
                <div class="auth-form-body reg-scroll">
                    <h2 class="h5 fw-bold text-dark mb-1">Create Account</h2>
                    <p class="text-muted small mb-3">Fill in the details to register as a customer</p>

                    <form action="<?= url('index.php') ?>" method="POST" enctype="multipart/form-data" novalidate id="registerForm">
                        <input type="hidden" name="auth_action" value="register">
                        <?= csrf_input() ?>

                        <div class="row g-3">
                            <!-- Full Name -->
                            <div class="col-12 col-md-6">
                                <label for="regName" class="form-label fw-semibold small text-dark">Full Name <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="bi bi-person text-muted"></i></span>
                                    <input type="text" class="form-control border-start-0 ps-0" id="regName" name="full_name" value="<?= e($regFullName) ?>" placeholder="e.g. Juan Dela Cruz" required autocomplete="name">
                                </div>
                            </div>

                            <!-- Email -->
                            <div class="col-12 col-md-6">
                                <label for="regEmail" class="form-label fw-semibold small text-dark">Email Address <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="bi bi-envelope text-muted"></i></span>
                                    <input type="email" class="form-control border-start-0 ps-0" id="regEmail" name="email" value="<?= e($regEmail) ?>" placeholder="name@example.com" required autocomplete="email">
                                </div>
                            </div>

                            <!-- Phone -->
                            <div class="col-12 col-md-6">
                                <label for="regPhone" class="form-label fw-semibold small text-dark">Mobile Phone <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="bi bi-telephone text-muted"></i></span>
                                    <input type="tel" class="form-control border-start-0 ps-0" id="regPhone" name="phone" value="<?= e($regPhone) ?>" placeholder="09171234567" pattern="09[0-9]{9}" maxlength="11" required autocomplete="tel">
                                </div>
                                <div class="form-text" style="font-size:.72rem;">11-digit PH mobile (09XXXXXXXXX)</div>
                            </div>

                            <!-- Address -->
                            <div class="col-12 col-md-6">
                                <label for="regAddress" class="form-label fw-semibold small text-dark">Delivery Address <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light align-items-start pt-2"><i class="bi bi-geo-alt text-muted"></i></span>
                                    <textarea class="form-control border-start-0 ps-0" id="regAddress" name="address" rows="2" placeholder="House No., Street, Barangay, City" required><?= e($regAddress) ?></textarea>
                                </div>
                            </div>

                            <!-- Government ID -->
                            <div class="col-12">
                                <label class="form-label fw-semibold small text-dark">Valid Government ID <span class="text-danger">*</span></label>
                                <ul class="nav nav-pills mb-2 gap-2" id="idTabs" role="tablist">
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link active fw-semibold small py-1 px-2" id="camera-tab" data-bs-toggle="pill" data-bs-target="#cameraPane" type="button"><i class="bi bi-camera-video me-1"></i>Photo</button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link fw-semibold small py-1 px-2" id="upload-tab" data-bs-toggle="pill" data-bs-target="#uploadPane" type="button"><i class="bi bi-upload me-1"></i>Upload</button>
                                    </li>
                                </ul>
                                <div class="tab-content">
                                    <div class="tab-pane fade show active" id="cameraPane">
                                        <div class="id-camera-container border rounded-3 p-3 bg-light" id="cameraContainer">
                                            <div id="cameraIdle" class="text-center py-2">
                                                <i class="bi bi-camera-video d-block mb-1" style="font-size:1.5rem;color:#0d9488;"></i>
                                                <p class="text-muted small mb-2">Take a photo of your government ID</p>
                                                <button type="button" class="btn btn-primary px-3 py-1 fw-semibold small" id="startCameraBtn"><i class="bi bi-camera-video-fill me-1"></i>Open Camera</button>
                                            </div>
                                            <div id="cameraActive" class="d-none">
                                                <div class="position-relative rounded-3 overflow-hidden bg-dark" style="max-height:250px;">
                                                    <video id="cameraVideo" class="w-100 d-block" autoplay playsinline style="max-height:250px;object-fit:contain;"></video>
                                                    <div class="position-absolute top-0 start-0 m-2"><span class="badge bg-danger"><i class="bi bi-record-circle me-1"></i>LIVE</span></div>
                                                </div>
                                                <canvas id="cameraCanvas" class="d-none"></canvas>
                                                <div class="d-flex justify-content-center gap-2 mt-2">
                                                    <button type="button" class="btn btn-success px-3 py-1 fw-semibold small" id="captureBtn"><i class="bi bi-camera-fill me-1"></i>Capture</button>
                                                    <button type="button" class="btn btn-outline-secondary px-2 py-1 small" id="cancelCameraBtn"><i class="bi bi-x-lg me-1"></i>Cancel</button>
                                                </div>
                                            </div>
                                            <div id="cameraPreview" class="d-none text-center">
                                                <div class="position-relative d-inline-block">
                                                    <img id="capturedImage" class="img-fluid rounded-3 border" style="max-height:200px;" alt="Captured ID">
                                                    <div class="position-absolute top-0 end-0 m-2"><span class="badge bg-success"><i class="bi bi-check-circle-fill me-1"></i>OK</span></div>
                                                </div>
                                                <div class="mt-2"><button type="button" class="btn btn-outline-warning px-2 py-1 fw-semibold small" id="retakeBtn"><i class="bi bi-arrow-counterclockwise me-1"></i>Retake</button></div>
                                            </div>
                                            <div id="cameraError" class="d-none">
                                                <div class="alert alert-warning d-flex align-items-center mb-0 small py-2" role="alert">
                                                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                                    <div>Camera unavailable. Use file upload instead.</div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="form-text" style="font-size:.72rem;"><i class="bi bi-info-circle me-1"></i>Passport, license, national ID, etc.</div>
                                    </div>
                                    <div class="tab-pane fade" id="uploadPane">
                                        <div class="border rounded-3 p-3 bg-light">
                                            <div class="input-group">
                                                <span class="input-group-text bg-white border-end-0"><i class="bi bi-card-heading text-muted"></i></span>
                                                <input type="file" class="form-control border-start-0 ps-0" id="validId" name="valid_id" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                                            </div>
                                            <div class="form-text" style="font-size:.72rem;">JPG, PNG or WebP (Max 5MB)</div>
                                        </div>
                                    </div>
                                </div>
                                <input type="file" id="cameraFileInput" name="valid_id" class="d-none" accept="image/jpeg,image/png,image/webp">
                            </div>

                            <!-- Password -->
                            <div class="col-12 col-md-6">
                                <label for="regPassword" class="form-label fw-semibold small text-dark">Password <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="bi bi-lock text-muted"></i></span>
                                    <input type="password" class="form-control border-start-0 border-end-0 px-0" id="regPassword" name="password" placeholder="Create password" required autocomplete="new-password">
                                    <button class="btn btn-outline-secondary border-start-0 bg-light text-muted toggle-pw" type="button" data-target="#regPassword"><i class="bi bi-eye"></i></button>
                                </div>
                            </div>

                            <!-- Confirm Password -->
                            <div class="col-12 col-md-6">
                                <label for="regConfirm" class="form-label fw-semibold small text-dark">Confirm Password <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="bi bi-lock-fill text-muted"></i></span>
                                    <input type="password" class="form-control border-start-0 border-end-0 px-0" id="regConfirm" name="confirm_password" placeholder="Confirm password" required autocomplete="new-password">
                                    <button class="btn btn-outline-secondary border-start-0 bg-light text-muted toggle-pw" type="button" data-target="#regConfirm"><i class="bi bi-eye"></i></button>
                                </div>
                            </div>

                            <!-- Password Rules -->
                            <div class="col-12">
                                <div class="p-2 rounded-3 border" style="background:rgba(13,148,136,0.03);">
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
                            </div>

                            <!-- Terms -->
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="termsCheck" required checked>
                                    <label class="form-check-label small text-muted user-select-none" for="termsCheck">I agree to the Terms of Service and Privacy Policy.</label>
                                </div>
                            </div>

                            <!-- Submit -->
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold"><i class="bi bi-person-plus-fill me-2"></i>Create Account</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
    </div><!-- /.auth-form-side -->
</div><!-- /.auth-split -->

<script src="https://code.jquery.com/jquery-3.7.1.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="https://unpkg.com/aos@2.3.4/dist/aos.js"></script>
<?php if (!empty($flash['message'])): ?>
<!-- Flash popup (e.g. after logout): transparent glass matching the login
     card, spring entrance, plain 2-second auto-dismiss (no loader bar) -->
<style>
    .flash-popup {
        position: fixed;
        top: 1rem;
        left: 50%;
        transform: translate(-50%, -150%);
        z-index: 1090;
        display: flex;
        align-items: center;
        gap: 0.65rem;
        max-width: min(92vw, 430px);
        padding: 0.8rem 1.1rem;
        background: rgba(255, 255, 255, 0.08);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        border: 1px solid rgba(255, 255, 255, 0.16);
        border-radius: 1rem;
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.18);
        color: #f0fdfa;
        font-size: 0.9rem;
        font-weight: 600;
        opacity: 0;
        transition: transform 0.45s cubic-bezier(0.34, 1.35, 0.64, 1), opacity 0.3s ease;
    }
    .flash-popup.show { transform: translate(-50%, 0); opacity: 1; }
    .flash-popup-icon {
        width: 34px;
        height: 34px;
        flex-shrink: 0;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.05rem;
        background: rgba(255, 255, 255, 0.12);
        border: 1px solid rgba(255, 255, 255, 0.18);
    }
    .flash-popup.flash-success .flash-popup-icon { color: #6ee7b7; }
    .flash-popup.flash-info .flash-popup-icon { color: #5eead4; }
    .flash-popup.flash-warning .flash-popup-icon { color: #fcd34d; }
    .flash-popup.flash-danger .flash-popup-icon { color: #fca5a5; }
</style>
<div id="flashPopup" class="flash-popup flash-<?= e($flashType) ?>" role="alert" aria-live="assertive" aria-atomic="true">
    <span class="flash-popup-icon"><i class="bi <?= e($flashIcon) ?>"></i></span>
    <span><?= e($flash['message']) ?></span>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('flashPopup');
    if (!el) return;
    requestAnimationFrame(function () { requestAnimationFrame(function () { el.classList.add('show'); }); });
    setTimeout(function () { el.classList.remove('show'); }, 2000);
    setTimeout(function () { el.remove(); }, 2500);
});
</script>
<?php endif; ?>
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
        var p = document.getElementById('regPassword') ? document.getElementById('regPassword').value : '';
        var c = document.getElementById('regConfirm') ? document.getElementById('regConfirm').value : '';
        updateRule('#rule-length',  p.length >= 8);
        updateRule('#rule-upper',   /[A-Z]/.test(p));
        updateRule('#rule-lower',   /[a-z]/.test(p));
        updateRule('#rule-number',  /[0-9]/.test(p));
        updateRule('#rule-special', /[\W_]/.test(p));
        updateRule('#rule-match',   p.length > 0 && p === c);
    }
    ['regPassword','regConfirm'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('input', checkPw);
    });
    checkPw();

    // Camera capture
    (function () {
        var startBtn = document.getElementById('startCameraBtn');
        var captureBtn = document.getElementById('captureBtn');
        var cancelBtn = document.getElementById('cancelCameraBtn');
        var retakeBtn = document.getElementById('retakeBtn');
        var idle = document.getElementById('cameraIdle');
        var active = document.getElementById('cameraActive');
        var preview = document.getElementById('cameraPreview');
        var error = document.getElementById('cameraError');
        var video = document.getElementById('cameraVideo');
        var canvas = document.getElementById('cameraCanvas');
        var capturedImg = document.getElementById('capturedImage');
        var cameraFileInput = document.getElementById('cameraFileInput');
        var fileInput = document.getElementById('validId');
        var stream = null;

        function show(s) { [idle,active,preview,error].forEach(function(e){e.classList.add('d-none');}); s.classList.remove('d-none'); }
        function stopCam() { if (stream) { stream.getTracks().forEach(function(t){t.stop();}); stream = null; } video.srcObject = null; }
        function makeFile(blob) {
            var f = new File([blob],'id_'+Date.now()+'.jpg',{type:'image/jpeg',lastModified:Date.now()});
            var dt = new DataTransfer(); dt.items.add(f);
            cameraFileInput.files = dt.files;
            if (fileInput) { fileInput.disabled = true; fileInput.value = ''; }
        }
        function clearFile() { cameraFileInput.value = ''; if (fileInput) fileInput.disabled = false; }

        if (startBtn) startBtn.addEventListener('click', function () {
            if (!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia) { show(error); return; }
            navigator.mediaDevices.getUserMedia({video:{facingMode:'environment',width:{ideal:1280},height:{ideal:720}}})
            .then(function(s){stream=s;video.srcObject=s;show(active);})
            .catch(function(){show(error);});
        });
        if (captureBtn) captureBtn.addEventListener('click', function () {
            if (!stream) return;
            canvas.width=video.videoWidth; canvas.height=video.videoHeight;
            canvas.getContext('2d').drawImage(video,0,0);
            canvas.toBlob(function(b){if(!b)return;capturedImg.src=URL.createObjectURL(b);makeFile(b);stopCam();show(preview);},'image/jpeg',0.92);
        });
        if (cancelBtn) cancelBtn.addEventListener('click', function () { stopCam(); clearFile(); show(idle); });
        if (retakeBtn) retakeBtn.addEventListener('click', function () {
            clearFile(); if(capturedImg.src){URL.revokeObjectURL(capturedImg.src);capturedImg.src='';}
            if(!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia){show(error);return;}
            navigator.mediaDevices.getUserMedia({video:{facingMode:'environment',width:{ideal:1280},height:{ideal:720}}})
            .then(function(s){stream=s;video.srcObject=s;show(active);})
            .catch(function(){show(error);});
        });
        var uploadTab = document.getElementById('upload-tab');
        if (uploadTab) uploadTab.addEventListener('shown.bs.tab', function () { stopCam(); show(idle); });
    })();
});
</script>
</body>
</html>
