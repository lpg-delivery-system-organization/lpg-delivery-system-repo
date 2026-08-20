<?php
/**
 * Admin User Account Management Portal
 * LPG Delivery System v2
 *
 * Provides:
 * - Listing of all customer, rider, and administrator accounts
 * - Role-based filtering and instant search by name, email, or contact number
 * - Secure proxy link / modal preview for uploaded government/valid ID documents
 * - Account activation, deactivation, and suspension status toggles with self-lockout safeguards
 * - Create new administrator, rider, and customer accounts
 * - AJAX & Synchronous POST workflows with CSRF verification
 */

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/middleware.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/User.php';

// Enforce admin role guard
require_role('admin');

$db = Database::connect();
$userModel = new User($db);

$currentAdminId = (int)current_user_id();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        if (is_ajax()) {
            json_response(['success' => false, 'error' => 'Security token invalid or expired.'], 403);
        }
        set_flash('error', 'Security token invalid or expired. Please try again.');
        redirect('/pages/admin/users.php');
        return;
    }

    $action = sanitize_input($_POST['action'] ?? '');
    $userId = (int)($_POST['user_id'] ?? 0);

    try {
        switch ($action) {
            case 'update_status':
                if ($userId <= 0) {
                    throw new InvalidArgumentException('Invalid user ID specified.');
                }
                $newStatus = sanitize_input($_POST['status'] ?? '');
                if (!in_array($newStatus, ['active', 'inactive', 'suspended'], true)) {
                    throw new InvalidArgumentException("Invalid status '{$newStatus}'.");
                }

                // Guard: Prevent admin from deactivating or suspending their own currently logged-in account
                if ($userId === $currentAdminId && $newStatus !== 'active') {
                    throw new RuntimeException('You cannot suspend or deactivate your own administrator account.');
                }

                $user = $userModel->findById($userId);
                if (!$user) {
                    throw new InvalidArgumentException("User #{$userId} not found.");
                }

                $userModel->updateStatus($userId, $newStatus);

                if (is_ajax()) {
                    json_response([
                        'success' => true,
                        'message' => "User status updated to {$newStatus}.",
                        'user_id' => $userId,
                        'status' => $newStatus
                    ]);
                }
                set_flash('success', "Account status for {$user['full_name']} updated to " . ucfirst($newStatus) . ".");
                break;

            case 'create_user':
                $fullName = sanitize_input($_POST['full_name'] ?? '');
                $email = sanitize_input($_POST['email'] ?? '');
                $password = $_POST['password'] ?? '';
                $role = sanitize_input($_POST['role'] ?? 'customer');
                $phone = sanitize_input($_POST['phone'] ?? '');
                $address = sanitize_input($_POST['address'] ?? '');
                $status = sanitize_input($_POST['status'] ?? 'active');

                if (empty($fullName) || empty($email) || empty($password) || empty($phone) || empty($address)) {
                    throw new InvalidArgumentException('Full name, email, password, phone, and address are required.');
                }

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException('Please enter a valid email address.');
                }

                if (strlen($password) < 8) {
                    throw new InvalidArgumentException('Password must be at least 8 characters long.');
                }

                if (!in_array($role, ['customer', 'rider', 'admin'], true)) {
                    $role = 'customer';
                }

                if (!in_array($status, ['active', 'inactive', 'suspended'], true)) {
                    $status = 'active';
                }

                if ($userModel->emailExists($email)) {
                    throw new RuntimeException("A user with the email '{$email}' already exists.");
                }

                $newUserId = $userModel->create([
                    'full_name' => $fullName,
                    'email'     => $email,
                    'password'  => $password,
                    'role'      => $role,
                    'phone'     => $phone,
                    'address'   => $address,
                    'status'    => $status
                ]);

                if (is_ajax()) {
                    json_response([
                        'success' => true,
                        'message' => "User account for {$fullName} created successfully.",
                        'user_id' => $newUserId
                    ]);
                }
                set_flash('success', "New {$role} account for {$fullName} created successfully.");
                break;

            case 'update_user':
                if ($userId <= 0) {
                    throw new InvalidArgumentException('Invalid user ID specified.');
                }
                $fullName = sanitize_input($_POST['full_name'] ?? '');
                $phone = sanitize_input($_POST['phone'] ?? '');
                $address = sanitize_input($_POST['address'] ?? '');
                $status = sanitize_input($_POST['status'] ?? 'active');

                if (empty($fullName) || empty($phone) || empty($address)) {
                    throw new InvalidArgumentException('Full name, phone, and address are required.');
                }

                // Self-lockout check
                if ($userId === $currentAdminId && $status !== 'active') {
                    throw new RuntimeException('You cannot change your own administrator account status to non-active.');
                }

                $userModel->updateProfile($userId, [
                    'full_name' => $fullName,
                    'phone'     => $phone,
                    'address'   => $address
                ]);

                if (in_array($status, ['active', 'inactive', 'suspended'], true)) {
                    $userModel->updateStatus($userId, $status);
                }

                if (is_ajax()) {
                    json_response([
                        'success' => true,
                        'message' => "User #{$userId} updated successfully.",
                        'user_id' => $userId
                    ]);
                }
                set_flash('success', "User account #{$userId} details updated successfully.");
                break;

            default:
                throw new InvalidArgumentException('Unknown user management action requested.');
        }
    } catch (Throwable $e) {
        if (is_ajax()) {
            json_response(['success' => false, 'error' => $e->getMessage()], 400);
        }
        set_flash('error', $e->getMessage());
    }

    redirect('/pages/admin/users.php');
    return;
}

