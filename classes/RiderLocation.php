<?php
/**
 * RiderLocation Model Class
 * LPG Delivery System v2
 *
 * Manages real-time GPS location records for riders during active deliveries.
 */

require_once __DIR__ . '/Database.php';

class RiderLocation {
    /**
     * @var PDO
     */
    private PDO $db;

    /**
     * RiderLocation Constructor
     *
     * @param PDO|null $db Optional PDO database connection instance
     */
    public function __construct(?PDO $db = null) {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Store a new GPS location record for a rider on a specific order
     *
     * @param int $orderId
     * @param int $riderId
     * @param float $latitude
     * @param float $longitude
     * @param float|null $accuracy
     * @return int The created location record ID
     * @throws InvalidArgumentException
     */
    public function create(int $orderId, int $riderId, float $latitude, float $longitude, ?float $accuracy = null): int {
        if ($latitude < -90 || $latitude > 90) {
            throw new InvalidArgumentException("Invalid latitude value: {$latitude}");
        }
        if ($longitude < -180 || $longitude > 180) {
            throw new InvalidArgumentException("Invalid longitude value: {$longitude}");
        }

        $stmt = $this->db->prepare("
            INSERT INTO rider_locations (order_id, rider_id, latitude, longitude, accuracy, recorded_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$orderId, $riderId, $latitude, $longitude, $accuracy]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Get the most recent GPS location for a rider on a specific order
     *
     * @param int $orderId
     * @param int $riderId
     * @return array|null
     */
    public function getLatest(int $orderId, int $riderId): ?array {
        $stmt = $this->db->prepare("
            SELECT id, order_id, rider_id, latitude, longitude, accuracy, recorded_at
            FROM rider_locations
            WHERE order_id = ? AND rider_id = ?
            ORDER BY recorded_at DESC
            LIMIT 1
        ");
        $stmt->execute([$orderId, $riderId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Get the most recent GPS location for a given order (any rider)
     *
     * @param int $orderId
     * @return array|null
     */
    public function getLatestByOrder(int $orderId): ?array {
        $stmt = $this->db->prepare("
            SELECT rl.id, rl.order_id, rl.rider_id, rl.latitude, rl.longitude, rl.accuracy, rl.recorded_at,
                   u.full_name AS rider_name
            FROM rider_locations rl
            JOIN users u ON rl.rider_id = u.id
            WHERE rl.order_id = ?
            ORDER BY rl.recorded_at DESC
            LIMIT 1
        ");
        $stmt->execute([$orderId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Get location history trail for an order (used to draw route polyline)
     *
     * @param int $orderId
     * @param int $riderId
     * @param int $limit Maximum number of recent points to return
     * @return array
     */
    public function getHistory(int $orderId, int $riderId, int $limit = 50): array {
        $stmt = $this->db->prepare("
            SELECT latitude, longitude, recorded_at
            FROM rider_locations
            WHERE order_id = ? AND rider_id = ?
            ORDER BY recorded_at ASC
            LIMIT ?
        ");
        $stmt->execute([$orderId, $riderId, $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Purge old location records to keep the table lean
     * Deletes records older than the specified number of hours
     *
     * @param int $hoursOld Default 24 hours
     * @return int Number of deleted rows
     */
    public function purgeOld(int $hoursOld = 24): int {
        $stmt = $this->db->prepare("
            DELETE FROM rider_locations
            WHERE recorded_at < DATE_SUB(NOW(), INTERVAL ? HOUR)
        ");
        $stmt->execute([$hoursOld]);
        return $stmt->rowCount();
    }
}
