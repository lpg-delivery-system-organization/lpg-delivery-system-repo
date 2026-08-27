<?php
/**
 * Order Model Class
 * LPG Delivery System v2
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Product.php';
require_once __DIR__ . '/User.php';

class Order {
    /**
     * @var PDO
     */
    private PDO $db;

    /**
     * Valid state machine transitions
     */
    public const TRANSITIONS = [
        'pending_payment'    => ['pending', 'cancelled'],
        'pending'            => ['approved', 'cancelled'],
        'approved'           => ['ready_for_delivery', 'cancelled'],
        'ready_for_delivery' => ['picked_up', 'cancelled'],
        'picked_up'          => ['out_for_delivery'],
        'out_for_delivery'   => ['delivered'],
        'delivered'          => [],
        'cancelled'          => [],
    ];

    /**
     * Order Constructor
     *
     * @param PDO|null $db Optional PDO database connection instance
     */
    public function __construct(?PDO $db = null) {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Check if a status transition is allowed by the state machine
     *
     * @param string $currentStatus
     * @param string $newStatus
     * @return bool
     */
    public static function canTransition(string $currentStatus, string $newStatus): bool {
        $allowed = self::TRANSITIONS[$currentStatus] ?? [];
        return in_array($newStatus, $allowed, true);
    }

    /**
     * Place a new order with transactional concurrency safety and stock decrement
     *
     * @param array $orderData
     * @return int The created order ID
     * @throws InvalidArgumentException
     * @throws RuntimeException
     * @throws Throwable
     */
    public function place(array $orderData): int {
        $customerId = (int)($orderData['customer_id'] ?? 0);
        $productId = (int)($orderData['product_id'] ?? 0);
        $quantity = (int)($orderData['quantity'] ?? 0);
        $paymentMethod = $orderData['payment_method'] ?? 'cod';
        $paymentReference = isset($orderData['payment_reference']) ? trim((string)$orderData['payment_reference']) : null;
        $deliveryAddress = trim($orderData['delivery_address'] ?? '');
        $contactPhone = trim($orderData['contact_phone'] ?? '');
        $notes = isset($orderData['notes']) ? trim($orderData['notes']) : null;
        $status = $orderData['status'] ?? 'pending';

        // Resolve exact drop-off coordinates (client pin preferred, geocode fallback)
        [$deliveryLatitude, $deliveryLongitude] = $this->resolveDeliveryCoordinates(
            $deliveryAddress,
            $orderData['delivery_latitude'] ?? null,
            $orderData['delivery_longitude'] ?? null
        );

        if ($customerId <= 0 || $productId <= 0 || $quantity <= 0) {
            throw new InvalidArgumentException("Customer ID, Product ID, and a positive Quantity are required.");
        }

        if (empty($deliveryAddress) || empty($contactPhone)) {
            throw new InvalidArgumentException("Delivery address and contact phone number are required.");
        }

        $validPayments = ['cod', 'gcash'];
        if (!in_array($paymentMethod, $validPayments, true)) {
            throw new InvalidArgumentException("Invalid payment method '{$paymentMethod}'.");
        }

        // Begin transaction for row locking & atomic updates
        $this->db->beginTransaction();

        try {
            $productModel = new Product($this->db);

            // Pessimistic lock on product record
            $product = $productModel->findByIdForUpdate($productId);

            if (!$product) {
                throw new RuntimeException("Product #{$productId} not found.");
            }

            if ($product['status'] !== 'active') {
                throw new RuntimeException("Product '{$product['name']}' is currently inactive.");
            }

            if ((int)$product['stock'] < $quantity) {
                throw new RuntimeException("Insufficient stock for '{$product['name']}'. Requested: {$quantity}, Available: {$product['stock']}.");
            }

            // Snapshot the unit price at order time
            $unitPrice = (float)$product['price'];
            $totalAmount = round($unitPrice * $quantity, 2);

            // Decrement product inventory atomically.
            // For 'pending_payment' orders (online checkout) stock is NOT
            // reserved yet — it is decremented inside confirmPayment() once
            // the payment webhook confirms. This prevents tying up stock for
            // unpaid or abandoned online orders.
            if ($status !== 'pending_payment') {
                $decremented = $productModel->decrementStock($productId, $quantity);
                if (!$decremented) {
                    throw new RuntimeException("Failed to reserve stock for product #{$productId}.");
                }
            }

            // Insert new order record
            $stmt = $this->db->prepare("
                INSERT INTO orders (
                    customer_id, product_id, rider_id, quantity, unit_price, total_amount,
                    payment_method, payment_reference, status, delivery_address, delivery_latitude, delivery_longitude,
                    contact_phone, notes, created_at, updated_at
                ) VALUES (
                    ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
                )
            ");

            $stmt->execute([
                $customerId,
                $productId,
                $quantity,
                $unitPrice,
                $totalAmount,
                $paymentMethod,
                $paymentReference,
                $status,
                $deliveryAddress,
                $deliveryLatitude,
                $deliveryLongitude,
                $contactPhone,
                $notes
            ]);

            $orderId = (int)$this->db->lastInsertId();

            $this->db->commit();
            return $orderId;

        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Resolve delivery coordinates for an order: prefer client-supplied pin,
     * fall back to server-side Nominatim geocoding, else return [null, null].
     * Never throws — checkout must not fail because of a geocoder outage.
     *
     * @param string $address
     * @param mixed $clientLat
     * @param mixed $clientLng
     * @return array{0: ?float, 1: ?float} [latitude, longitude]
     */
    private function resolveDeliveryCoordinates(string $address, $clientLat, $clientLng): array {
        $lat = is_numeric($clientLat) && $clientLat !== '' ? (float)$clientLat : null;
        $lng = is_numeric($clientLng) && $clientLng !== '' ? (float)$clientLng : null;

        $latValid = ($lat !== null && $lat >= -90 && $lat <= 90);
        $lngValid = ($lng !== null && $lng >= -180 && $lng <= 180);

        if ($latValid && $lngValid) {
            return [$lat, $lng];
        }

        $geocoded = $this->geocodeAddress($address);
        if ($geocoded !== null) {
            return [$geocoded['lat'], $geocoded['lng']];
        }

        return [null, null];
    }

    /**
     * Geocode a free-text address via the Nominatim public API.
     *
     * @param string $address
     * @return array{lat: float, lng: float}|null
     */
    private function geocodeAddress(string $address): ?array {
        if ($address === '') {
            return null;
        }

        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
            'q' => $address,
            'format' => 'json',
            'limit' => 1,
        ]);

        $raw = $this->fetchUrl($url);
        if ($raw === null) {
            return null;
        }

        $results = json_decode($raw, true);
        if (!is_array($results) || empty($results)) {
            return null;
        }

        $lat = isset($results[0]['lat']) ? (float)$results[0]['lat'] : null;
        $lng = isset($results[0]['lon']) ? (float)$results[0]['lon'] : null;

        if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return null;
        }

        return ['lat' => $lat, 'lng' => $lng];
    }

    /**
     * Fetch a remote URL with a short timeout via cURL, falling back to file_get_contents.
     *
     * @param string $url
     * @return string|null
     */
    private function fetchUrl(string $url): ?string {
        try {
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 7,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_USERAGENT => 'LPGDeliverySystem/2.0 (order geocoding)',
                    CURLOPT_HTTPHEADER => ['Accept: application/json'],
                ]);
                $caBundle = ini_get('curl.cainfo') ?: ini_get('openssl.cafile');
                if ($caBundle && is_file($caBundle)) {
                    curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
                }
                $body = curl_exec($ch);
                $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_close($ch);
                if (is_string($body) && $status === 200) {
                    return $body;
                }
                return null;
            }

            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => "User-Agent: LPGDeliverySystem/2.0 (order geocoding)\r\nAccept: application/json\r\n",
                    'timeout' => 4,
                ]
            ]);
            $body = @file_get_contents($url, false, $context);
            return is_string($body) && $body !== '' ? $body : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Find an order by its ID with full joined customer, product, and rider details
     *
     * @param int $id
     * @return array|null
     */
    public function findById(int $id): ?array {
        $stmt = $this->db->prepare("
            SELECT o.*,
                   c.full_name AS customer_name, c.email AS customer_email, c.phone AS customer_registered_phone,
                   r.full_name AS rider_name, r.phone AS rider_phone,
                   p.name AS product_name, p.brand AS product_brand, p.weight AS product_weight, p.image_url AS product_image
            FROM orders o
            JOIN users c ON o.customer_id = c.id
            JOIN products p ON o.product_id = p.id
            LEFT JOIN users r ON o.rider_id = r.id
            WHERE o.id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $order = $stmt->fetch();
        return $order ?: null;
    }

    /**
     * Find an order by its PayMongo payment reference.
     *
     * @param string $paymentReference
     * @return array|null
     */
    public function findByPaymentReference(string $paymentReference): ?array {
        $stmt = $this->db->prepare("
            SELECT o.*
            FROM orders o
            WHERE o.payment_reference = ?
            LIMIT 1
        ");
        $stmt->execute([$paymentReference]);
        $order = $stmt->fetch();
        return $order ?: null;
    }

    /**
     * Store the PayMongo reference on an order.
     *
     * @param int $orderId
     * @param string $paymentReference
     * @return bool
     */
    public function setPaymentReference(int $orderId, string $paymentReference): bool {
        $stmt = $this->db->prepare("
            UPDATE orders SET payment_reference = ?, updated_at = NOW() WHERE id = ?
        ");
        return $stmt->execute([$paymentReference, $orderId]);
    }

    /**
     * Confirm payment for a pending_payment order (called by the payment
     * webhook). Atomically reserves stock and transitions the order to
     * 'pending' so it enters the normal admin approval queue.
     *
     * Idempotent: if the order has already been confirmed, this is a no-op
     * and returns true.
     *
     * @param int $orderId
     * @param string|null $paymentId PayMongo payment id (pay_...) to store for refunds
     * @return bool
     * @throws Throwable
     */
    public function confirmPayment(int $orderId, ?string $paymentId = null): bool {
        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare("
                SELECT id, product_id, quantity, status, payment_status FROM orders WHERE id = ? FOR UPDATE
            ");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();

            if (!$order) {
                throw new InvalidArgumentException("Order #{$orderId} not found.");
            }

            // Already confirmed (e.g. duplicate webhook delivery) — no-op.
            if ($order['payment_status'] === 'paid' && $order['status'] !== 'pending_payment') {
                $this->db->commit();
                return true;
            }

            if ($order['status'] !== 'pending_payment') {
                throw new RuntimeException("Order #{$orderId} is not awaiting payment.");
            }

            // Reserve stock now that payment is confirmed.
            $productModel = new Product($this->db);

            // Pessimistic lock on product row to prevent overselling.
            $product = $productModel->findByIdForUpdate((int)$order['product_id']);
            if (!$product) {
                throw new RuntimeException("Product #{$order['product_id']} not found.");
            }
            if ($product['status'] !== 'active') {
                throw new RuntimeException("Product '{$product['name']}' is no longer active.");
            }
            if ((int)$product['stock'] < (int)$order['quantity']) {
                throw new RuntimeException("Insufficient stock for '{$product['name']}'. Payment will be refunded separately.");
            }

            $decremented = $productModel->decrementStock((int)$order['product_id'], (int)$order['quantity']);
            if (!$decremented) {
                throw new RuntimeException("Failed to reserve stock for product #{$order['product_id']}.");
            }

            // Mark paid and move to normal pending queue.
            $upd = $this->db->prepare("
                UPDATE orders
                SET status = 'pending', payment_status = 'paid', paid_at = NOW(),
                    payment_id = COALESCE(?, payment_id), updated_at = NOW()
                WHERE id = ?
            ");
            $upd->execute([$paymentId !== null && $paymentId !== '' ? $paymentId : null, $orderId]);

            $this->db->commit();
            return true;

        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Retrieve all orders for a specific customer
     *
     * @param int $customerId
     * @return array
     */
    public function getByCustomer(int $customerId): array {
        $stmt = $this->db->prepare("
            SELECT o.*,
                   p.name AS product_name, p.brand AS product_brand, p.weight AS product_weight, p.image_url AS product_image,
                   r.full_name AS rider_name, r.phone AS rider_phone
            FROM orders o
            JOIN products p ON o.product_id = p.id
            LEFT JOIN users r ON o.rider_id = r.id
            WHERE o.customer_id = ?
            ORDER BY o.created_at DESC, o.id DESC
        ");
        $stmt->execute([$customerId]);
        return $stmt->fetchAll();
    }

    /**
     * Retrieve all orders assigned to a rider, optionally filtered by status
     *
     * @param int $riderId
     * @param string|null $status
     * @return array
     */
    public function getByRider(int $riderId, ?string $status = null): array {
        if ($status !== null) {
            $stmt = $this->db->prepare("
                SELECT o.*,
                       c.full_name AS customer_name, c.phone AS customer_phone,
                       p.name AS product_name, p.brand AS product_brand, p.weight AS product_weight, p.image_url AS product_image
                FROM orders o
                JOIN users c ON o.customer_id = c.id
                JOIN products p ON o.product_id = p.id
                WHERE o.rider_id = ? AND o.status = ?
                ORDER BY o.created_at DESC, o.id DESC
            ");
            $stmt->execute([$riderId, $status]);
        } else {
            $stmt = $this->db->prepare("
                SELECT o.*,
                       c.full_name AS customer_name, c.phone AS customer_phone,
                       p.name AS product_name, p.brand AS product_brand, p.weight AS product_weight, p.image_url AS product_image
                FROM orders o
                JOIN users c ON o.customer_id = c.id
                JOIN products p ON o.product_id = p.id
                WHERE o.rider_id = ?
                ORDER BY o.created_at DESC, o.id DESC
            ");
            $stmt->execute([$riderId]);
        }
        return $stmt->fetchAll();
    }

    /**
     * Retrieve unassigned orders that are ready for claiming by riders (status 'approved' or 'ready_for_delivery')
     *
     * @return array
     */
    public function getAvailableForRider(): array {
        $stmt = $this->db->query("
            SELECT o.*,
                   c.full_name AS customer_name, c.phone AS customer_phone,
                   p.name AS product_name, p.brand AS product_brand, p.weight AS product_weight, p.image_url AS product_image
            FROM orders o
            JOIN users c ON o.customer_id = c.id
            JOIN products p ON o.product_id = p.id
            WHERE o.rider_id IS NULL AND o.status IN ('approved', 'ready_for_delivery')
            ORDER BY o.created_at ASC, o.id ASC
        ");
        return $stmt->fetchAll();
    }

    /**
     * Retrieve all orders for admin view, optionally filtered by status
     *
     * @param string|null $status
     * @return array
     */
    public function getAll(?string $status = null): array {
        if ($status !== null) {
            $stmt = $this->db->prepare("
                SELECT o.*,
                       c.full_name AS customer_name, c.email AS customer_email,
                       r.full_name AS rider_name,
                       p.name AS product_name, p.brand AS product_brand, p.weight AS product_weight, p.image_url AS product_image
                FROM orders o
                JOIN users c ON o.customer_id = c.id
                JOIN products p ON o.product_id = p.id
                LEFT JOIN users r ON o.rider_id = r.id
                WHERE o.status = ?
                ORDER BY o.created_at DESC, o.id DESC
            ");
            $stmt->execute([$status]);
        } else {
            $stmt = $this->db->query("
                SELECT o.*,
                       c.full_name AS customer_name, c.email AS customer_email,
                       r.full_name AS rider_name,
                       p.name AS product_name, p.brand AS product_brand, p.weight AS product_weight, p.image_url AS product_image
                FROM orders o
                JOIN users c ON o.customer_id = c.id
                JOIN products p ON o.product_id = p.id
                LEFT JOIN users r ON o.rider_id = r.id
                ORDER BY o.created_at DESC, o.id DESC
            ");
        }
        return $stmt->fetchAll();
    }

    /**
     * Update order status enforcing valid state machine transitions
     *
     * @param int $orderId
     * @param string $newStatus
     * @return bool
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function updateStatus(int $orderId, string $newStatus): bool {
        $stmt = $this->db->prepare("SELECT id, status FROM orders WHERE id = ? LIMIT 1");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        if (!$order) {
            throw new InvalidArgumentException("Order #{$orderId} not found.");
        }

        $currentStatus = $order['status'];

        if (!self::canTransition($currentStatus, $newStatus)) {
            throw new InvalidArgumentException(
                "Invalid status transition from '{$currentStatus}' to '{$newStatus}' for order #{$orderId}."
            );
        }

        if ($newStatus === 'delivered') {
            $updateStmt = $this->db->prepare("
                UPDATE orders
                SET status = ?, delivered_at = NOW(), updated_at = NOW()
                WHERE id = ?
            ");
            return $updateStmt->execute([$newStatus, $orderId]);
        }

        $updateStmt = $this->db->prepare("
            UPDATE orders
            SET status = ?, updated_at = NOW()
            WHERE id = ?
        ");
        return $updateStmt->execute([$newStatus, $orderId]);
    }

    /**
     * Update customer-facing details of an order (walk-in editing support).
     * Updates contact_phone and delivery_address on the order, refreshes
     * drop-off coordinates via geocode fallback, and syncs the customer's
     * full_name on their user profile.
     *
     * @param int $orderId
     * @param string $customerName
     * @param string $contactPhone
     * @param string $deliveryAddress
     * @return bool
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    public function updateCustomerInfo(int $orderId, string $customerName, string $contactPhone, string $deliveryAddress): bool {
        $customerName = trim($customerName);
        $contactPhone = trim($contactPhone);
        $deliveryAddress = trim($deliveryAddress);

        if ($customerName === '' || $contactPhone === '' || $deliveryAddress === '') {
            throw new InvalidArgumentException("Customer name, contact phone, and delivery address are required.");
        }

        $stmt = $this->db->prepare("SELECT id, customer_id FROM orders WHERE id = ? LIMIT 1");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        if (!$order) {
            throw new InvalidArgumentException("Order #{$orderId} not found.");
        }

        // Refresh drop-off coordinates for the new address (never throws)
        [$deliveryLatitude, $deliveryLongitude] = $this->resolveDeliveryCoordinates($deliveryAddress, null, null);

        $this->db->beginTransaction();

        try {
            $updOrder = $this->db->prepare("
                UPDATE orders
                SET contact_phone = ?, delivery_address = ?,
                    delivery_latitude = ?, delivery_longitude = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $updOrder->execute([$contactPhone, $deliveryAddress, $deliveryLatitude, $deliveryLongitude, $orderId]);

            $updUser = $this->db->prepare("UPDATE users SET full_name = ? WHERE id = ?");
            $updUser->execute([$customerName, (int)$order['customer_id']]);

            $this->db->commit();
            return true;

        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Concurrency-safe rider assignment / order claim
     * Only succeeds if the order is currently unassigned (rider_id IS NULL)
     * and in an assignable status ('approved' or 'ready_for_delivery').
     *
     * @param int $orderId
     * @param int $riderId
     * @param string $newStatus Defaults to 'picked_up'
     * @return bool True if successfully assigned, false if already claimed or invalid state
     */
    public function assignRider(int $orderId, int $riderId, string $newStatus = 'picked_up'): bool {
        $stmt = $this->db->prepare("
            UPDATE orders
            SET rider_id = ?, status = ?, updated_at = NOW()
            WHERE id = ?
              AND rider_id IS NULL
              AND status IN ('approved', 'ready_for_delivery')
        ");
        $stmt->execute([$riderId, $newStatus, $orderId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Cancel an order and restore product inventory in a transaction
     *
     * @param int $orderId
     * @param string|null $reason
     * @return bool
     * @throws InvalidArgumentException
     * @throws RuntimeException
     * @throws Throwable
     */
    public function cancel(int $orderId, ?string $reason = null): bool {
        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare("SELECT id, product_id, quantity, status FROM orders WHERE id = ? FOR UPDATE");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();

            if (!$order) {
                throw new InvalidArgumentException("Order #{$orderId} not found.");
            }

            if (!self::canTransition($order['status'], 'cancelled')) {
                throw new InvalidArgumentException("Order #{$orderId} in status '{$order['status']}' cannot be cancelled.");
            }

            // Restore product stock ONLY IF it was actually reserved.
            // pending_payment orders (online checkout) never decremented stock,
            // so cancelling them must not add stock back.
            if ($order['status'] !== 'pending_payment') {
                $productModel = new Product($this->db);
                $productModel->incrementStock((int)$order['product_id'], (int)$order['quantity']);
            }

            // Update order status
            $cleanReason = $reason ? trim($reason) : '';
            $notesAppend = $cleanReason !== '' ? "\n[Cancelled: " . $cleanReason . "]" : "";
            $stmt = $this->db->prepare("
                UPDATE orders
                SET status = 'cancelled',
                    cancel_reason = ?,
                    notes = CONCAT(COALESCE(notes, ''), ?),
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$cleanReason !== '' ? $cleanReason : null, $notesAppend, $orderId]);

            $this->db->commit();
            return true;

        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Customer requests a refund for a paid online order.
     *
     * Only allowed when the order was paid online (payment_status = 'paid')
     * and there is either no request yet, a rejected one, or a failed one.
     *
     * @param int $orderId
     * @param string $reason
     * @return bool
     * @throws Throwable
     */
    public function requestRefund(int $orderId, string $reason = ''): bool {
        $stmt = $this->db->prepare("SELECT id, payment_method, payment_status, refund_status, status FROM orders WHERE id = ? LIMIT 1");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        if (!$order) {
            throw new InvalidArgumentException("Order #{$orderId} not found.");
        }
        if (strtolower((string)$order['payment_method']) !== 'gcash') {
            throw new RuntimeException("Only online (GCash) orders can be refunded.");
        }
        if ($order['payment_status'] !== 'paid') {
            throw new RuntimeException("Only paid orders can be refunded.");
        }
        if ($order['refund_status'] === 'requested' || $order['refund_status'] === 'refunded') {
            throw new RuntimeException("A refund is already pending or completed for this order.");
        }
        if ($order['status'] === 'cancelled') {
            throw new RuntimeException("This order is already cancelled.");
        }

        $upd = $this->db->prepare("
            UPDATE orders
            SET refund_status = 'requested',
                refund_reason = ?,
                refund_requested_at = NOW(),
                refund_processed_at = NULL,
                updated_at = NOW()
            WHERE id = ?
        ");
        return $upd->execute([trim($reason), $orderId]);
    }

    /**
     * Admin approves a refund request: issues an automatic PayMongo refund,
     * then cancels the order and restores stock.
     *
     * The PayMongo refund call runs BEFORE the DB transaction so we never
     * hold a row lock across an external HTTP call. The final DB update is
     * guarded by re-checking refund_status = 'requested' under FOR UPDATE so
     * two concurrent approvals cannot refund the same payment twice.
     *
     * @param int $orderId
     * @return array{refunded:bool,refund_id?:string,error?:string}
     */
    public function approveRefund(int $orderId): array {
        $read = $this->db->prepare("SELECT id, payment_method, payment_status, refund_status, payment_id, total_amount, product_id, quantity, status FROM orders WHERE id = ? LIMIT 1");
        $read->execute([$orderId]);
        $order = $read->fetch();

        if (!$order) {
            return ['refunded' => false, 'error' => "Order #{$orderId} not found."];
        }
        if ($order['refund_status'] !== 'requested') {
            return ['refunded' => false, 'error' => "Order #{$orderId} has no pending refund request."];
        }
        if ($order['payment_status'] !== 'paid' || empty($order['payment_id'])) {
            return ['refunded' => false, 'error' => "Order #{$orderId} has no captured payment to refund."];
        }

        $paymongo = new PayMongo();
        $amountCents = (int)round(((float)$order['total_amount']) * 100);

        try {
            $refund = $paymongo->createRefund(
                (string)$order['payment_id'],
                $amountCents,
                'others',
                'Refund for cancelled order #' . $orderId
            );
        } catch (Throwable $e) {
            // Record the failure but don't cancel the order.
            $this->markRefundFailed($orderId, $e->getMessage());
            return ['refunded' => false, 'error' => $e->getMessage()];
        }

        $refundId = (string)($refund['id'] ?? '');

        $this->db->beginTransaction();
        try {
            $lock = $this->db->prepare("SELECT refund_status FROM orders WHERE id = ? FOR UPDATE");
            $lock->execute([$orderId]);
            $locked = $lock->fetch();

            if (!$locked || $locked['refund_status'] !== 'requested') {
                throw new RuntimeException("Refund for order #{$orderId} was already processed by another request.");
            }

            // Stock was reserved when payment confirmed, so restore it now.
            $productModel = new Product($this->db);
            $productModel->incrementStock((int)$order['product_id'], (int)$order['quantity']);

            $upd = $this->db->prepare("
                UPDATE orders
                SET status = 'cancelled',
                    refund_status = 'refunded',
                    refund_reference = ?,
                    refund_processed_at = NOW(),
                    notes = CONCAT(COALESCE(notes, ''), '\n[Refunded: ', ? , ']'),
                    updated_at = NOW()
                WHERE id = ?
            ");
            $upd->execute([$refundId, trim((string)($order['refund_reason'] ?? 'Order cancelled, refunded')), $orderId]);

            $this->db->commit();
            return ['refunded' => true, 'refund_id' => $refundId];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['refunded' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Admin rejects a pending refund request.
     *
     * @param int $orderId
     * @param string $reason
     * @return bool
     */
    public function rejectRefund(int $orderId, string $reason = ''): bool {
        $stmt = $this->db->prepare("SELECT refund_status FROM orders WHERE id = ? LIMIT 1");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        if (!$order || $order['refund_status'] !== 'requested') {
            throw new RuntimeException("Order #{$orderId} has no pending refund request to reject.");
        }

        $upd = $this->db->prepare("
            UPDATE orders
            SET refund_status = 'rejected',
                refund_processed_at = NOW(),
                notes = CONCAT(COALESCE(notes, ''), '\n[Refund rejected: ', ?, ']'),
                updated_at = NOW()
            WHERE id = ?
        ");
        return $upd->execute([trim($reason) !== '' ? trim($reason) : 'Declined by administrator', $orderId]);
    }

    /**
     * Mark a refund request as failed (approval ran but PayMongo rejected it).
     *
     * @param int $orderId
     * @param string $error
     * @return void
     */
    private function markRefundFailed(int $orderId, string $error): void {
        $upd = $this->db->prepare("
            UPDATE orders
            SET refund_status = 'failed',
                refund_processed_at = NOW(),
                notes = CONCAT(COALESCE(notes, ''), '\n[Refund failed: ', ?, ']'),
                updated_at = NOW()
            WHERE id = ? AND refund_status = 'requested'
        ");
        $upd->execute([$error, $orderId]);
    }

    /**
     * Retrieve aggregated statistics for the admin dashboard
     *
     * @return array
     */
    public function getDashboardStats(): array {
        // Summary counts and revenue
        $statsStmt = $this->db->query("
            SELECT
                COUNT(*) AS total_orders,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_orders,
                SUM(CASE WHEN status IN ('approved', 'ready_for_delivery', 'picked_up', 'out_for_delivery') THEN 1 ELSE 0 END) AS in_transit_orders,
                SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) AS delivered_orders,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_orders,
                COALESCE(SUM(CASE WHEN status = 'delivered' THEN total_amount ELSE 0 END), 0) AS total_revenue
            FROM orders
        ");
        $stats = $statsStmt->fetch() ?: [];

        // Recent orders (top 10)
        $recentStmt = $this->db->query("
            SELECT o.id, o.quantity, o.unit_price, o.total_amount, o.payment_method, o.status, o.created_at,
                   c.full_name AS customer_name,
                   p.name AS product_name,
                   r.full_name AS rider_name
            FROM orders o
            JOIN users c ON o.customer_id = c.id
            JOIN products p ON o.product_id = p.id
            LEFT JOIN users r ON o.rider_id = r.id
            ORDER BY o.created_at DESC, o.id DESC
            LIMIT 10
        ");
        $recentOrders = $recentStmt->fetchAll();

        return [
            'total_orders'      => (int)($stats['total_orders'] ?? 0),
            'pending_orders'    => (int)($stats['pending_orders'] ?? 0),
            'in_transit_orders' => (int)($stats['in_transit_orders'] ?? 0),
            'delivered_orders'  => (int)($stats['delivered_orders'] ?? 0),
            'cancelled_orders'  => (int)($stats['cancelled_orders'] ?? 0),
            'total_revenue'     => (float)($stats['total_revenue'] ?? 0.0),
            'recent_orders'     => $recentOrders,
        ];
    }
}
