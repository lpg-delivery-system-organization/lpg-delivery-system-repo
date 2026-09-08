<?php
/**
 * Chat Message Model
 * LPG Delivery System v2
 *
 * Manages rider-customer chat messages tied to orders.
 */

require_once __DIR__ . '/Database.php';

date_default_timezone_set('Asia/Manila');

class ChatMessage
{
    private $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Send a new chat message.
     *
     * @param int    $orderId   Order ID
     * @param int    $senderId  User ID of sender
     * @param string $message   Message text
     * @return int   Inserted message ID
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function send(int $orderId, int $senderId, string $message): int
    {
        $message = trim($message);
        if ($message === '') {
            throw new InvalidArgumentException('Message cannot be empty.');
        }
        if (mb_strlen($message) > 2000) {
            throw new InvalidArgumentException('Message must not exceed 2000 characters.');
        }
        if ($orderId <= 0 || $senderId <= 0) {
            throw new InvalidArgumentException('Invalid order or sender ID.');
        }

        $stmt = $this->db->prepare(
            "INSERT INTO chat_messages (order_id, sender_id, message, created_at)
             VALUES (:order_id, :sender_id, :message, date('Y-m-d H:i:s'))"
        );
        $stmt->execute([
            ':order_id'  => $orderId,
            ':sender_id' => $senderId,
            ':message'   => $message,
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Get messages for an order, optionally after a specific message ID (for polling).
     *
     * @param int      $orderId
     * @param int|null $afterId  Only return messages with id > $afterId
     * @param int      $limit    Max messages to return
     * @return array
     */
    public function getByOrder(int $orderId, ?int $afterId = null, int $limit = 100): array
    {
        if ($afterId !== null && $afterId > 0) {
            $stmt = $this->db->prepare(
                "SELECT cm.id, cm.order_id, cm.sender_id, cm.message, cm.created_at,
                        u.full_name AS sender_name, u.role AS sender_role
                 FROM chat_messages cm
                 JOIN users u ON u.id = cm.sender_id
                 WHERE cm.order_id = :order_id AND cm.id > :after_id
                 ORDER BY cm.id ASC
                 LIMIT :lim"
            );
            $stmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
            $stmt->bindValue(':after_id', $afterId, PDO::PARAM_INT);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        } else {
            $stmt = $this->db->prepare(
                "SELECT cm.id, cm.order_id, cm.sender_id, cm.message, cm.created_at,
                        u.full_name AS sender_name, u.role AS sender_role
                 FROM chat_messages cm
                 JOIN users u ON u.id = cm.sender_id
                 WHERE cm.order_id = :order_id
                 ORDER BY cm.id DESC
                 LIMIT :lim"
            );
            $stmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        }

        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // When fetching "latest" (no afterId), reverse so oldest-first for display
        if ($afterId === null) {
            $rows = array_reverse($rows);
        }

        return $rows;
    }

    /**
     * Get unread message count for a specific user in an order chat.
     *
     * @param int $orderId
     * @param int $userId      The viewer's user ID
     * @param int $lastSeenId  Last message ID the viewer has seen
     * @return int
     */
    public function getUnreadCount(int $orderId, int $userId, int $lastSeenId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS cnt
             FROM chat_messages
             WHERE order_id = :order_id
               AND sender_id != :user_id
               AND id > :last_id"
        );
        $stmt->execute([
            ':order_id' => $orderId,
            ':user_id'  => $userId,
            ':last_id'  => $lastSeenId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row['cnt'] ?? 0);
    }
}