// Retrieve all user records
$users = $userModel->getAll();

// Compute metric aggregations
$totalUsers = count($users);
$totalCustomers = 0;
$totalRiders = 0;
$totalAdmins = 0;
$suspendedCount = 0;

foreach ($users as $u) {
    $r = $u['role'] ?? 'customer';
    $st = $u['status'] ?? 'active';

    if ($r === 'customer') {
        $totalCustomers++;
    } elseif ($r === 'rider') {
        $totalRiders++;
    } elseif ($r === 'admin') {
        $totalAdmins++;
    }

    if ($st === 'suspended') {
        $suspendedCount++;
    }
}

$page_title = 'User Management';
$current_page = 'users';
$page_js = 'admin.js';

require_once __DIR__ . '/../../templates/header.php';
?>

<div class="container-fluid px-0" id="adminUsersContainer">
    <!-- Header Section -->
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <h3 class="fw-bold mb-1 d-flex align-items-center gap-2">
                <i class="bi bi-people text-primary"></i>User Account Management
            </h3>
            <p class="text-muted small mb-0">Inspect customer profiles, verify government ID uploads, and manage rider/admin credentials.</p>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-primary shadow-sm px-4 fw-semibold" data-bs-toggle="modal" data-bs-target="#addUserModal">
                <i class="bi bi-person-plus-fill me-1"></i>Add New User
            </button>
        </div>
    </div>

    <!-- User Metric Cards -->
    <div class="row g-3 mb-4">
        <!-- Total Users -->
        <div class="col-sm-6 col-xl-3">
            <div class="card app-stat-card border-0 shadow-sm h-100 p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">Total Accounts</span>
                        <h3 class="fw-bold my-1 text-dark"><?= $totalUsers ?></h3>
                        <span class="extra-small text-muted">Across all system roles</span>
                    </div>
                    <div class="stat-icon-wrapper bg-primary-subtle text-primary">
                        <i class="bi bi-people-fill"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Customers -->
        <div class="col-sm-6 col-xl-3">
            <div class="card app-stat-card border-0 shadow-sm h-100 p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">Customers</span>
                        <h3 class="fw-bold my-1 text-info"><?= $totalCustomers ?></h3>
                        <span class="extra-small text-muted">Registered LPG buyers</span>
                    </div>
                    <div class="stat-icon-wrapper bg-info-subtle text-info">
                        <i class="bi bi-person"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Riders -->
        <div class="col-sm-6 col-xl-3">
            <div class="card app-stat-card border-0 shadow-sm h-100 p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">Delivery Riders</span>
                        <h3 class="fw-bold my-1 text-warning"><?= $totalRiders ?></h3>
                        <span class="extra-small text-muted">Active dispatch crew</span>
                    </div>
                    <div class="stat-icon-wrapper bg-warning-subtle text-warning">
                        <i class="bi bi-truck"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Admins / Suspended -->
        <div class="col-sm-6 col-xl-3">
            <div class="card app-stat-card border-0 shadow-sm h-100 p-3 <?= $suspendedCount > 0 ? 'border-start border-danger border-4' : '' ?>">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">Admins & Suspended</span>
                        <h3 class="fw-bold my-1 text-dark"><?= $totalAdmins ?> <small class="text-danger fs-6 fw-normal">(<?= $suspendedCount ?> suspended)</small></h3>
                        <span class="extra-small text-muted"><?= $totalAdmins ?> system administrators</span>
                    </div>
                    <div class="stat-icon-wrapper bg-danger-subtle text-danger">
                        <i class="bi bi-shield-lock"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Role Filter Tabs -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-2 d-flex flex-wrap gap-2 align-items-center">
            <button type="button" class="btn btn-sm btn-primary active admin-user-filter-btn px-3" data-role="all">
                All Users <span class="badge bg-white text-primary ms-1"><?= $totalUsers ?></span>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary admin-user-filter-btn px-3" data-role="customer">
                Customers <span class="badge bg-secondary ms-1"><?= $totalCustomers ?></span>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary admin-user-filter-btn px-3" data-role="rider">
                Riders <span class="badge bg-secondary ms-1"><?= $totalRiders ?></span>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary admin-user-filter-btn px-3" data-role="admin">
                Administrators <span class="badge bg-secondary ms-1"><?= $totalAdmins ?></span>
            </button>
        </div>
    </div>

    <!-- Search & Status Filter Controls -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-3">
            <div class="row g-3 align-items-center">
                <div class="col-md-6">
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" class="form-control border-start-0" id="userSearchInput" placeholder="Search by user name, email, phone, or address...">
                    </div>
                </div>
                <div class="col-md-3">
                    <select class="form-select" id="userStatusFilterSelect">
                        <option value="all">All Account Statuses</option>
                        <option value="active">Active Only</option>
                        <option value="inactive">Inactive Only</option>
                        <option value="suspended">Suspended Only</option>
                    </select>
                </div>
                <div class="col-md-3 text-md-end text-muted small">
                    Showing <strong id="visibleUserCount"><?= $totalUsers ?></strong> users
                </div>
            </div>
        </div>
    </div>

    <!-- Users Table -->
    <div class="card border-0 shadow-sm rounded-3">
        <div class="card-body p-0">
            <?php if (empty($users)): ?>
                <div class="text-center py-5 px-3">
                    <div class="mb-3 text-muted">
                        <i class="bi bi-people fs-1 opacity-50"></i>
                    </div>
                    <h5 class="fw-bold">No users registered in database</h5>
                    <p class="text-muted small mb-0">Registered accounts will appear here.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="adminUsersTable">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4">ID</th>
                                <th>User Details</th>
                                <th>Role</th>
                                <th>Contact Phone</th>
                                <th>Delivery / Billing Address</th>
                                <th>Valid ID Document</th>
                                <th>Status</th>
                                <th>Registered</th>
                                <th class="text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <?php
                                $uId = (int)$user['id'];
                                $uName = (string)$user['full_name'];
                                $uEmail = (string)$user['email'];
                                $uRole = (string)$user['role'];
                                $uPhone = (string)$user['phone'];
                                $uAddress = (string)$user['address'];
                                $uStatus = (string)$user['status'];
                                $uValidId = (string)($user['valid_id_path'] ?? '');
                                $uInitial = strtoupper(substr($uName, 0, 1));
                                $isSelf = ($uId === $currentAdminId);

                                $roleBadgeClass = 'bg-secondary';
                                if ($uRole === 'admin') $roleBadgeClass = 'bg-dark text-white';
                                elseif ($uRole === 'rider') $roleBadgeClass = 'bg-warning text-dark';
                                elseif ($uRole === 'customer') $roleBadgeClass = 'bg-primary text-white';

                                $statusBadgeClass = 'bg-success';
                                if ($uStatus === 'suspended') $statusBadgeClass = 'bg-danger';
                                elseif ($uStatus === 'inactive') $statusBadgeClass = 'bg-secondary';
                                ?>
                                <tr class="admin-user-row"
                                    data-id="<?= $uId ?>"
                                    data-role="<?= e($uRole) ?>"
                                    data-status="<?= e($uStatus) ?>"
                                    data-name="<?= e($uName) ?>"
                                    data-email="<?= e($uEmail) ?>"
                                    data-phone="<?= e($uPhone) ?>"
                                    data-address="<?= e($uAddress) ?>"
                                    data-id-path="<?= e($uValidId) ?>">

                                    <!-- ID -->
                                    <td class="ps-4 fw-bold text-muted">#<?= $uId ?></td>

                                    <!-- User Details -->
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="user-avatar-circle flex-shrink-0" style="width: 34px; height: 34px; font-size: 14px;">
                                                <?= e($uInitial) ?>
                                            </div>
                                            <div class="overflow-hidden">
                                                <div class="fw-bold text-dark text-truncate" style="max-width: 180px;">
                                                    <?= e($uName) ?>
                                                    <?php if ($isSelf): ?>
                                                        <span class="badge bg-light text-primary border extra-small ms-1">You</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="text-muted extra-small text-truncate" style="max-width: 180px;"><?= e($uEmail) ?></div>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Role -->
                                    <td>
                                        <span class="badge <?= $roleBadgeClass ?> text-uppercase px-2 py-1">
                                            <?= e($uRole) ?>
                                        </span>
                                    </td>

                                    <!-- Phone -->
                                    <td>
                                        <span class="small text-dark fw-medium"><?= e($uPhone) ?></span>
                                    </td>

                                    <!-- Address -->
                                    <td>
                                        <div class="small text-muted text-truncate" style="max-width: 220px;" title="<?= e($uAddress) ?>">
                                            <i class="bi bi-geo-alt me-1 text-danger"></i><?= e($uAddress) ?>
                                        </div>
                                    </td>

                                    <!-- Valid ID Document -->
                                    <td>
                                        <?php if (!empty($uValidId)): ?>
                                            <a href="<?= url('pages/admin/view_id.php?user_id=' . $uId) ?>" 
                                               target="_blank" 
                                               class="btn btn-outline-info btn-sm p-1 px-2 text-decoration-none fw-semibold extra-small"
                                               title="View Government ID Document">
                                                <i class="bi bi-file-earmark-person me-1"></i>View ID
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted extra-small fst-italic">None</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Status -->
                                    <td>
                                        <span class="badge <?= $statusBadgeClass ?> text-uppercase px-2 py-1">
                                            <?= e($uStatus) ?>
                                        </span>
                                    </td>

                                    <!-- Registered Date -->
                                    <td class="text-muted small">
                                        <?= e(format_date($user['created_at'], 'M d, Y')) ?>
                                    </td>

                                    <!-- Actions -->
                                    <td class="text-end pe-4">
                                        <div class="d-inline-flex gap-1">
                                            <!-- Edit User Button -->
                                            <button type="button" 
                                                    class="btn btn-light btn-sm p-1 px-2 border btn-open-edit-user"
                                                    data-id="<?= $uId ?>"
                                                    data-name="<?= e($uName) ?>"
                                                    data-email="<?= e($uEmail) ?>"
                                                    data-phone="<?= e($uPhone) ?>"
                                                    data-address="<?= e($uAddress) ?>"
                                                    data-role="<?= e($uRole) ?>"
                                                    data-status="<?= e($uStatus) ?>"
                                                    title="Edit User Info">
                                                <i class="bi bi-pencil"></i>
                                            </button>

                                            <!-- Status Toggle Dropdown -->
                                            <?php if (!$isSelf): ?>
                                                <div class="dropdown d-inline">
                                                    <button class="btn btn-sm btn-outline-secondary p-1 px-2 dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Change Status">
                                                        <i class="bi bi-shield-shaded"></i>
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
                                                        <li class="dropdown-header small">Account Status</li>
                                                        <?php if ($uStatus !== 'active'): ?>
                                                            <li>
                                                                <form action="<?= url('pages/admin/users.php') ?>" method="POST" class="m-0">
                                                                    <?= csrf_input() ?>
                                                                    <input type="hidden" name="action" value="update_status">
                                                                    <input type="hidden" name="user_id" value="<?= $uId ?>">
                                                                    <input type="hidden" name="status" value="active">
                                                                    <button type="submit" class="dropdown-item text-success small">
                                                                        <i class="bi bi-check-circle me-2"></i>Set as <strong>Active</strong>
                                                                    </button>
                                                                </form>
                                                            </li>
                                                        <?php endif; ?>
                                                        <?php if ($uStatus !== 'inactive'): ?>
                                                            <li>
                                                                <form action="<?= url('pages/admin/users.php') ?>" method="POST" class="m-0">
                                                                    <?= csrf_input() ?>
                                                                    <input type="hidden" name="action" value="update_status">
                                                                    <input type="hidden" name="user_id" value="<?= $uId ?>">
                                                                    <input type="hidden" name="status" value="inactive">
                                                                    <button type="submit" class="dropdown-item text-secondary small">
                                                                        <i class="bi bi-dash-circle me-2"></i>Set as <strong>Inactive</strong>
                                                                    </button>
                                                                </form>
                                                            </li>
                                                        <?php endif; ?>
                                                        <?php if ($uStatus !== 'suspended'): ?>
                                                            <li><hr class="dropdown-divider my-1"></li>
                                                            <li>
                                                                <form action="<?= url('pages/admin/users.php') ?>" method="POST" class="m-0">
                                                                    <?= csrf_input() ?>
                                                                    <input type="hidden" name="action" value="update_status">
                                                                    <input type="hidden" name="user_id" value="<?= $uId ?>">
                                                                    <input type="hidden" name="status" value="suspended">
                                                                    <button type="submit" class="dropdown-item text-danger small">
                                                                        <i class="bi bi-slash-circle me-2"></i><strong>Suspend Account</strong>
                                                                    </button>
                                                                </form>
                                                            </li>
                                                        <?php endif; ?>
                                                    </ul>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filter Alert when no matches -->
    <div id="noUserFilteredAlert" class="alert alert-light border text-center p-4 shadow-sm my-4 d-none">
        <i class="bi bi-search fs-3 text-muted d-block mb-2"></i>
        <strong>No users found matching the selected filter and search criteria.</strong>
    </div>
