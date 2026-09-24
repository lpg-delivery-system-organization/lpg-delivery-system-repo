<?php
/**
 * Notification Model
 * LPG Delivery System v2
 *
 * Persists user-facing notifications (new chat messages, order assignment
 * by admin, order delivered) which are polled by the client to render
 * toast popups and a notification bell.
 */

require_once __DIR__ . '/Database.php';

class Notification
{
    /**
     * @var PDO
     */
    private $db;

    /**
     * Notification Constructor
     *
     * @param PDO|null $db Optional PDO database connection instance
     */
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Create a new notification for a recipient user.
     *
     * @param int         $userId  Recipient user ID
     * @param string      $type    Notification type (e.g. chat_message, order_assigned, order_delivered)
     * @param string      $title   Short headline shown in the toast / bell
     * @param string      $message Body text shown in the toast / bell
     * @param int|null    $orderId Optional related order ID
     * @param string|null $link    Optional relative target URL (e.g. pages/rider/order-detail.php?id=5)
     * @return int Inserted notification ID
     * @throws InvalidArgumentException
     */
    public function create(int $userId, string $type, string $title, string $message, ?int $orderId = null, ?string $link = null): int
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('A valid recipient user ID is required.');
        }

        $title = trim($title);
        $message = trim($message);
        if ($title === '' || $message === '') {
            throw new InvalidArgumentException('Notification title and message are required.');
        }

        if (mb_strlen($title) > 191) {
            $title = mb_substr($title, 0, 191);
        }
        if (mb_strlen($message) > 1000) {
            $message = mb_substr($message, 0, 1000);
        }

        $stmt = $this->db->prepare(
            "INSERT INTO notifications (user_id, order_id, type, title, message, link, is_read, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 0, NOW())"
        );
        $stmt->execute([$userId, $orderId, $type, $title, $message, $link]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Fetch notifications newer than a reference ID (for client polling).
     * Always scoped to the requesting user.
     *
     * @param int $userId
     * @param int $afterId Only return notifications with id > $afterId
     * @param int $limit
     * @return array
     */
    public function getNew(int $userId, int $afterId = 0, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));

        if ($afterId > 0) {
            $stmt = $this->db->prepare("
                SELECT id, user_id, order_id, type, title, message, link, is_read, created_at
                FROM notifications
                WHERE user_id = ? AND id > ?
                ORDER BY id ASC
                LIMIT {$limit}
            ");
            $stmt->execute([$userId, $afterId]);
        } else {
            $stmt = $this->db->prepare("
                SELECT id, user_id, order_id, type, title, message, link, is_read, created_at
                FROM notifications
                WHERE user_id = ?
                ORDER BY id ASC
                LIMIT {$limit}
            ");
            $stmt->execute([$userId]);
        }

        return $stmt->fetchAll();
    }

    /**
     * Fetch the most recent notifications for the bell dropdown (newest first).
     *
     * @param int $userId
     * @param int $limit
     * @return array
     */
    public function getRecent(int $userId, int $limit = 15): array
    {
        $limit = max(1, min(50, $limit));

        $stmt = $this->db->prepare("
            SELECT id, user_id, order_id, type, title, message, link, is_read, created_at
            FROM notifications
            WHERE user_id = ?
            ORDER BY id DESC
            LIMIT {$limit}
        ");
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    /**
     * Count unread notifications for a user (bell badge).
     *
     * @param int $userId
     * @return int
     */
    public function getUnreadCount(int $userId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Mark a set of notification IDs as read for the requesting user (ignores
     * IDs that belong to other users as an extra authorization guard).
     *
     * @param int   $userId
     * @param array $ids
     * @return bool
     */
    public function markRead(int $userId, array $ids): bool
    {
        $ids = array_values(array_unique(array_filter($ids, function ($id) {
            return is_numeric($id) && (int)$id > 0;
        })));

        if (empty($ids)) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("
            UPDATE notifications
            SET is_read = 1
            WHERE user_id = ? AND id IN ({$placeholders})
        ");
        return $stmt->execute(array_merge([$userId], $ids));
    }

    /**
     * Mark all notifications as read for a user.
     *
     * @param int $userId
     * @return bool
     */
    public function markAllRead(int $userId): bool
    {
        $stmt = $this->db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        return $stmt->execute([$userId]);
    }
}