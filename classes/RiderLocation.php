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
            ORDER BY id DESC
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
            ORDER BY rl.id DESC
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
     * Get the best available GPS fix for an order, preferring quality over
     * recency so a single coarse WiFi/IP reading cannot yank the marker off a
     * good satellite fix.
     *
     * Resolution order:
     *   1. Most recent fix that is both accurate (<= $maxAccuracy metres) and
     *      fresh (within $maxAgeSeconds).
     *   2. Otherwise the most accurate recent fix (null accuracy last).
     *   3. Otherwise the latest fix of any age/quality (never regress to null
     *      while data exists).
     *
     * The returned row is enriched with `age_seconds` (int, relative to MySQL
     * NOW()) and the derived booleans `is_accurate` / `is_stale`.
     *
     * @param int $orderId
     * @param int $maxAccuracy  Maximum acceptable accuracy radius, in metres
     * @param int $maxAgeSeconds Age after which a fix is considered stale
     * @return array|null
     */
    public function getBestByOrder(int $orderId, int $maxAccuracy = 100, int $maxAgeSeconds = 180): ?array {
        $select = "
            SELECT rl.id, rl.order_id, rl.rider_id, rl.latitude, rl.longitude, rl.accuracy, rl.recorded_at,
                   u.full_name AS rider_name,
                   TIMESTAMPDIFF(SECOND, rl.recorded_at, NOW()) AS age_seconds
            FROM rider_locations rl
            JOIN users u ON u.id = rl.rider_id
            WHERE rl.order_id = ?";

        // 1. Recent + accurate.
        $row = $this->fetchFirst(
            $select . "
              AND rl.accuracy IS NOT NULL
              AND rl.accuracy <= ?
              AND rl.recorded_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
            ORDER BY rl.id DESC
            LIMIT 1",
            [$orderId, $maxAccuracy, $maxAgeSeconds]
        );

        // 2. Most accurate recent fix (regardless of radius).
        if ($row === null) {
            $row = $this->fetchFirst(
                $select . "
                  AND rl.recorded_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
                ORDER BY (rl.accuracy IS NULL) ASC, rl.accuracy ASC, rl.id DESC
                LIMIT 1",
                [$orderId, $maxAgeSeconds]
            );
        }

        // 3. Latest fix of any age/quality.
        if ($row === null) {
            $row = $this->fetchFirst($select . " ORDER BY rl.id DESC LIMIT 1", [$orderId]);
        }

        if ($row === null) {
            return null;
        }

        $accuracy = ($row['accuracy'] !== null) ? (float)$row['accuracy'] : null;
        $age = ($row['age_seconds'] !== null) ? (int)$row['age_seconds'] : null;

        $row['accuracy'] = $accuracy;
        $row['age_seconds'] = $age;
        $row['is_accurate'] = ($accuracy !== null && $accuracy <= $maxAccuracy);
        $row['is_stale'] = ($age === null || $age > $maxAgeSeconds);

        return $row;
    }

    /**
     * Execute a prepared SELECT and return the first row (or null).
     *
     * @param string $sql
     * @param array  $params
     * @return array|null
     */
    private function fetchFirst(string $sql, array $params): ?array {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
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