</div>

<!-- Modal 1: Add New User -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form action="<?= url('pages/admin/users.php') ?>" method="POST" id="addUserForm">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="create_user">

                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold" id="addUserModalLabel">
                        <i class="bi bi-person-plus-fill me-2"></i>Create New User Account
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="newFullName" class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="full_name" id="newFullName" placeholder="e.g. Juan Dela Cruz" required>
                    </div>
                    <div class="mb-3">
                        <label for="newEmail" class="form-label fw-semibold">Email Address <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" name="email" id="newEmail" placeholder="e.g. juan@example.com" required>
                    </div>
                    <div class="mb-3">
                        <label for="newPassword" class="form-label fw-semibold">Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" name="password" id="newPassword" placeholder="Minimum 8 characters" required minlength="8">
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label for="newRole" class="form-label fw-semibold">Account Role <span class="text-danger">*</span></label>
                            <select class="form-select" name="role" id="newRole" required>
                                <option value="customer" selected>Customer</option>
                                <option value="rider">Delivery Rider</option>
                                <option value="admin">Administrator</option>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label for="newStatus" class="form-label fw-semibold">Account Status</label>
                            <select class="form-select" name="status" id="newStatus">
                                <option value="active" selected>Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="suspended">Suspended</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="newPhone" class="form-label fw-semibold">Contact Phone Number <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="phone" id="newPhone" placeholder="09171234567" required>
                    </div>
                    <div class="mb-3">
                        <label for="newAddress" class="form-label fw-semibold">Delivery / HQ Address <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="address" id="newAddress" rows="2" placeholder="Complete address" required></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4 fw-semibold">
                        <i class="bi bi-check-lg me-1"></i>Create Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal 2: Edit User Details -->
