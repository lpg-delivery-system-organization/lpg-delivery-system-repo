<?php
/**
 * Rider Profile Management Page
 * LPG Delivery System v2
 *
 * View and update personal information, contact phone, residential address,
 * delivery performance statistics, optional valid ID verification document,
 * and account password security.
 */

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/middleware.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../classes/Order.php';

// Enforce rider access
require_role('rider');

$db = Database::connect();
$userModel = new User($db);
$orderModel = new Order($db);

$riderId = (int)current_user_id();
$rider = $userModel->findById($riderId);

$profileErrors = [];
$passwordErrors = [];

// Handle POST submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize_input($_POST['action'] ?? '');

    // Verify CSRF token
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Your security session has expired. Please refresh and try again.');
        redirect('/pages/rider/profile.php');
        return;
    }

    // 1. Profile Details Update (full name, phone, address, optional valid_id)
    if ($action === 'update_profile') {
        $fullName = sanitize_input($_POST['full_name'] ?? '');
        $phone = sanitize_input($_POST['phone'] ?? '');
        $address = sanitize_input($_POST['address'] ?? '');

        if (empty($fullName)) {
            $profileErrors[] = 'Full name is required.';
        }

        if (empty($phone)) {
            $profileErrors[] = 'Contact phone number is required.';
        } elseif (!preg_match('/^09\d{9}$/', $phone)) {
            $profileErrors[] = 'Phone number must be an 11-digit Philippine mobile number starting with 09 (e.g. 09171234567).';
        }

        if (empty($address)) {
            $profileErrors[] = 'Home address cannot be empty.';
        }

        $validIdRelativePath = null;
        if (!empty($_FILES['valid_id']['name']) && $_FILES['valid_id']['error'] !== UPLOAD_ERR_NO_FILE) {
            $uploadResult = validate_id_upload($_FILES['valid_id']);
            if (!$uploadResult['success']) {
                $profileErrors[] = $uploadResult['error'];
            } else {
                $validIdRelativePath = $uploadResult['relative_path'];
            }
        }

        if (empty($profileErrors)) {
            $updatePayload = [
                'full_name' => $fullName,
                'phone'     => $phone,
                'address'   => $address
            ];

            if ($validIdRelativePath !== null) {
                $updatePayload['valid_id_path'] = $validIdRelativePath;
            }

            try {
                $updated = $userModel->updateProfile($riderId, $updatePayload);
                if ($updated) {
                    $_SESSION['user_name'] = $fullName;
                    if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
                        $_SESSION['user']['full_name'] = $fullName;
                        $_SESSION['user']['phone'] = $phone;
                        $_SESSION['user']['address'] = $address;
                        if ($validIdRelativePath !== null) {
                            $_SESSION['user']['valid_id_path'] = $validIdRelativePath;
                        }
                    }
                    set_flash('success', 'Your profile details have been successfully updated.');
                    redirect('/pages/rider/profile.php');
                    return;
                } else {
                    $profileErrors[] = 'No changes were detected or failed to update profile.';
                }
            } catch (Throwable $e) {
                $profileErrors[] = 'Error updating profile: ' . $e->getMessage();
            }
        }
    }

    // 2. Password Change
    if ($action === 'change_password') {
        $currentPass = $_POST['current_password'] ?? '';
        $newPass = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_new_password'] ?? '';

        if (empty($currentPass)) {
            $passwordErrors[] = 'Please enter your current password.';
        } elseif (!password_verify($currentPass, $rider['password'])) {
            $passwordErrors[] = 'The current password you entered is incorrect.';
        }

        if (empty($newPass)) {
            $passwordErrors[] = 'Please enter a new password.';
        } else {
            if (strlen($newPass) < 8) {
                $passwordErrors[] = 'New password must be at least 8 characters long.';
            }
            if (!preg_match('/[A-Z]/', $newPass)) {
                $passwordErrors[] = 'New password must contain at least one uppercase letter.';
            }
            if (!preg_match('/[a-z]/', $newPass)) {
                $passwordErrors[] = 'New password must contain at least one lowercase letter.';
            }
            if (!preg_match('/[0-9]/', $newPass)) {
                $passwordErrors[] = 'New password must contain at least one number.';
            }
            if (!preg_match('/[\W_]/', $newPass)) {
                $passwordErrors[] = 'New password must contain at least one special character.';
            }
        }

        if ($newPass !== $confirmPass) {
            $passwordErrors[] = 'New password and confirmation do not match.';
        }

        if (empty($passwordErrors)) {
            try {
                $updated = $userModel->updatePassword($riderId, $newPass);
                if ($updated) {
                    set_flash('success', 'Your password has been changed successfully.');
                    redirect('/pages/rider/profile.php');
                    return;
                } else {
                    $passwordErrors[] = 'Failed to update password. Please try again.';
                }
            } catch (Throwable $e) {
                $passwordErrors[] = 'Error updating password: ' . $e->getMessage();
            }
        }
    }
}

