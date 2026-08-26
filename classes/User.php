<?php
/**
 * User Model Class
 * LPG Delivery System v2
 */

require_once __DIR__ . '/Database.php';

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
                full_name, email, password, role, phone, address, valid_id_path, status, created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
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
            $status
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