<div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form action="<?= url('pages/admin/users.php') ?>" method="POST" id="editUserForm">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" name="user_id" id="editUserId" value="0">

                <div class="modal-header bg-light border-bottom">
                    <h5 class="modal-title fw-bold" id="editUserModalLabel">
                        <i class="bi bi-pencil-square me-2 text-primary"></i>Edit User Profile
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="p-3 bg-light rounded mb-3 border">
                        <div class="fw-bold text-dark fs-6" id="editUserEmailDisplay">user@example.com</div>
                        <small class="text-muted">User ID: <span id="editUserIdDisplay">#</span> &bull; Role: <span id="editUserRoleDisplay" class="text-uppercase fw-semibold">-</span></small>
                    </div>

                    <div class="mb-3">
                        <label for="editFullName" class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="full_name" id="editFullName" required>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label for="editPhone" class="form-label fw-semibold">Contact Phone <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="phone" id="editPhone" required>
                        </div>
                        <div class="col-sm-6">
                            <label for="editStatus" class="form-label fw-semibold">Account Status</label>
                            <select class="form-select" name="status" id="editStatus">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="suspended">Suspended</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="editAddress" class="form-label fw-semibold">Address <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="address" id="editAddress" rows="2" required></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4 fw-semibold">
                        <i class="bi bi-check-lg me-1"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../../templates/footer.php';
?>
