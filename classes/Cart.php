<?php
/**
 * Shopping Cart Model Class
 * LPG Delivery System v2
 *
 * Persistent, per-customer cart backed by the `cart_items` table (one row per
 * product, keyed by a UNIQUE(customer_id, product_id) constraint). A cart is a
 * staging area only: it never reserves stock and never creates an order. Prices
 * and stock are always read live from `products` so the customer can never be
 * shown a stale price, and the server re-validates everything again inside
 * Order::place() at checkout.
 */

require_once __DIR__ . '/Database.php';

class Cart {
    /** Hard ceiling on the quantity of a single line (mirrors the checkout input). */
    public const MAX_PER_ITEM = 10;

    /** @var PDO */
    private PDO $db;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?? Database::connect();
    }

    /**
     * All cart lines for a customer, enriched with live product data.
     *
     * @param int $customerId
     * @return array<int, array<string, mixed>>
     */
    public function getItems(int $customerId): array {
        $stmt = $this->db->prepare("
            SELECT ci.id, ci.product_id, ci.quantity,
                   p.name, p.brand, p.weight, p.price, p.stock, p.image_url, p.status
            FROM cart_items ci
            JOIN products p ON p.id = ci.product_id
            WHERE ci.customer_id = ?
            ORDER BY ci.created_at ASC, ci.id ASC
        ");
        $stmt->execute([$customerId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['id']         = (int)$row['id'];
            $row['product_id'] = (int)$row['product_id'];
            $row['quantity']   = (int)$row['quantity'];
            $row['price']      = (float)$row['price'];
            $row['stock']      = (int)$row['stock'];
            $row['subtotal']   = round($row['price'] * $row['quantity'], 2);
            $row['is_active']  = ($row['status'] === 'active');
            $row['within_stock'] = ($row['is_active'] && $row['quantity'] <= $row['stock']);
        }
        unset($row);

        return $rows;
    }

    /**
     * Cart totals used by the drawer badge and the checkout summary.
     *
     * @param int $customerId
     * @return array{items: array, line_count: int, units: int, subtotal: float}
     */
    public function getSummary(int $customerId): array {
        $items = $this->getItems($customerId);

        $units = 0;
        $subtotal = 0.0;
        foreach ($items as $item) {
            $units    += $item['quantity'];
            $subtotal += $item['subtotal'];
        }

        return [
            'items'      => $items,
            'line_count' => count($items),
            'units'      => $units,
            'subtotal'   => round($subtotal, 2),
        ];
    }

    /**
     * Total units in the cart (drives the header badge).
     *
     * @param int $customerId
     * @return int
     */
    public function countUnits(int $customerId): int {
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(quantity), 0) FROM cart_items WHERE customer_id = ?");
        $stmt->execute([$customerId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Add a product to the cart (or increase its quantity).
     *
     * @param int $customerId
     * @param int $productId
     * @param int $quantity
     * @return int The resulting line quantity
     * @throws InvalidArgumentException
     */
    public function addItem(int $customerId, int $productId, int $quantity = 1): int {
        if ($customerId <= 0 || $productId <= 0) {
            throw new InvalidArgumentException('A valid product is required.');
        }
        if ($quantity <= 0) {
            $quantity = 1;
        }

        $product = $this->loadActiveProduct($productId);

        $existing = $this->findLine($customerId, $productId);
        $currentQty = $existing ? (int)$existing['quantity'] : 0;
        $newQty = $currentQty + $quantity;

        $this->assertQuantityAllowed($product, $newQty);

        if ($existing) {
            $stmt = $this->db->prepare("
                UPDATE cart_items SET quantity = ?, updated_at = NOW()
                WHERE customer_id = ? AND product_id = ?
            ");
            $stmt->execute([$newQty, $customerId, $productId]);
        } else {
            $stmt = $this->db->prepare("
                INSERT INTO cart_items (customer_id, product_id, quantity, created_at, updated_at)
                VALUES (?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([$customerId, $productId, $newQty]);
        }

        return $newQty;
    }

    /**
     * Set an absolute quantity for a cart line.
     *
     * @param int $customerId
     * @param int $productId
     * @param int $quantity
     * @return int The resulting quantity
     * @throws InvalidArgumentException
     */
    public function updateItem(int $customerId, int $productId, int $quantity): int {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Quantity must be at least 1. Use remove to delete an item.');
        }

        $existing = $this->findLine($customerId, $productId);
        if (!$existing) {
            throw new InvalidArgumentException('That item is not in your cart.');
        }

        $product = $this->loadActiveProduct($productId);
        $this->assertQuantityAllowed($product, $quantity);

        $stmt = $this->db->prepare("
            UPDATE cart_items SET quantity = ?, updated_at = NOW()
            WHERE customer_id = ? AND product_id = ?
        ");
        $stmt->execute([$quantity, $customerId, $productId]);

        return $quantity;
    }

    /**
     * Remove a single product from the cart.
     *
     * @param int $customerId
     * @param int $productId
     * @return bool
     */
    public function removeItem(int $customerId, int $productId): bool {
        $stmt = $this->db->prepare("DELETE FROM cart_items WHERE customer_id = ? AND product_id = ?");
        $stmt->execute([$customerId, $productId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Empty the cart (called after an order is placed).
     *
     * @param int $customerId
     * @return bool
     */
    public function clear(int $customerId): bool {
        $stmt = $this->db->prepare("DELETE FROM cart_items WHERE customer_id = ?");
        return $stmt->execute([$customerId]);
    }

    /**
     * The upper bound for a single line: the smaller of MAX_PER_ITEM and stock.
     *
     * @param array $product
     * @return int
     */
    public function maxQuantityFor(array $product): int {
        return max(1, min(self::MAX_PER_ITEM, (int)$product['stock']));
    }

    /**
     * @param int $customerId
     * @param int $productId
     * @return array|null
     */
    private function findLine(int $customerId, int $productId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM cart_items WHERE customer_id = ? AND product_id = ? LIMIT 1");
        $stmt->execute([$customerId, $productId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * @param int $productId
     * @return array
     * @throws InvalidArgumentException
     */
    private function loadActiveProduct(int $productId): array {
        $stmt = $this->db->prepare("SELECT * FROM products WHERE id = ? LIMIT 1");
        $stmt->execute([$productId]);
        $product = $stmt->fetch();

        if (!$product) {
            throw new InvalidArgumentException('That product could not be found.');
        }
        if ($product['status'] !== 'active') {
            throw new InvalidArgumentException('That product is currently unavailable.');
        }
        if ((int)$product['stock'] <= 0) {
            throw new InvalidArgumentException('That product is out of stock.');
        }

        return $product;
    }

    /**
     * @param array $product
     * @param int $quantity
     * @return void
     * @throws InvalidArgumentException
     */
    private function assertQuantityAllowed(array $product, int $quantity): void {
        if ($quantity > self::MAX_PER_ITEM) {
            throw new InvalidArgumentException('You can order at most ' . self::MAX_PER_ITEM . ' units of a single cylinder.');
        }
        if ($quantity > (int)$product['stock']) {
            throw new InvalidArgumentException("Only {$product['stock']} unit(s) of '" . $product['name'] . "' are currently available.");
        }
    }
}
