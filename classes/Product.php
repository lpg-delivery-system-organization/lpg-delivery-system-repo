<?php
/**
 * Product Model Class
 * LPG Delivery System v2
 */

require_once __DIR__ . '/Database.php';

class Product {
    /**
     * @var PDO
     */
    private PDO $db;

    /**
     * Product Constructor
     *
     * @param PDO|null $db Optional PDO database connection instance
     */
    public function __construct(?PDO $db = null) {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Retrieve all active products for the customer catalog / shop
     *
     * @return array
     */
    public function getActive(): array {
        $stmt = $this->db->query("SELECT * FROM products WHERE status = 'active' ORDER BY id ASC");
        return $stmt->fetchAll();
    }

    /**
     * Retrieve all products (active and inactive) for admin inventory management
     *
     * @return array
     */
    public function getAll(): array {
        $stmt = $this->db->query("SELECT * FROM products ORDER BY id ASC");
        return $stmt->fetchAll();
    }

    /**
     * Find a product by its ID
     *
     * @param int $id
     * @return array|null
     */
    public function findById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM products WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $product = $stmt->fetch();
        return $product ?: null;
    }

    /**
     * Find a product by its ID with pessimistic row locking (FOR UPDATE)
     * Must be called inside an active database transaction.
     *
     * @param int $id
     * @return array|null
     */
    public function findByIdForUpdate(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM products WHERE id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $product = $stmt->fetch();
        return $product ?: null;
    }

    /**
     * Safely decrement a product's stock count ensuring non-negative balance
     *
     * @param int $id
     * @param int $quantity
     * @return bool True if successfully decremented, false if insufficient stock
     * @throws InvalidArgumentException
     */
    public function decrementStock(int $id, int $quantity): bool {
        if ($quantity <= 0) {
            throw new InvalidArgumentException("Quantity must be a positive integer.");
        }

        $stmt = $this->db->prepare("
            UPDATE products
            SET stock = stock - ?, updated_at = NOW()
            WHERE id = ? AND stock >= ?
        ");
        $stmt->execute([$quantity, $id, $quantity]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Increment a product's stock count (e.g. for order cancellation restock)
     *
     * @param int $id
     * @param int $quantity
     * @return bool
     * @throws InvalidArgumentException
     */
    public function incrementStock(int $id, int $quantity): bool {
        if ($quantity <= 0) {
            throw new InvalidArgumentException("Quantity must be a positive integer.");
        }

        $stmt = $this->db->prepare("
            UPDATE products
            SET stock = stock + ?, updated_at = NOW()
            WHERE id = ?
        ");
        return $stmt->execute([$quantity, $id]);
    }

    /**
     * Update stock level for a product
     *
     * @param int $id
     * @param int $stock
     * @return bool
     * @throws InvalidArgumentException
     */
    public function updateStock(int $id, int $stock): bool {
        if ($stock < 0) {
            throw new InvalidArgumentException("Stock quantity cannot be negative.");
        }

        $stmt = $this->db->prepare("UPDATE products SET stock = ?, updated_at = NOW() WHERE id = ?");
        return $stmt->execute([$stock, $id]);
    }

    /**
     * Update price for a product
     *
     * @param int $id
     * @param float $price
     * @return bool
     * @throws InvalidArgumentException
     */
    public function updatePrice(int $id, float $price): bool {
        if ($price < 0) {
            throw new InvalidArgumentException("Price cannot be negative.");
        }

        $stmt = $this->db->prepare("UPDATE products SET price = ?, updated_at = NOW() WHERE id = ?");
        return $stmt->execute([$price, $id]);
    }

    /**
     * Toggle product status between active and inactive
     *
     * @param int $id
     * @return bool
     */
    public function toggleStatus(int $id): bool {
        $stmt = $this->db->prepare("
            UPDATE products
            SET status = IF(status = 'active', 'inactive', 'active'), updated_at = NOW()
            WHERE id = ?
        ");
        return $stmt->execute([$id]);
    }

    /**
     * Create a new product
     *
     * @param array $data
     * @return int Inserted product ID
     * @throws InvalidArgumentException
     */
    public function create(array $data): int {
        $name = trim($data['name'] ?? '');
        $brand = trim($data['brand'] ?? '');
        $weight = trim($data['weight'] ?? '');
        $price = (float)($data['price'] ?? 0);
        $stock = (int)($data['stock'] ?? 0);
        $imageUrl = $data['image_url'] ?? null;
        $status = $data['status'] ?? 'active';

        if (empty($name) || empty($brand) || empty($weight) || $price <= 0 || $stock < 0) {
            throw new InvalidArgumentException("Invalid product parameters.");
        }

        $validStatuses = ['active', 'inactive'];
        if (!in_array($status, $validStatuses, true)) {
            throw new InvalidArgumentException("Invalid status: {$status}");
        }

        $stmt = $this->db->prepare("
            INSERT INTO products (name, brand, weight, price, stock, image_url, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([$name, $brand, $weight, $price, $stock, $imageUrl, $status]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Update product details
     *
     * @param int $id
     * @param array $data
     * @return bool
     */
    public function update(int $id, array $data): bool {
        $fields = [];
        $params = [];

        if (isset($data['name'])) {
            $fields[] = "name = ?";
            $params[] = trim($data['name']);
        }
        if (isset($data['brand'])) {
            $fields[] = "brand = ?";
            $params[] = trim($data['brand']);
        }
        if (isset($data['weight'])) {
            $fields[] = "weight = ?";
            $params[] = trim($data['weight']);
        }
        if (isset($data['price'])) {
            $fields[] = "price = ?";
            $params[] = (float)$data['price'];
        }
        if (isset($data['stock'])) {
            $fields[] = "stock = ?";
            $params[] = (int)$data['stock'];
        }
        if (array_key_exists('image_url', $data)) {
            $fields[] = "image_url = ?";
            $params[] = $data['image_url'];
        }
        if (isset($data['status'])) {
            $fields[] = "status = ?";
            $params[] = $data['status'];
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = "updated_at = NOW()";
        $params[] = $id;

        $sql = "UPDATE products SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
}
