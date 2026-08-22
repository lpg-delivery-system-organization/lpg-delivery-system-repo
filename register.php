<?php
/**
 * Customer Registration Page
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

$page_title = 'Register Customer Account';
$errors = [];
$fullName = '';
$email = '';
$phone = '';
$address = '';
$userModel = new User();

// Handle Registration POST Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = sanitize_input($_POST['full_name'] ?? '');
    $email = sanitize_input($_POST['email'] ?? '');
    $phone = sanitize_input($_POST['phone'] ?? '');
    $address = sanitize_input($_POST['address'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $submittedCsrf = $_POST['csrf_token'] ?? null;

    // 1. Verify CSRF Token
    if (!verify_csrf($submittedCsrf)) {
        $errors[] = 'Your security token is invalid or expired. Please refresh and try again.';
    }

    // 2. Full Name Validation
    if ($fullName === '') {
        $errors[] = 'Full name is required.';
    } elseif (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 100) {
        $errors[] = 'Full name must be between 2 and 100 characters.';
    }

    // 3. Email Validation
    if ($email === '') {
        $errors[] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please provide a valid email address.';
    } elseif ($userModel->emailExists($email)) {
        $errors[] = 'An account with this email address already exists. Please sign in or use a different email.';
    }

    // 4. Contact Phone Number Validation (Philippine mobile: 09XXXXXXXXX)
    if ($phone === '') {
        $errors[] = 'Contact phone number is required.';
    } elseif (!preg_match('/^09\d{9}$/', $phone)) {
        $errors[] = 'Phone number must be an 11-digit Philippine mobile number starting with 09 (e.g. 09171234567).';
    }

    // 5. Delivery Address Validation
    if ($address === '') {
        $errors[] = 'Delivery address is required.';
    } elseif (mb_strlen($address) < 5) {
        $errors[] = 'Please provide a complete delivery address (at least 5 characters).';
    }

    // 6. Password Complexity & Confirmation Validation
    if ($password === '') {
        $errors[] = 'Password is required.';
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

    // 7. Required Valid ID Upload Validation (camera capture or file upload)
    $validIdPath = null;
    if (isset($_FILES['valid_id']) && $_FILES['valid_id']['error'] !== UPLOAD_ERR_NO_FILE) {
        $uploadResult = validate_id_upload($_FILES['valid_id']);
        if (!$uploadResult['success']) {
            $errors[] = 'Valid ID upload failed: ' . $uploadResult['error'];
        } else {
            $validIdPath = $uploadResult['relative_path'];
        }
    } else {
        $errors[] = 'A valid government ID photo is required. Please upload a file or take a photo using your camera.';
    }

    // 8. Create Customer Record
    if (empty($errors)) {
        try {
            $userModel->create([
                'full_name'     => $fullName,
                'email'         => $email,
                'password'      => $password,
                'role'          => 'customer', // strictly enforced customer role
                'phone'         => $phone,
                'address'       => $address,
                'valid_id_path' => $validIdPath,
                'status'        => 'active'
            ]);

            set_flash('success', 'Registration successful! You may now sign in with your email and password.');
            redirect('/index.php');
        } catch (Throwable $e) {
            $errors[] = 'Registration failed: ' . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/templates/header.php';
?>

<div class="container py-4 py-md-5">
    <div class="row justify-content-center">
        <div class="col-12 col-md-10 col-lg-8 col-xl-7">
            
            <!-- Header -->
            <div class="text-center mb-4">
                <div class="d-inline-flex align-items-center justify-content-center bg-primary text-white rounded-circle mb-3 shadow-sm" style="width: 56px; height: 56px;">
                    <i class="bi bi-person-plus-fill text-warning fs-3"></i>
                </div>
                <h1 class="h3 fw-bold text-dark mb-1">Create Customer Account</h1>
                <p class="text-muted small">Register to order LPG cylinders and track deliveries in real-time</p>
            </div>

            <!-- Registration Card -->
            <div class="card shadow-sm border-0 rounded-3">
                <div class="card-body p-4 p-md-5">
                    
                    <div class="d-flex align-items-center justify-content-between mb-4 border-bottom pb-2">
                        <h2 class="h5 fw-bold mb-0 text-dark">Customer Registration</h2>
                        <span class="badge bg-primary text-white px-2 py-1 small">Free Account</span>
                    </div>

                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger alert-dismissible fade show d-flex align-items-start shadow-sm mb-4" role="alert">
                            <i class="bi bi-exclamation-triangle-fill fs-5 me-2 flex-shrink-0 mt-1"></i>
                            <div class="flex-grow-1 small">
                                <?php if (count($errors) === 1): ?>
                                    <span><?= e($errors[0]) ?></span>
                                <?php else: ?>
                                    <div class="fw-semibold mb-1">Please fix the following issues:</div>
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

                    <form action="<?= url('register.php') ?>" method="POST" enctype="multipart/form-data" novalidate id="registerForm">
                        <?= csrf_input() ?>

                        <div class="row g-3">
                            
                            <!-- Full Name -->
                            <div class="col-12 col-md-6">
                                <label for="fullName" class="form-label fw-semibold small text-dark">
                                    Full Name <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0 text-muted">
                                        <i class="bi bi-person"></i>
                                    </span>
                                    <input
                                        type="text"
                                        class="form-control border-start-0 ps-0"
                                        id="fullName"
                                        name="full_name"
                                        value="<?= e($fullName) ?>"
                                        placeholder="e.g. Juan Dela Cruz"
                                        required
                                        autocomplete="name"
                                    >
                                </div>
                            </div>

                            <!-- Email Address -->
                            <div class="col-12 col-md-6">
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
                                        autocomplete="email"
                                    >
                                </div>
                            </div>

                            <!-- Phone Number -->
                            <div class="col-12 col-md-6">
                                <label for="phone" class="form-label fw-semibold small text-dark">
                                    Mobile Phone Number <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0 text-muted">
                                        <i class="bi bi-telephone"></i>
                                    </span>
                                    <input
                                        type="tel"
                                        class="form-control border-start-0 ps-0"
                                        id="phone"
                                        name="phone"
                                        value="<?= e($phone) ?>"
                                        placeholder="09171234567"
                                        pattern="09[0-9]{9}"
                                        maxlength="11"
                                        required
                                        autocomplete="tel"
                                    >
                                </div>
                                <div class="form-text extra-small">11-digit Philippine mobile format (09XXXXXXXXX)</div>
                            </div>

                            <!-- Government/Valid ID Verification (Required) -->
                            <div class="col-12">
                                <label class="form-label fw-semibold small text-dark">
                                    Valid Government ID <span class="text-danger">*</span>
                                </label>

                                <!-- Tab Switcher -->
                                <ul class="nav nav-pills mb-3 gap-2" id="idVerificationTabs" role="tablist">
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link active fw-semibold small" id="camera-tab" data-bs-toggle="pill" data-bs-target="#cameraPane" type="button" role="tab" aria-controls="cameraPane" aria-selected="true">
                                            <i class="bi bi-camera-video me-1"></i>Take Photo
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link fw-semibold small" id="upload-tab" data-bs-toggle="pill" data-bs-target="#uploadPane" type="button" role="tab" aria-controls="uploadPane" aria-selected="false">
                                            <i class="bi bi-upload me-1"></i>Upload File
                                        </button>
                                    </li>
                                </ul>

                                <div class="tab-content">
                                    <!-- Camera Capture Tab -->
                                    <div class="tab-pane fade show active" id="cameraPane" role="tabpanel" aria-labelledby="camera-tab">
                                        <div class="id-camera-container border rounded-3 p-3 bg-light" id="cameraContainer">
                                            <!-- Idle State: Start Camera -->
                                            <div id="cameraIdle" class="text-center py-4">
                                                <div class="mb-3">
                                                    <i class="bi bi-camera-video text-primary" style="font-size: 2.5rem;"></i>
                                                </div>
                                                <p class="text-muted small mb-3">Position your valid government ID in front of your camera and take a clear photo.</p>
                                                <button type="button" class="btn btn-primary px-4 py-2 fw-semibold" id="startCameraBtn">
                                                    <i class="bi bi-camera-video-fill me-2"></i>Open Camera
                                                </button>
                                            </div>

                                            <!-- Live Camera View -->
                                            <div id="cameraActive" class="d-none">
                                                <div class="position-relative rounded-3 overflow-hidden bg-dark" style="max-height: 360px;">
                                                    <video id="cameraVideo" class="w-100 d-block" autoplay playsinline style="max-height: 360px; object-fit: contain;"></video>
                                                    <div class="position-absolute top-0 start-0 m-2">
                                                        <span class="badge bg-danger"><i class="bi bi-record-circle me-1"></i>LIVE</span>
                                                    </div>
                                                </div>
                                                <canvas id="cameraCanvas" class="d-none"></canvas>
                                                <div class="d-flex justify-content-center gap-2 mt-3">
                                                    <button type="button" class="btn btn-success px-4 py-2 fw-semibold" id="captureBtn">
                                                        <i class="bi bi-camera-fill me-1"></i>Capture
                                                    </button>
                                                    <button type="button" class="btn btn-outline-secondary px-3 py-2" id="cancelCameraBtn">
                                                        <i class="bi bi-x-lg me-1"></i>Cancel
                                                    </button>
                                                </div>
                                            </div>

                                            <!-- Captured Preview -->
                                            <div id="cameraPreview" class="d-none text-center">
                                                <div class="position-relative d-inline-block">
                                                    <img id="capturedImage" class="img-fluid rounded-3 border" style="max-height: 300px;" alt="Captured ID">
                                                    <div class="position-absolute top-0 end-0 m-2">
                                                        <span class="badge bg-success"><i class="bi bi-check-circle-fill me-1"></i>Captured</span>
                                                    </div>
                                                </div>
                                                <div class="mt-3">
                                                    <button type="button" class="btn btn-outline-warning px-3 py-2 fw-semibold small" id="retakeBtn">
                                                        <i class="bi bi-arrow-counterclockwise me-1"></i>Retake Photo
                                                    </button>
                                                </div>
                                            </div>

                                            <!-- Camera Error -->
                                            <div id="cameraError" class="d-none">
                                                <div class="alert alert-warning d-flex align-items-center mb-0" role="alert">
                                                    <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
                                                    <div>
                                                        <div class="fw-semibold small">Camera access denied or unavailable.</div>
                                                        <div class="extra-small text-muted">Please allow camera access in your browser settings, or use the file upload option instead.</div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="form-text extra-small mt-2">
                                            <i class="bi bi-info-circle me-1"></i>Take a clear photo of your valid government-issued ID (passport, driver's license, national ID, etc.)
                                        </div>
                                    </div>

                                    <!-- File Upload Tab -->
                                    <div class="tab-pane fade" id="uploadPane" role="tabpanel" aria-labelledby="upload-tab">
                                        <div class="border rounded-3 p-3 bg-light">
                                            <div class="input-group">
                                                <span class="input-group-text bg-white border-end-0 text-muted">
                                                    <i class="bi bi-card-heading"></i>
                                                </span>
                                                <input
                                                    type="file"
                                                    class="form-control border-start-0 ps-0"
                                                    id="validId"
                                                    name="valid_id"
                                                    accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                                >
                                            </div>
                                            <div class="form-text extra-small">JPG, PNG or WebP (Max 5MB)</div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Hidden input that carries camera-captured blob as a file -->
                                <input type="file" id="cameraFileInput" name="valid_id" class="d-none" accept="image/jpeg,image/png,image/webp">
                            </div>

                            <!-- Delivery Address -->
                            <div class="col-12">
                                <label for="address" class="form-label fw-semibold small text-dark">
                                    Complete Delivery Address <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0 text-muted align-items-start pt-2">
                                        <i class="bi bi-geo-alt"></i>
                                    </span>
                                    <textarea
                                        class="form-control border-start-0 ps-0"
                                        id="address"
                                        name="address"
                                        rows="2"
                                        placeholder="House/Unit No., Street Name, Barangay, City/Municipality"
                                        required
                                    ><?= e($address) ?></textarea>
                                </div>
                            </div>

                            <!-- Password -->
                            <div class="col-12 col-md-6">
                                <label for="password" class="form-label fw-semibold small text-dark">
                                    Password <span class="text-danger">*</span>
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
                                        placeholder="Create password"
                                        required
                                        autocomplete="new-password"
                                    >
                                    <button class="btn btn-outline-secondary border-start-0 bg-light text-muted toggle-password-btn" type="button" data-target="#password" aria-label="Toggle password visibility">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Confirm Password -->
                            <div class="col-12 col-md-6">
                                <label for="confirmPassword" class="form-label fw-semibold small text-dark">
                                    Confirm Password <span class="text-danger">*</span>
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
                                        placeholder="Confirm password"
                                        required
                                        autocomplete="new-password"
                                    >
                                    <button class="btn btn-outline-secondary border-start-0 bg-light text-muted toggle-password-btn" type="button" data-target="#confirmPassword" aria-label="Toggle password confirmation visibility">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Password Complexity Checklist (Live jQuery validation) -->
                            <div class="col-12">
                                <div class="p-3 bg-light rounded-3 border small">
                                    <div class="fw-semibold text-dark mb-2">Password Requirements:</div>
                                    <div class="row g-2">
                                        <div class="col-12 col-sm-6">
                                            <div id="rule-length" class="d-flex align-items-center text-muted">
                                                <i class="bi bi-circle me-2 text-muted rule-icon"></i>
                                                <span>At least 8 characters</span>
                                            </div>
                                        </div>
                                        <div class="col-12 col-sm-6">
                                            <div id="rule-upper" class="d-flex align-items-center text-muted">
                                                <i class="bi bi-circle me-2 text-muted rule-icon"></i>
                                                <span>At least 1 uppercase letter (A-Z)</span>
                                            </div>
                                        </div>
                                        <div class="col-12 col-sm-6">
                                            <div id="rule-lower" class="d-flex align-items-center text-muted">
                                                <i class="bi bi-circle me-2 text-muted rule-icon"></i>
                                                <span>At least 1 lowercase letter (a-z)</span>
                                            </div>
                                        </div>
                                        <div class="col-12 col-sm-6">
                                            <div id="rule-number" class="d-flex align-items-center text-muted">
                                                <i class="bi bi-circle me-2 text-muted rule-icon"></i>
                                                <span>At least 1 number (0-9)</span>
                                            </div>
                                        </div>
                                        <div class="col-12 col-sm-6">
                                            <div id="rule-special" class="d-flex align-items-center text-muted">
                                                <i class="bi bi-circle me-2 text-muted rule-icon"></i>
                                                <span>At least 1 special character (!@#$%^&*)</span>
                                            </div>
                                        </div>
                                        <div class="col-12 col-sm-6">
                                            <div id="rule-match" class="d-flex align-items-center text-muted">
                                                <i class="bi bi-circle me-2 text-muted rule-icon"></i>
                                                <span>Passwords match</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Terms & Privacy Agreement -->
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="termsCheck" required checked>
                                    <label class="form-check-label small text-muted user-select-none" for="termsCheck">
                                        I agree to the Terms of Service and Privacy Policy.
                                    </label>
                                </div>
                            </div>

                            <!-- Submit Button -->
                            <div class="col-12 mt-4">
                                <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold shadow-sm" id="registerSubmitBtn">
                                    <i class="bi bi-person-plus-fill me-2"></i>Create Customer Account
                                </button>
                            </div>

                        </div>
                    </form>

                    <!-- Login Link -->
                    <div class="text-center pt-3 border-top mt-4">
                        <p class="text-muted small mb-0">
                            Already have an account?
                            <a href="<?= url('index.php') ?>" class="fw-semibold text-primary text-decoration-none ms-1">
                                Sign in here
                            </a>
                        </p>
                    </div>

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

    // Live jQuery Password Checklist
    if (window.jQuery) {
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

    // =====================================================================
    // Live Camera Capture for ID Verification
    // =====================================================================
    (function () {
        var startBtn = document.getElementById('startCameraBtn');
        var captureBtn = document.getElementById('captureBtn');
        var cancelBtn = document.getElementById('cancelCameraBtn');
        var retakeBtn = document.getElementById('retakeBtn');

        var idleState = document.getElementById('cameraIdle');
        var activeState = document.getElementById('cameraActive');
        var previewState = document.getElementById('cameraPreview');
        var errorState = document.getElementById('cameraError');

        var video = document.getElementById('cameraVideo');
        var canvas = document.getElementById('cameraCanvas');
        var capturedImg = document.getElementById('capturedImage');
        var cameraFileInput = document.getElementById('cameraFileInput');
        var fileInput = document.getElementById('validId');

        var currentStream = null;

        function showState(state) {
            [idleState, activeState, previewState, errorState].forEach(function (el) {
                el.classList.add('d-none');
            });
            state.classList.remove('d-none');
        }

        function stopCamera() {
            if (currentStream) {
                currentStream.getTracks().forEach(function (track) {
                    track.stop();
                });
                currentStream = null;
            }
            video.srcObject = null;
        }

        function createFileFromBlob(blob) {
            var file = new File([blob], 'camera_capture_' + Date.now() + '.jpg', {
                type: 'image/jpeg',
                lastModified: Date.now()
            });
            var dataTransfer = new DataTransfer();
            dataTransfer.items.add(file);

            cameraFileInput.files = dataTransfer.files;

            if (fileInput) {
                fileInput.disabled = true;
                fileInput.value = '';
            }
        }

        function clearCapturedFile() {
            cameraFileInput.value = '';
            if (fileInput) {
                fileInput.disabled = false;
            }
        }

        if (startBtn) {
            startBtn.addEventListener('click', function () {
                if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                    showState(errorState);
                    return;
                }

                navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: 'environment',
                        width: { ideal: 1280 },
                        height: { ideal: 720 }
                    }
                })
                .then(function (stream) {
                    currentStream = stream;
                    video.srcObject = stream;
                    showState(activeState);
                })
                .catch(function (err) {
                    console.error('Camera error:', err);
                    showState(errorState);
                });
            });
        }

        if (captureBtn) {
            captureBtn.addEventListener('click', function () {
                if (!currentStream) return;

                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;

                var ctx = canvas.getContext('2d');
                ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

                canvas.toBlob(function (blob) {
                    if (!blob) return;

                    var url = URL.createObjectURL(blob);
                    capturedImg.src = url;
                    createFileFromBlob(blob);
                    stopCamera();
                    showState(previewState);
                }, 'image/jpeg', 0.92);
            });
        }

        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                stopCamera();
                clearCapturedFile();
                showState(idleState);
            });
        }

        if (retakeBtn) {
            retakeBtn.addEventListener('click', function () {
                clearCapturedFile();
                if (capturedImg.src) {
                    URL.revokeObjectURL(capturedImg.src);
                    capturedImg.src = '';
                }

                if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                    showState(errorState);
                    return;
                }

                navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: 'environment',
                        width: { ideal: 1280 },
                        height: { ideal: 720 }
                    }
                })
                .then(function (stream) {
                    currentStream = stream;
                    video.srcObject = stream;
                    showState(activeState);
                })
                .catch(function (err) {
                    console.error('Camera error on retake:', err);
                    showState(errorState);
                });
            });
        }

        var uploadTab = document.getElementById('upload-tab');
        if (uploadTab) {
            uploadTab.addEventListener('shown.bs.tab', function () {
                stopCamera();
                showState(idleState);
            });
        }
    })();
});
</script>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