// Reload fresh rider data
$rider = $userModel->findById($riderId);

// Calculate rider delivery stats
$riderOrders = $orderModel->getByRider($riderId);
$totalAssigned = count($riderOrders);
$totalCompleted = 0;
$totalActive = 0;
$totalCodCollected = 0.0;

foreach ($riderOrders as $ro) {
    $st = $ro['status'] ?? '';
    if ($st === 'delivered') {
        $totalCompleted++;
        if (($ro['payment_method'] ?? '') === 'cod') {
            $totalCodCollected += (float)($ro['total_amount'] ?? 0);
        }
    } elseif (in_array($st, ['ready_for_delivery', 'picked_up', 'out_for_delivery'], true)) {
        $totalActive++;
    }
}

$page_title = 'My Profile';
$current_page = 'profile';
$page_js = 'rider.js';

require_once __DIR__ . '/../../templates/header.php';
?>

<div class="container-fluid px-0" id="riderProfileContainer">
    <!-- Header Section -->
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <h3 class="fw-bold mb-1 d-flex align-items-center gap-2">
                <i class="bi bi-person-circle text-primary"></i>Rider Profile & Performance
            </h3>
            <p class="text-muted small mb-0">View delivery performance stats, update contact details, and manage password security.</p>
        </div>
        <div>
            <span class="badge bg-primary text-uppercase px-3 py-2">
                <i class="bi bi-shield-check me-1"></i>Official Delivery Rider
            </span>
        </div>
    </div>

    <div class="row g-4">
        <!-- Left Column: Rider Overview Card & Performance Stats -->
        <div class="col-lg-4">
            <!-- User Summary Card -->
            <div class="card border-0 shadow-sm text-center p-4 rounded-3 mb-4">
                <div class="mx-auto mb-3">
                    <div class="user-avatar-circle" style="width: 80px; height: 80px; font-size: 2rem;">
                        <?= e(strtoupper(substr($rider['full_name'] ?? 'R', 0, 1))) ?>
                    </div>
                </div>
                <h5 class="fw-bold mb-1 text-dark"><?= e($rider['full_name'] ?? 'Rider') ?></h5>
                <p class="text-muted small mb-2"><?= e($rider['email'] ?? '') ?></p>
                <div class="mb-3">
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-1">
                        <i class="bi bi-circle-fill me-1" style="font-size: 0.5rem;"></i>Active Rider
                    </span>
                </div>
                <div class="border-top pt-3 text-start small">
                    <div class="mb-2">
                        <span class="text-muted d-block extra-small text-uppercase fw-semibold">Rider Since</span>
                        <span class="fw-medium text-dark"><i class="bi bi-calendar3 me-2 text-primary"></i><?= e(format_date($rider['created_at'], 'F d, Y')) ?></span>
                    </div>
                    <div class="mb-2">
                        <span class="text-muted d-block extra-small text-uppercase fw-semibold">Contact Phone</span>
                        <span class="fw-medium text-dark"><i class="bi bi-telephone me-2 text-primary"></i><?= e($rider['phone'] ?? 'Not provided') ?></span>
                    </div>
                    <div>
                        <span class="text-muted d-block extra-small text-uppercase fw-semibold">Home Address</span>
                        <span class="fw-medium text-dark"><i class="bi bi-geo-alt me-2 text-danger"></i><?= e($rider['address'] ?? 'Not provided') ?></span>
                    </div>
                </div>
            </div>

            <!-- Delivery Performance Stats Card -->
            <div class="card border-0 shadow-sm rounded-3 mb-4">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="card-title mb-0 fw-bold d-flex align-items-center gap-2">
                        <i class="bi bi-speedometer2 text-primary"></i>Delivery Performance
                    </h6>
                </div>
                <div class="card-body p-3">
                    <div class="row g-2 text-center">
                        <div class="col-6">
                            <div class="p-3 bg-light rounded-3 border">
                                <span class="text-muted extra-small text-uppercase fw-semibold d-block">Completed</span>
                                <h4 class="fw-bold my-1 text-success"><?= $totalCompleted ?></h4>
                                <span class="extra-small text-muted">Successful drops</span>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-3 bg-light rounded-3 border">
                                <span class="text-muted extra-small text-uppercase fw-semibold d-block">In Queue</span>
                                <h4 class="fw-bold my-1 text-primary"><?= $totalActive ?></h4>
                                <span class="extra-small text-muted">Active deliveries</span>
                            </div>
                        </div>
                        <div class="col-12 mt-2">
                            <div class="p-3 bg-light rounded-3 border text-start">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="text-muted extra-small text-uppercase fw-semibold">Total Delivered COD</span>
                                    <span class="fw-bold text-dark fs-6"><?= e(format_currency($totalCodCollected)) ?></span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="text-muted extra-small text-uppercase fw-semibold">Total Orders Handled</span>
                                    <span class="fw-bold text-dark"><?= $totalAssigned ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Valid ID Verification Document Card -->
            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="card-title mb-0 fw-bold d-flex align-items-center gap-2">
                        <i class="bi bi-card-heading text-primary"></i>Driver's License / Valid ID
                    </h6>
                </div>
                <div class="card-body p-3">
                    <?php if (!empty($rider['valid_id_path'])): ?>
                        <div class="alert alert-success d-flex align-items-center p-3 mb-0 rounded-3" role="alert">
                            <i class="bi bi-shield-check fs-4 me-2 text-success flex-shrink-0"></i>
                            <div>
                                <strong class="d-block">ID Document On File</strong>
                                <small class="text-muted">Government ID is verified for rider authentication.</small>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning d-flex align-items-center p-3 mb-0 rounded-3" role="alert">
                            <i class="bi bi-exclamation-circle fs-4 me-2 text-warning flex-shrink-0"></i>
                            <div>
                                <strong class="d-block">No Valid ID Uploaded</strong>
                                <small class="text-muted">Upload your Driver's License or Government ID below.</small>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right Column: Edit Profile & Password Update Forms -->
        <div class="col-lg-8">
            <!-- Profile Details Form -->
            <div class="card border-0 shadow-sm rounded-3 mb-4">
                <div class="card-header bg-white py-3 border-bottom">
                    <h5 class="card-title mb-0 fw-bold d-flex align-items-center gap-2">
                        <i class="bi bi-person-gear text-primary"></i>Edit Rider Contact Details
                    </h5>
                </div>
                <div class="card-body p-3 p-md-4">
                    <?php if (!empty($profileErrors)): ?>
                        <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <ul class="mb-0 ps-3">
                                <?php foreach ($profileErrors as $err): ?>
                                    <li><?= e($err) ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <form action="<?= url('pages/rider/profile.php') ?>" method="POST" enctype="multipart/form-data" id="riderProfileForm">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="update_profile">

                        <div class="row g-3">
                            <!-- Full Name -->
                            <div class="col-md-6">
                                <label for="full_name" class="form-label fw-semibold small text-dark">
                                    Full Name <span class="text-danger">*</span>
                                </label>
                                <input type="text" 
                                       name="full_name" 
                                       id="full_name" 
                                       class="form-control" 
                                       value="<?= e($rider['full_name'] ?? '') ?>" 
                                       required>
                            </div>

                            <!-- Email (Read-Only) -->
                            <div class="col-md-6">
                                <label for="email" class="form-label fw-semibold small text-dark">
                                    Email Address <span class="badge bg-secondary-subtle text-secondary ms-1">Read-Only</span>
                                </label>
                                <input type="email" 
                                       id="email" 
                                       class="form-control bg-light" 
                                       value="<?= e($rider['email'] ?? '') ?>" 
                                       disabled>
                                <div class="form-text extra-small">Login email cannot be changed directly. Contact admin for email updates.</div>
                            </div>

                            <!-- Contact Phone -->
                            <div class="col-md-6">
                                <label for="phone" class="form-label fw-semibold small text-dark">
                                    Contact Mobile Phone <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white"><i class="bi bi-telephone text-muted"></i></span>
                                    <input type="text" 
                                           name="phone" 
                                           id="phone" 
                                           class="form-control" 
                                           placeholder="09171234567" 
                                           maxlength="11" 
                                           value="<?= e($rider['phone'] ?? '') ?>" 
                                           required>
                                </div>
                                <div class="form-text extra-small">11-digit Philippine mobile format starting with 09.</div>
                            </div>

                            <!-- Valid ID Upload -->
                            <div class="col-md-6">
                                <label for="valid_id" class="form-label fw-semibold small text-dark">
                                    Driver's License / Valid ID <span class="text-muted extra-small fw-normal">(JPG/PNG/PDF max 5MB)</span>
                                </label>
                                <input type="file" 
                                       name="valid_id" 
                                       id="valid_id" 
                                       class="form-control" 
                                       accept="image/jpeg,image/png,image/webp,application/pdf">
                                <div class="form-text extra-small">Upload or update your Driver's License or Government ID.</div>
                            </div>

                            <!-- Home Address -->
                            <div class="col-12">
                                <label for="address" class="form-label fw-semibold small text-dark">
                                    Home Address <span class="text-danger">*</span>
                                </label>
                                <textarea name="address" 
                                          id="address" 
                                          rows="3" 
                                          class="form-control" 
                                          placeholder="House #, Street, Barangay, City/Municipality, Province" 
                                          required><?= e($rider['address'] ?? '') ?></textarea>
                            </div>

                            <!-- Submit Button -->
                            <div class="col-12 text-end">
                                <button type="submit" class="btn btn-primary px-4 fw-semibold shadow-sm">
                                    <i class="bi bi-save me-1"></i>Save Profile Changes
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Password Change Card -->
            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-header bg-white py-3 border-bottom">
                    <h5 class="card-title mb-0 fw-bold d-flex align-items-center gap-2">
                        <i class="bi bi-shield-lock text-primary"></i>Change Account Password
                    </h5>
                </div>
                <div class="card-body p-3 p-md-4">
                    <?php if (!empty($passwordErrors)): ?>
                        <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <ul class="mb-0 ps-3">
                                <?php foreach ($passwordErrors as $err): ?>
                                    <li><?= e($err) ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <form action="<?= url('pages/rider/profile.php') ?>" method="POST" id="riderPasswordForm">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="change_password">

                        <div class="row g-3">
                            <!-- Current Password -->
                            <div class="col-12">
                                <label for="current_password" class="form-label fw-semibold small text-dark">
                                    Current Password <span class="text-danger">*</span>
                                </label>
                                <input type="password" 
                                       name="current_password" 
                                       id="current_password" 
                                       class="form-control" 
                                       placeholder="Enter your current password" 
                                       required>
                            </div>

                            <!-- New Password -->
                            <div class="col-md-6">
                                <label for="new_password" class="form-label fw-semibold small text-dark">
                                    New Password <span class="text-danger">*</span>
                                </label>
                                <input type="password" 
                                       name="new_password" 
                                       id="new_password" 
                                       class="form-control" 
                                       placeholder="Create new strong password" 
                                       required>
                            </div>

                            <!-- Confirm New Password -->
                            <div class="col-md-6">
                                <label for="confirm_new_password" class="form-label fw-semibold small text-dark">
                                    Confirm New Password <span class="text-danger">*</span>
                                </label>
                                <input type="password" 
                                       name="confirm_new_password" 
                                       id="confirm_new_password" 
                                       class="form-control" 
                                       placeholder="Repeat new password" 
                                       required>
                            </div>

                            <!-- Password Requirement Checklist -->
                            <div class="col-12">
                                <div class="p-3 bg-light rounded-3 border">
                                    <span class="text-muted extra-small text-uppercase fw-semibold d-block mb-2">Password Requirements:</span>
                                    <div class="row g-2 extra-small">
                                        <div class="col-sm-6" id="rule-length">
                                            <i class="bi bi-circle me-1 text-muted"></i> At least 8 characters
                                        </div>
                                        <div class="col-sm-6" id="rule-upper">
                                            <i class="bi bi-circle me-1 text-muted"></i> At least one uppercase letter (A-Z)
                                        </div>
                                        <div class="col-sm-6" id="rule-lower">
                                            <i class="bi bi-circle me-1 text-muted"></i> At least one lowercase letter (a-z)
                                        </div>
                                        <div class="col-sm-6" id="rule-number">
                                            <i class="bi bi-circle me-1 text-muted"></i> At least one number (0-9)
                                        </div>
                                        <div class="col-sm-6" id="rule-special">
                                            <i class="bi bi-circle me-1 text-muted"></i> At least one special symbol (!@#$%^&*)
                                        </div>
                                        <div class="col-sm-6" id="rule-match">
                                            <i class="bi bi-circle me-1 text-muted"></i> Passwords must match
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Submit Button -->
                            <div class="col-12 text-end">
                                <button type="submit" class="btn btn-danger px-4 fw-semibold shadow-sm">
                                    <i class="bi bi-key me-1"></i>Update Password
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../../templates/footer.php';
?>
