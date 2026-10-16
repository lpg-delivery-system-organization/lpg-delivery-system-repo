<?php
/**
 * User Model Class
 * LPG Delivery System v2
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/../includes/helpers.php';

class User {
    /**
     * @var PDO
     */
    private PDO $db;

    /**
     * User Constructor
     *
     * @param PDO|null $db Optional PDO database connection instance
     */
    public function __construct(?PDO $db = null) {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Find a user by their email address
     *
     * @param string $email
     * @return array|null
     */
    public function findByEmail(string $email): ?array {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([trim(strtolower($email))]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    /**
     * Find a user by their primary key ID
     *
     * @param int $id
     * @return array|null
     */
    public function findById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    /**
     * Check if an email address already exists in the users table
     *
     * @param string $email
     * @param int|null $excludeUserId Optional user ID to exclude (for profile updates)
     * @return bool
     */
    public function emailExists(string $email, ?int $excludeUserId = null): bool {
        $email = trim(strtolower($email));
        if ($excludeUserId !== null) {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$email, $excludeUserId]);
        } else {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
            $stmt->execute([$email]);
        }
        return ((int)$stmt->fetchColumn()) > 0;
    }

    /**
     * Create a new user record
     *
     * @param array $data Associative array containing user attributes
     * @return int The ID of the newly created user
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function create(array $data): int {
        $fullName = trim($data['full_name'] ?? '');
        $email = trim(strtolower($data['email'] ?? ''));
        $rawPassword = $data['password'] ?? '';
        $role = $data['role'] ?? 'customer';
        $phone = trim($data['phone'] ?? '');
        $address = trim($data['address'] ?? '');
        $validIdPath = $data['valid_id_path'] ?? null;
        $status = $data['status'] ?? 'active';
        // Self-registered customers are explicitly created as 'pending'; every
        // other caller (admin provisioning, seed/script creation, tests) defaults
        // to 'approved' so ID verification never blocks accounts it doesn't own.
        $idVerificationStatus = $data['id_verification_status'] ?? 'approved';

        if (empty($fullName) || empty($email) || empty($rawPassword) || empty($phone) || empty($address)) {
            throw new InvalidArgumentException("Required fields: full_name, email, password, phone, and address are mandatory.");
        }

        $validRoles = ['customer', 'admin', 'rider'];
        if (!in_array($role, $validRoles, true)) {
            throw new InvalidArgumentException("Invalid role '{$role}'. Allowed roles: " . implode(', ', $validRoles));
        }

        $validStatuses = ['active', 'inactive', 'suspended'];
        if (!in_array($status, $validStatuses, true)) {
            throw new InvalidArgumentException("Invalid status '{$status}'. Allowed statuses: " . implode(', ', $validStatuses));
        }

        $validVerificationStatuses = ['pending', 'approved', 'rejected'];
        if (!in_array($idVerificationStatus, $validVerificationStatuses, true)) {
            throw new InvalidArgumentException("Invalid ID verification status '{$idVerificationStatus}'. Allowed: " . implode(', ', $validVerificationStatuses));
        }

        if ($this->emailExists($email)) {
            throw new RuntimeException("A user with the email address '{$email}' already exists.");
        }

        // Hash password with Bcrypt cost 12 if not already hashed
        $passwordInfo = password_get_info($rawPassword);
        if ($passwordInfo['algo'] === 0 || $passwordInfo['algo'] === null) {
            $hashedPassword = password_hash($rawPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        } else {
            $hashedPassword = $rawPassword;
        }

        $stmt = $this->db->prepare("
            INSERT INTO users (
                full_name, email, password, role, phone, address, valid_id_path, status,
                id_verification_status, created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
            )
        ");

        $stmt->execute([
            $fullName,
            $email,
            $hashedPassword,
            $role,
            $phone,
            $address,
            $validIdPath,
            $status,
            $idVerificationStatus
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Update a user's password with Bcrypt cost 12
     *
     * @param int $userId
     * @param string $newPassword Plaintext or already hashed password
     * @return bool
     */
    public function updatePassword(int $userId, string $newPassword): bool {
        $passwordInfo = password_get_info($newPassword);
        if ($passwordInfo['algo'] === 0 || $passwordInfo['algo'] === null) {
            $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        } else {
            $hashedPassword = $newPassword;
        }

        $stmt = $this->db->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
        return $stmt->execute([$hashedPassword, $userId]);
    }

    /**
     * Update user profile information
     *
     * @param int $userId
     * @param array $data Contains full_name, phone, address, and optional valid_id_path / profile_picture
     * @return bool
     * @throws InvalidArgumentException
     */
    public function updateProfile(int $userId, array $data): bool {
        $fields = [];
        $params = [];

        if (isset($data['full_name'])) {
            $fields[] = "full_name = ?";
            $params[] = trim($data['full_name']);
        }
        if (isset($data['phone'])) {
            $fields[] = "phone = ?";
            $params[] = trim($data['phone']);
        }
        if (isset($data['address'])) {
            $fields[] = "address = ?";
            $params[] = trim($data['address']);
        }
        if (array_key_exists('valid_id_path', $data)) {
            $fields[] = "valid_id_path = ?";
            $params[] = $data['valid_id_path'];
        }
        if (array_key_exists('profile_picture', $data)) {
            $fields[] = "profile_picture = ?";
            $params[] = $data['profile_picture'];
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = "updated_at = NOW()";
        $params[] = $userId;

        $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Update a user's account status (active, inactive, suspended)
     *
     * @param int $userId
     * @param string $status
     * @return bool
     * @throws InvalidArgumentException
     */
    public function updateStatus(int $userId, string $status): bool {
        $validStatuses = ['active', 'inactive', 'suspended'];
        if (!in_array($status, $validStatuses, true)) {
            throw new InvalidArgumentException("Invalid status '{$status}'. Allowed statuses: " . implode(', ', $validStatuses));
        }

        $stmt = $this->db->prepare("UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?");
        return $stmt->execute([$status, $userId]);
    }

    /**
     * Approve a user's uploaded government ID.
     *
     * Clears any previous rejection reason and records who reviewed it.
     *
     * @param int $userId
     * @param int|null $adminId Reviewing administrator's user ID
     * @return bool
     */
    public function approveId(int $userId, ?int $adminId = null): bool {
        $stmt = $this->db->prepare("
            UPDATE users
               SET id_verification_status = 'approved',
                   id_rejection_reason = NULL,
                   id_reviewed_by = ?,
                   id_reviewed_at = NOW(),
                   updated_at = NOW()
             WHERE id = ?
        ");
        return $stmt->execute([$adminId, $userId]);
    }

    /**
     * Reject a user's uploaded government ID, recording the reason.
     *
     * @param int $userId
     * @param string $reason Human-readable reason shown to the applicant
     * @param int|null $adminId Reviewing administrator's user ID
     * @return bool
     * @throws InvalidArgumentException
     */
    public function rejectId(int $userId, string $reason, ?int $adminId = null): bool {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('A rejection reason is required.');
        }
        if (mb_strlen($reason) > 500) {
            $reason = mb_substr($reason, 0, 500);
        }

        $stmt = $this->db->prepare("
            UPDATE users
               SET id_verification_status = 'rejected',
                   id_rejection_reason = ?,
                   id_reviewed_by = ?,
                   id_reviewed_at = NOW(),
                   purge_warned_at = NULL,
                   updated_at = NOW()
             WHERE id = ?
        ");
        return $stmt->execute([$reason, $adminId, $userId]);
    }

    /**
     * Submit (or re-submit) a government ID for review.
     *
     * Resets the verification state to 'pending' and clears any prior decision
     * so a rejected applicant can upload a corrected document.
     *
     * @param int $userId
     * @param string $validIdPath Relative path to the stored ID document
     * @return bool
     */
    public function submitId(int $userId, string $validIdPath): bool {
        $stmt = $this->db->prepare("
            UPDATE users
               SET valid_id_path = ?,
                   id_verification_status = 'pending',
                   id_rejection_reason = NULL,
                   id_reviewed_by = NULL,
                   id_reviewed_at = NULL,
                   purge_warned_at = NULL,
                   updated_at = NOW()
             WHERE id = ?
        ");
        return $stmt->execute([$validIdPath, $userId]);
    }

    /**
     * Find rejected customers whose grace period has expired and are therefore
     * eligible for automatic purge.
     *
     * A customer qualifies when their government ID is still 'rejected', the
     * rejection is at least $graceHours old, and they have no order history.
     * Re-submitting an ID resets the status to 'pending' and clears
     * id_reviewed_at, so an applicant who complies is dropped from this set
     * automatically. Customers with orders are excluded so the ON DELETE
     * CASCADE can never destroy order/receipt data.
     *
     * @param int $graceHours Hours after rejection before purge (default from config)
     * @return array List of ['id' => int, 'valid_id_path' => ?string]
     */
    public function getExpiredRejections(int $graceHours = 0): array {
        $graceHours = $graceHours > 0 ? (int)$graceHours : (defined('ID_REJECTION_GRACE_HOURS') ? (int)ID_REJECTION_GRACE_HOURS : 24);

        $stmt = $this->db->prepare("
            SELECT u.id, u.valid_id_path
              FROM users u
             WHERE u.role = 'customer'
               AND u.id_verification_status = 'rejected'
               AND u.id_reviewed_at IS NOT NULL
               AND u.id_reviewed_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)
               AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.customer_id = u.id)
             ORDER BY u.id ASC
        ");
        $stmt->execute([$graceHours]);
        return $stmt->fetchAll();
    }

    /**
     * Permanently delete customers whose rejected ID was never resubmitted
     * within the grace window.
     *
     * Each candidate is re-validated as part of the DELETE so a concurrent
     * re-submission or a late order cannot be raced into deletion. The stored
     * government ID file is only removed once the row is confirmed deleted.
     *
     * @param int  $graceHours     Hours after rejection before purge
     * @param bool $deleteIdFiles  Also remove the uploaded ID document from disk
     * @return array Summary: ['purged' => int, 'files_deleted' => int, 'skipped_with_orders' => int, 'ids' => int[]]
     */
    public function purgeExpiredRejections(int $graceHours = 0, bool $deleteIdFiles = true): array {
        $graceHours = $graceHours > 0 ? (int)$graceHours : (defined('ID_REJECTION_GRACE_HOURS') ? (int)ID_REJECTION_GRACE_HOURS : 24);

        $summary = [
            'purged' => 0,
            'files_deleted' => 0,
            'skipped_with_orders' => 0,
            'ids' => [],
        ];

        // Report rejected applicants who are past the window but protected by
        // order history, so a caller can surface them for manual review.
        $skippedStmt = $this->db->prepare("
            SELECT COUNT(*)
              FROM users u
             WHERE u.role = 'customer'
               AND u.id_verification_status = 'rejected'
               AND u.id_reviewed_at IS NOT NULL
               AND u.id_reviewed_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)
               AND EXISTS (SELECT 1 FROM orders o WHERE o.customer_id = u.id)
        ");
        $skippedStmt->execute([$graceHours]);
        $summary['skipped_with_orders'] = (int)$skippedStmt->fetchColumn();

        // Row-safe re-check at delete time. NOT EXISTS on `orders` keeps any
        // customer with history out of reach of the cascade.
        $deleteStmt = $this->db->prepare("
            DELETE FROM users
             WHERE id = ?
               AND role = 'customer'
               AND id_verification_status = 'rejected'
               AND id_reviewed_at IS NOT NULL
               AND id_reviewed_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)
               AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.customer_id = users.id)
        ");

        foreach ($this->getExpiredRejections($graceHours) as $candidate) {
            $userId = (int)$candidate['id'];
            if ($userId <= 0) {
                continue;
            }

            $deleteStmt->execute([$userId, $graceHours]);
            if ($deleteStmt->rowCount() !== 1) {
                // Resubmitted, ordered, or already gone between SELECT and now.
                continue;
            }

            $summary['purged']++;
            $summary['ids'][] = $userId;

            if ($deleteIdFiles && $this->deleteStoredIdFile($candidate['valid_id_path'] ?? null)) {
                $summary['files_deleted']++;
            }
        }

        return $summary;
    }

    /**
     * Find rejected customers who are nearing the end of their grace window and
     * have not yet been warned. Sending is gated by purge_warned_at so each
     * applicant is reminded at most once per rejection.
     *
     * @param int $graceHours Hours after rejection before purge
     * @param int $leadHours  Send the reminder when this many hours remain
     * @return array
     */
    public function getRejectionsDueForReminder(int $graceHours = 0, int $leadHours = 0): array {
        $graceHours = $graceHours > 0 ? (int)$graceHours : (defined('ID_REJECTION_GRACE_HOURS') ? (int)ID_REJECTION_GRACE_HOURS : 24);
        $leadHours = $leadHours > 0 ? (int)$leadHours : (defined('ID_REJECTION_REMINDER_LEAD_HOURS') ? (int)ID_REJECTION_REMINDER_LEAD_HOURS : 4);

        // Reminder fires once the elapsed time reaches (grace - lead) but before
        // the grace window closes; the purge sweep handles the latter.
        $remindAfterHours = max(0, $graceHours - $leadHours);

        $stmt = $this->db->prepare("
            SELECT u.id, u.full_name, u.email, u.id_reviewed_at
              FROM users u
             WHERE u.role = 'customer'
               AND u.id_verification_status = 'rejected'
               AND u.purge_warned_at IS NULL
               AND u.id_reviewed_at IS NOT NULL
               AND u.id_reviewed_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)
               AND u.id_reviewed_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
               AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.customer_id = u.id)
             ORDER BY u.id ASC
        ");
        $stmt->execute([$remindAfterHours, $graceHours]);
        return $stmt->fetchAll();
    }

    /**
     * Mark a rejected applicant as having received the pending-purge reminder.
     *
     * The conditional WHERE makes the claim atomic: only the caller that
     * actually flips the NULL to a timestamp (rowCount === 1) should send the
     * notification, so concurrent sweeps cannot double-send.
     *
     * @param int $userId
     * @return bool True when this call claimed the reminder
     */
    public function markPurgeWarned(int $userId): bool {
        $stmt = $this->db->prepare("
            UPDATE users
               SET purge_warned_at = NOW()
             WHERE id = ? AND purge_warned_at IS NULL
        ");
        $stmt->execute([$userId]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Safely delete a stored ID document from disk.
     *
     * Confines deletion to the uploads/ directory and rejects path traversal,
     * so a corrupted database value can never remove an arbitrary file.
     *
     * @param string|null $relativePath Stored path, e.g. uploads/ids/id_ab12.png
     * @return bool True when a file was actually removed
     */
    private function deleteStoredIdFile(?string $relativePath): bool {
        $relativePath = trim((string)$relativePath);
        if ($relativePath === '') {
            return false;
        }

        $absolute = upload_abs_path($relativePath);
        if ($absolute === null) {
            return false;
        }

        return @unlink($absolute);
    }

    /**
     * Count self-registered customer accounts still awaiting ID verification.
     *
     * @return int
     */
    public function countPendingVerifications(): int {
        $stmt = $this->db->query("SELECT COUNT(*) FROM users WHERE role = 'customer' AND id_verification_status = 'pending'");
        return (int)$stmt->fetchColumn();
    }

    /**
     * Retrieve users by their ID verification status.
     *
     * @param string $status pending|approved|rejected
     * @param string|null $role Optional role filter (e.g. 'customer')
     * @return array
     * @throws InvalidArgumentException
     */
    public function getByVerificationStatus(string $status, ?string $role = null): array {
        $valid = ['pending', 'approved', 'rejected'];
        if (!in_array($status, $valid, true)) {
            throw new InvalidArgumentException("Invalid verification status '{$status}'.");
        }

        if ($role !== null) {
            $stmt = $this->db->prepare("SELECT * FROM users WHERE id_verification_status = ? AND role = ? ORDER BY id DESC");
            $stmt->execute([$status, $role]);
        } else {
            $stmt = $this->db->prepare("SELECT * FROM users WHERE id_verification_status = ? ORDER BY id DESC");
            $stmt->execute([$status]);
        }
        return $stmt->fetchAll();
    }

    /**
     * Retrieve all users filtered by role
     *
     * @param string $role
     * @return array
     */
    public function getAllByRole(string $role): array {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE role = ? ORDER BY id DESC");
        $stmt->execute([$role]);
        return $stmt->fetchAll();
    }

    /**
     * Retrieve all users
     *
     * @return array
     */
    public function getAll(): array {
        $stmt = $this->db->query("SELECT * FROM users ORDER BY id DESC");
        return $stmt->fetchAll();
    }

    /**
     * Count users by role
     *
     * @param string $role
     * @return int
     */
    public function countByRole(string $role): int {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM users WHERE role = ?");
        $stmt->execute([$role]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Create a password reset token
     *
     * @param int $userId
     * @param string $token
     * @param int $expiresInMinutes
     * @return bool
     */
    public function createPasswordResetToken(int $userId, string $token, int $expiresInMinutes = 60): bool {
        $stmt = $this->db->prepare("
            INSERT INTO password_resets (user_id, token, expires_at, used, created_at)
            VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), 0, NOW())
        ");
        return $stmt->execute([$userId, $token, $expiresInMinutes]);
    }

    /**
     * Verify a password reset token
     *
     * @param string $token
     * @return array|null Returns password_reset row with user data if valid and unexpired
     */
    public function verifyPasswordResetToken(string $token): ?array {
        $stmt = $this->db->prepare("
            SELECT pr.*, u.email, u.full_name
            FROM password_resets pr
            JOIN users u ON pr.user_id = u.id
            WHERE pr.token = ? AND pr.used = 0 AND pr.expires_at > NOW()
            ORDER BY pr.id DESC
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Mark a password reset token as used
     *
     * @param string $token
     * @return bool
     */
    public function markPasswordResetUsed(string $token): bool {
        $stmt = $this->db->prepare("UPDATE password_resets SET used = 1 WHERE token = ?");
        return $stmt->execute([$token]);
    }
}
