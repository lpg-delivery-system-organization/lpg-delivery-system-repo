<?php
/**
 * Customer Government ID Verification Status Page
 * LPG Delivery System v2
 *
 * Landing page for customers whose government ID is pending or was rejected.
 * It shows the current verification state and, when rejected (or when no ID is
 * on file), lets the applicant upload a new document which is queued for admin
 * review. Verified customers never see this page — they are forwarded onward by
 * the require_verified_id() middleware.
 */

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/middleware.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../includes/verification.php';

require_role('customer');

$db = Database::connect();
$userModel = new User($db);
$customerId = (int)current_user_id();

$errors = [];

// ── Re-upload / first-time upload POST ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resubmit_id') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your security token is invalid or expired. Please refresh and try again.';
    }

    $validIdPath = null;
    if (empty($errors)) {
        if (isset($_FILES['valid_id']) && $_FILES['valid_id']['error'] !== UPLOAD_ERR_NO_FILE) {
            $uploadResult = validate_id_upload($_FILES['valid_id']);
            if (!$uploadResult['success']) {
                $errors[] = 'ID upload failed: ' . $uploadResult['error'];
            } else {
                $validIdPath = $uploadResult['relative_path'];
            }
        } else {
            $errors[] = 'Please choose a government ID photo to upload.';
        }
    }

    if (empty($errors)) {
        $customer = $userModel->findById($customerId);
        if ($userModel->submitId($customerId, $validIdPath)) {
            if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
                $_SESSION['user']['valid_id_path'] = $validIdPath;
                $_SESSION['user']['id_verification_status'] = 'pending';
            }
            notify_admins_pending_verification($customerId, (string)($customer['full_name'] ?? 'Customer'));
            set_flash('success', 'Your new government ID has been submitted and is now pending verification.');
            redirect('/pages/customer/verification.php');
            return;
        }
        $errors[] = 'Could not save your ID submission. Please try again.';
    }
}

$customer = $userModel->findById($customerId);
if (!$customer) {
    set_flash('error', 'Your account could not be found. Please sign in again.');
    redirect('/index.php');
    return;
}

$verificationStatus = (string)($customer['id_verification_status'] ?? 'pending');
$rejectionReason = trim((string)($customer['id_rejection_reason'] ?? ''));
$validIdPath = (string)($customer['valid_id_path'] ?? '');

// Deletion deadline for a rejected applicant. Mirrors the purge sweep in
// classes/User.php and the admin-side hint in pages/admin/users.php: the account
// is removed ID_REJECTION_GRACE_HOURS after the rejection timestamp unless a new
// ID is submitted (which clears id_reviewed_at and flips the status to pending).
$purgeGraceHours = defined('ID_REJECTION_GRACE_HOURS') ? (int)ID_REJECTION_GRACE_HOURS : 24;
$purgeHoursLeft = null;
$purgeDeadline = '';
if ($verificationStatus === 'rejected' && !empty($customer['id_reviewed_at'])) {
    $purgeDeadlineTs = strtotime((string)$customer['id_reviewed_at']) + ($purgeGraceHours * 3600);
    $purgeHoursLeft = (int)ceil(($purgeDeadlineTs - time()) / 3600);
    $purgeDeadline = format_date(date('Y-m-d H:i:s', $purgeDeadlineTs));
}

// A verified customer has no reason to be here — send them to the shop.
if ($verificationStatus === 'approved') {
    redirect('/pages/customer/shop.php');
    return;
}

$page_title = 'Verify Your ID';
$current_page = 'verification';
$needs_maps = false;

require_once __DIR__ . '/../../templates/header.php';
?>

