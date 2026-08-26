<?php
/**
 * Customer Profile Management Page
 * LPG Delivery System v2
 *
 * View and update personal information, delivery address, contact phone,
 * optional government ID document upload, and account password security.
 */

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/middleware.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/User.php';

// Enforce customer access
require_role('customer');

$db = Database::connect();
$userModel = new User($db);

$customerId = (int)current_user_id();
$customer = $userModel->findById($customerId);

$profileErrors = [];
$passwordErrors = [];

// Handle POST submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Verify CSRF token
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Your security session has expired. Please refresh and try again.');
        redirect('/pages/customer/profile.php');
        return;
    }

    // 0. Profile Picture Upload (from summary card)
    if ($action === 'upload_picture') {
        if (!empty($_FILES['profile_picture']['name']) && $_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE) {
            $avatarResult = validate_profile_picture_upload($_FILES['profile_picture']);
            if (!$avatarResult['success']) {
                $profileErrors[] = $avatarResult['error'];
            } else {
                try {
                    $updated = $userModel->updateProfile($customerId, ['profile_picture' => $avatarResult['relative_path']]);
                    if ($updated) {
                        delete_old_avatar($customer['profile_picture'] ?? null);
                        if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
                            $_SESSION['user']['profile_picture'] = $avatarResult['relative_path'];
                        }
                        set_flash('success', 'Your profile picture has been updated successfully.');
                        redirect('/pages/customer/profile.php');
                        return;
                    } else {
                        $profileErrors[] = 'Failed to save profile picture. Please try again.';
                    }
                } catch (Throwable $e) {
                    $profileErrors[] = 'Error uploading picture: ' . $e->getMessage();
                }
            }
        } else {
            $profileErrors[] = 'Please choose an image file (JPG, PNG, or WebP) to upload.';
        }
    }

    // 1. Profile Details Update
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
            $profileErrors[] = 'Delivery address cannot be empty.';
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

        $avatarRelativePath = null;
        if (!empty($_FILES['profile_picture']['name']) && $_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE) {
            $avatarResult = validate_profile_picture_upload($_FILES['profile_picture']);
            if (!$avatarResult['success']) {
                $profileErrors[] = $avatarResult['error'];
            } else {
                $avatarRelativePath = $avatarResult['relative_path'];
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

            if ($avatarRelativePath !== null) {
                $updatePayload['profile_picture'] = $avatarRelativePath;
            }

            try {
                $updated = $userModel->updateProfile($customerId, $updatePayload);
                if ($updated) {
                    if ($avatarRelativePath !== null) {
                        delete_old_avatar($customer['profile_picture'] ?? null);
                    }
                    $_SESSION['user_name'] = $fullName;
                    if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
                        $_SESSION['user']['full_name'] = $fullName;
                        $_SESSION['user']['phone'] = $phone;
                        $_SESSION['user']['address'] = $address;
                        if ($validIdRelativePath !== null) {
                            $_SESSION['user']['valid_id_path'] = $validIdRelativePath;
                        }
                        if ($avatarRelativePath !== null) {
                            $_SESSION['user']['profile_picture'] = $avatarRelativePath;
                        }
                    }
                    set_flash('success', 'Your profile details have been successfully updated.');
                    redirect('/pages/customer/profile.php');
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
        } elseif (!password_verify($currentPass, $customer['password'])) {
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
                $updated = $userModel->updatePassword($customerId, $newPass);
                if ($updated) {
                    set_flash('success', 'Your password has been changed successfully.');
                    redirect('/pages/customer/profile.php');
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

// Reload fresh customer data
$customer = $userModel->findById($customerId);

$page_title = 'My Profile';
$current_page = 'profile';
$page_js = 'customer.js';

require_once __DIR__ . '/../../templates/header.php';
?>

<div class="container-fluid px-0" id="customerProfileContainer">
    <!-- Profile Page Header -->
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <h3 class="fw-bold mb-1 d-flex align-items-center gap-2">
                <i class="bi bi-person-circle text-primary"></i>Customer Profile
            </h3>
            <p class="text-muted small mb-0">Manage your account information, default delivery address, and security settings.</p>
        </div>
        <div>
            <span class="badge bg-primary text-uppercase px-3 py-2">
                <i class="bi bi-person-check me-1"></i>Customer Account
            </span>
        </div>
    </div>

    <div class="row g-4">
        <!-- Profile Overview Sidebar -->
        <div class="col-lg-4">
            <!-- User Summary Card -->
            <div class="card border-0 shadow-sm text-center p-4 rounded-3 mb-4">
                <div class="mx-auto mb-3">
                    <?php if (!empty($customer['profile_picture']) && is_file(dirname(__DIR__, 2) . '/' . ltrim($customer['profile_picture'], '/'))): ?>
                        <img src="<?= e(url($customer['profile_picture'])) ?>"
                             alt="Profile picture of <?= e($customer['full_name'] ?? 'Customer') ?>"
                             class="rounded-circle shadow-sm profile-avatar-lg"
                             style="border: 4px solid #e2e8f0;">
                    <?php else: ?>
                        <div class="user-avatar-circle profile-avatar-lg" style="font-size: 3rem;">
                            <?= e(strtoupper(substr($customer['full_name'] ?? 'U', 0, 1))) ?>
                        </div>
                    <?php endif; ?>
                </div>
                <!-- Profile Picture Upload (below the picture) -->
                <form action="<?= url('pages/customer/profile.php') ?>" method="POST" enctype="multipart/form-data" class="mb-3" id="customerAvatarForm">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="upload_picture">
                    <input type="file"
                           name="profile_picture"
                           id="profile_picture"
                           class="d-none"
                           accept="image/jpeg,image/png,image/webp">
                    <button type="button" class="btn btn-sm btn-primary fw-semibold px-3" id="customerAvatarBtn">
                        <i class="bi bi-upload me-1"></i>Upload Photo
                    </button>
                    <div class="form-text extra-small">Click to browse — JPG, PNG, or WebP (max 5MB).</div>
                </form>
                <h5 class="fw-bold mb-1 text-dark"><?= e($customer['full_name'] ?? 'Customer') ?></h5>
                <p class="text-muted small mb-2"><?= e($customer['email'] ?? '') ?></p>
                <div class="mb-3">
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-1">
                        <i class="bi bi-check-circle me-1"></i>Active Customer
                    </span>
                </div>
                <div class="border-top pt-3 text-start small">
                    <div class="mb-2">
                        <span class="text-muted d-block extra-small text-uppercase fw-semibold">Member Since</span>
                        <span class="fw-medium text-dark"><i class="bi bi-calendar3 me-2 text-primary"></i><?= e(format_date($customer['created_at'], 'F d, Y')) ?></span>
                    </div>
                    <div class="mb-2">
                        <span class="text-muted d-block extra-small text-uppercase fw-semibold">Contact Phone</span>
                        <span class="fw-medium text-dark"><i class="bi bi-telephone me-2 text-primary"></i><?= e($customer['phone'] ?? 'Not provided') ?></span>
                    </div>
                    <div>
                        <span class="text-muted d-block extra-small text-uppercase fw-semibold">Default Delivery Address</span>
                        <span class="fw-medium text-dark"><i class="bi bi-geo-alt me-2 text-danger"></i><?= e($customer['address'] ?? 'Not provided') ?></span>
                    </div>
                </div>
            </div>

            <!-- Valid ID Verification Card -->
            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="card-title mb-0 fw-bold d-flex align-items-center gap-2">
                        <i class="bi bi-card-heading text-primary"></i>Valid ID Verification
                    </h6>
                </div>
                <div class="card-body p-3">
                    <?php if (!empty($customer['valid_id_path'])): ?>
                        <div class="alert alert-success d-flex align-items-center p-3 mb-2 rounded-3" role="alert">
                            <i class="bi bi-shield-check fs-4 me-2 text-success flex-shrink-0"></i>
                            <div>
                                <strong class="d-block">Government ID Verified</strong>
                                <small class="text-muted">Document on file</small>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning d-flex align-items-center p-3 mb-2 rounded-3" role="alert">
                            <i class="bi bi-exclamation-circle fs-4 me-2 text-warning flex-shrink-0"></i>
                            <div>
                                <strong class="d-block">No Valid ID Uploaded</strong>
                                <small class="text-muted">Optional: Upload a government ID to speed up order confirmation.</small>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Forms Column -->
        <div class="col-lg-8">
            <!-- Profile Details Form -->
            <div class="card border-0 shadow-sm rounded-3 mb-4">
                <div class="card-header bg-white py-3 border-bottom">
                    <h5 class="card-title mb-0 fw-bold d-flex align-items-center gap-2">
                        <i class="bi bi-person-gear text-primary"></i>Edit Profile Information
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

                    <form action="<?= url('pages/customer/profile.php') ?>" method="POST" enctype="multipart/form-data" id="customerProfileForm">
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
                                       value="<?= e($customer['full_name'] ?? '') ?>" 
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
                                       value="<?= e($customer['email'] ?? '') ?>" 
                                       disabled>
                                <div class="form-text extra-small">Email address is your unique login ID and cannot be changed directly.</div>
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
                                           value="<?= e($customer['phone'] ?? '') ?>" 
                                           required>
                                </div>
                                <div class="form-text extra-small">11-digit Philippine mobile format starting with 09.</div>
                            </div>

                            <!-- Valid ID Upload (Optional) -->
                            <div class="col-md-6">
                                <label for="valid_id" class="form-label fw-semibold small text-dark">
                                    Valid ID Document <span class="text-muted extra-small fw-normal">(Optional, JPG/PNG/PDF max 5MB)</span>
                                </label>
                                <input type="file" 
                                       name="valid_id" 
                                       id="valid_id" 
                                       class="form-control" 
                                       accept="image/jpeg,image/png,image/webp,application/pdf">
                                <div class="form-text extra-small">Upload government-issued ID (Passport, Driver's License, UMID, National ID).</div>
                            </div>

                            <!-- Delivery Address -->
                            <div class="col-12">
                                <label for="address" class="form-label fw-semibold small text-dark">
                                    Default Delivery Address <span class="text-danger">*</span>
                                </label>
                                <textarea name="address" 
                                          id="address" 
                                          rows="3" 
                                          class="form-control" 
                                          placeholder="House/Unit #, Street Name, Barangay, Municipality/City, Province, Landmark" 
                                          required><?= e($customer['address'] ?? '') ?></textarea>
                            </div>

                            <!-- Submit Button -->
                            <div class="col-12 text-end">
                                <button type="submit" class="btn btn-primary px-4 fw-semibold">
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

                    <form action="<?= url('pages/customer/profile.php') ?>" method="POST" id="customerPasswordForm">
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
                                       placeholder="Enter your existing password" 
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
                                <button type="submit" class="btn btn-danger px-4 fw-semibold">
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