<div class="container py-4" style="max-width: 820px;">
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger d-flex align-items-start" role="alert">
            <i class="bi bi-exclamation-triangle-fill fs-5 me-2"></i>
            <div>
                <?php foreach ($errors as $err): ?>
                    <div class="small"><?= e($err) ?></div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-3 overflow-hidden mb-4" data-aos="fade-down">
        <div class="card-body p-4 p-md-5">
            <div class="text-center">
                <?php if ($verificationStatus === 'rejected'): ?>
                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-danger-subtle text-danger mb-3" style="width:72px;height:72px;">
                        <i class="bi bi-shield-x fs-1"></i>
                    </span>
                    <h4 class="fw-bold mb-1">Government ID rejected</h4>
                    <p class="text-muted mb-0">Please review the reason below and upload a valid ID to continue.</p>
                <?php else: ?>
                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-warning-subtle text-warning-emphasis mb-3" style="width:72px;height:72px;">
                        <i class="bi bi-hourglass-split fs-1"></i>
                    </span>
                    <h4 class="fw-bold mb-1">Verification in progress</h4>
                    <p class="text-muted mb-0">Your government ID is pending review by our team. You'll be notified once it's approved.</p>
                <?php endif; ?>
            </div>

            <?php if ($verificationStatus === 'rejected' && $purgeHoursLeft !== null): ?>
                <div class="alert alert-danger border-0 mt-4 mb-0" role="alert">
                    <div class="d-flex align-items-start">
                        <i class="bi bi-exclamation-triangle-fill fs-5 me-2"></i>
                        <div>
                            <?php if ($purgeHoursLeft > 0): ?>
                                <div class="fw-semibold mb-1">Action required: your account is scheduled for deletion</div>
                                <div class="small">
                                    Upload a valid government ID within
                                    <strong>~<?= max(0, $purgeHoursLeft) ?> hour<?= $purgeHoursLeft === 1 ? '' : 's' ?></strong>
                                    (by <strong><?= e($purgeDeadline) ?></strong>), or your account will be permanently deleted.
                                </div>
                            <?php else: ?>
                                <div class="fw-semibold mb-1">Your account is past its deletion deadline</div>
                                <div class="small">
                                    It is scheduled for removal. Upload a valid government ID below immediately to stop it.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($verificationStatus === 'pending'): ?>
                <div class="alert alert-warning border-0 small mt-4 mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    While your ID is under review you can sign in, but you won't be able to place orders until an administrator approves it.
                </div>
            <?php endif; ?>

            <?php if ($verificationStatus === 'rejected' && $rejectionReason !== ''): ?>
                <div class="alert alert-danger border-0 mt-4 mb-0">
                    <div class="fw-semibold mb-1"><i class="bi bi-chat-left-text me-1"></i>Reason from the reviewer</div>
                    <div class="small"><?= e($rejectionReason) ?></div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($validIdPath !== ''): ?>
        <div class="card border-0 shadow-sm rounded-3 mb-4" data-aos="fade-up">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-3"><i class="bi bi-file-earmark-person me-1 text-primary"></i>ID document on file</h6>
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <a href="<?= url('pages/customer/view_id.php') ?>" target="_blank" rel="noopener"
                       class="btn btn-outline-primary btn-sm fw-semibold">
                        <i class="bi bi-eye me-1"></i>View my submitted ID
                    </a>
                    <span class="text-muted small">Submitted for review</span>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-3" data-aos="fade-up">
        <div class="card-body p-4">
            <h6 class="fw-bold mb-1">
                <i class="bi bi-cloud-arrow-up me-1 text-primary"></i>
                <?= $verificationStatus === 'rejected' ? 'Upload a new government ID' : 'Submit a government ID' ?>
            </h6>
            <p class="text-muted small mb-3">JPG, PNG, WebP, or PDF — up to 5&nbsp;MB. Make sure the whole document is clearly visible.</p>

            <form action="<?= url('pages/customer/verification.php') ?>" method="POST" enctype="multipart/form-data" novalidate>
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="resubmit_id">
                <div class="mb-3">
                    <input type="file" class="form-control" name="valid_id" id="resubmitValidId"
                           accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf" required>
                </div>
                <button type="submit" class="btn btn-primary fw-semibold px-4">
                    <i class="bi bi-upload me-1"></i>Submit for verification
                </button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
