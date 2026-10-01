<?php
/**
 * OrderNotifier Class
 * LPG Delivery System v2
 *
 * Single source of truth for every notification raised by an order
 * transaction. Routing lives here rather than inside Order so that:
 *
 *   1. Each event is defined exactly once, so an event reachable through two
 *      code paths (e.g. admin UI fallback vs. model method) cannot drift.
 *   2. Recipient resolution is uniform: the customer, the assigned rider, and
 *      every admin, each deep-linked to the page that role actually uses.
 *   3. Fan-out is idempotent. Every type below describes exactly one state of
 *      one order, so the (user, type, order) triple is a natural key and
 *      Notification::createOnce() guarantees a recipient is told once even if
 *      the same transaction runs twice.
 *
 * Notification failures are deliberately swallowed. A notification is an
 * advisory side channel and must never roll back or fail the business
 * transaction that triggered it.
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Notification.php';

class OrderNotifier {
    /**
     * @var PDO
     */
    private PDO $db;

    /**
     * @var Notification
     */
    private Notification $notifications;

    /**
     * Notification type emitted for each order status.
     *
     * `pending_payment` and `pending` are intentionally absent: the initial
     * placement is covered by order_placed / receipt_ready, and a GCash order
     * moving pending_payment -> pending is covered by order_paid. Mapping them
     * again would tell the customer the same thing twice.
     *
     * @var array<string,string>
     */
    public const STATUS_TYPES = [
        'approved'           => 'order_approved',
        'ready_for_delivery' => 'order_ready_for_delivery',
        'picked_up'          => 'order_picked_up',
        'out_for_delivery'   => 'order_out_for_delivery',
        'delivered'          => 'order_delivered',
        'cancelled'          => 'order_cancelled',
    ];

    /**
     * Per-status copy. Each entry holds the rider-facing and customer-facing
     * text; the admin-facing text is derived from the customer text because
     * staff only need the bare facts.
     *
     * @var array<string,array{customer:string,rider:string,title:string}>
     */
    public const STATUS_COPY = [
        'approved' => [
            'title'    => 'Order Approved',
            'customer' => "Good news! Order #%d has been approved and is being prepared.",
            'rider'    => "Order #%d has been approved and is being prepared for pickup.",
        ],
        'ready_for_delivery' => [
            'title'    => 'Rider Arrived',
            'customer' => "Your rider has arrived at our station to collect Order #%d.",
            'rider'    => "Order #%d is ready. Confirm pickup to begin the delivery.",
        ],
        'picked_up' => [
            'title'    => 'Order Picked Up',
            'customer' => "Order #%d has been picked up and is on its way to you.",
            'rider'    => "Pickup of Order #%d is confirmed. Safe travels!",
        ],
        'out_for_delivery' => [
            'title'    => 'Out for Delivery',
            'customer' => "Order #%d is out for delivery. Please prepare your exact cash amount.",
            'rider'    => "Order #%d is out for delivery. Head to the customer's address.",
        ],
        'delivered' => [
            'title'    => 'Order Delivered',
            'customer' => "Your Order #%d has been delivered. Thank you for choosing LPG Delivery System!",
            'rider'    => "Delivery of Order #%d is complete. Thank you!",
        ],
        'cancelled' => [
            'title'    => 'Order Cancelled',
            'customer' => "Order #%d has been cancelled.",
            'rider'    => "Order #%d has been cancelled and is no longer assigned to you.",
        ],
    ];

    /**
     * Deep-link target for each role.
     *
     * @var array<string,string>
     */
    private const LINKS = [
        'customer' => 'pages/customer/order-detail.php?id=',
        'rider'    => 'pages/rider/order-detail.php?id=',
        'admin'    => 'pages/admin/order-detail.php?id=',
    ];

    /**
     * @param PDO|null $db
     */
    public function __construct(?PDO $db = null) {
        $this->db = $db ?? Database::connect();
        $this->notifications = new Notification($this->db);
    }

    // ---------------------------------------------------------------------
    // Public events
    // ---------------------------------------------------------------------

    /**
     * A customer placed a new order.
     *
     * The customer is thanked and pointed at their receipt; every admin is
     * alerted so a new order cannot sit unnoticed on the dashboard. Admins are
     * de-duplicated per order, so a retried checkout still raises one alert.
     *
     * @param int  $orderId
     * @param bool $notifyAdmins False for orders an admin created themselves
     *                           (walk-ins), where self-alerting is noise.
     * @return void
     */
    public function notifyOrderPlaced(int $orderId, bool $notifyAdmins = true): void {
        $this->guard(function () use ($orderId, $notifyAdmins) {
            $order = $this->loadOrder($orderId);
            if ($order === null) {
                return;
            }

            $customerId = (int)$order['customer_id'];

            $this->createOnce(
                $customerId,
                'order_placed',
                'Order Placed',
                "Order #{$orderId} has been placed successfully. We will process your delivery shortly.",
                $orderId,
                self::LINKS['customer'] . $orderId
            );

            if (!$notifyAdmins) {
                return;
            }

            $this->notifyAdmins(
                'order_placed',
                'New Order Placed',
                sprintf(
                    "Order #%d (%s) was placed by %s and is awaiting your action.",
                    $orderId,
                    strtoupper((string)($order['payment_method'] ?? 'cod')),
                    (string)($order['customer_name'] ?: 'a customer')
                ),
                $orderId
            );
        });
    }

    /**
     * The customer's official 80mm receipt is ready to view or download.
     *
     * @param int $orderId
     * @return void
     */
    public function notifyReceiptReady(int $orderId): void {
        $this->guard(function () use ($orderId) {
            $order = $this->loadOrder($orderId);
            if ($order === null) {
                return;
            }

            $this->createOnce(
                (int)$order['customer_id'],
                'receipt_ready',
                'Your Receipt Is Ready',
                sprintf(
                    'The official receipt for Order #%d is ready to view or download.',
                    $orderId
                ),
                $orderId,
                'pages/customer/receipt.php?id=' . $orderId
            );
        });
    }

    /**
     * An online payment was captured (GCash / PayMongo).
     *
     * @param int $orderId
     * @return void
     */
    public function notifyPaymentConfirmed(int $orderId): void {
        $this->guard(function () use ($orderId) {
            $order = $this->loadOrder($orderId);
            if ($order === null) {
                return;
            }

            $this->createOnce(
                (int)$order['customer_id'],
                'order_paid',
                'Payment Received',
                sprintf(
                    'We have received your payment for Order #%d. Your paid receipt is now available.',
                    $orderId
                ),
                $orderId,
                'pages/customer/receipt.php?id=' . $orderId
            );

            $this->notifyAdmins(
                'order_paid',
                'Payment Received',
                sprintf('Payment for Order #%d has been captured.', $orderId),
                $orderId
            );
        });
    }

    /**
     * An administrator assigned the order to a rider.
     *
     * Rider self-claims pass $actorId equal to $riderId and deliberately skip
     * this: the rider already knows they claimed it.
     *
     * @param int      $orderId
     * @param int      $riderId
     * @param int|null $actorId ID of the user performing the assignment
     * @return void
     */
    public function notifyRiderAssigned(int $orderId, int $riderId, ?int $actorId = null): void {
        if ($actorId !== null && $actorId === $riderId) {
            return;
        }

        $this->guard(function () use ($orderId, $riderId) {
            $this->createOnce(
                $riderId,
                'order_assigned',
                'New Order Assignment',
                "A new order has been assigned to you. Please check Order #{$orderId} in your deliveries.",
                $orderId,
                self::LINKS['rider'] . $orderId
            );
        });
    }

    /**
     * An order was taken off a rider's list by an administrator.
     *
     * @param int $orderId
     * @param int $riderId The rider who is losing the order
     * @return void
     */
    public function notifyReassignmentRevoked(int $orderId, int $riderId): void {
        $this->guard(function () use ($orderId, $riderId) {
            $this->createOnce(
                $riderId,
                'order_unassigned',
                'Order Reassigned',
                "Order #{$orderId} is no longer assigned to you.",
                $orderId,
                self::LINKS['rider'] . $orderId
            );
        });
    }

    /**
     * An order moved to a new status: tell the customer, the rider, and admins.
     *
     * The rider is only notified once assigned, so an approval does not ping
     * every rider in the system. The user who performed the transition is
     * skipped entirely - nobody needs a toast telling them what they just did.
     *
     * @param int      $orderId
     * @param string   $newStatus
     * @param int|null $actorId ID of the user who caused the transition
     * @return void
     */
    public function notifyStatusChange(int $orderId, string $newStatus, ?int $actorId = null): void {
        $type = self::STATUS_TYPES[$newStatus] ?? null;
        if ($type === null) {
            return;
        }

        $this->guard(function () use ($orderId, $newStatus, $type, $actorId) {
            $order = $this->loadOrder($orderId);
            if ($order === null) {
                return;
            }

            $copy = self::STATUS_COPY[$newStatus];

            $this->createOnce(
                (int)$order['customer_id'],
                $type,
                $copy['title'],
                sprintf($copy['customer'], $orderId),
                $orderId,
                self::LINKS['customer'] . $orderId,
                $actorId === (int)$order['customer_id'] ? $actorId : null
            );

            $riderId = (int)($order['rider_id'] ?? 0);
            if ($riderId > 0) {
                $this->createOnce(
                    $riderId,
                    $type,
                    $copy['title'],
                    sprintf($copy['rider'], $orderId),
                    $orderId,
                    self::LINKS['rider'] . $orderId,
                    $actorId === $riderId ? $actorId : null
                );
            }

            $this->notifyAdmins(
                $type,
                $copy['title'],
                sprintf('Order #%d is now %s.', $orderId, self::humanizeStatus($newStatus)),
                $orderId,
                $actorId
            );
        });
    }

    /**
     * An order was cancelled, optionally with a reason.
     *
     * @param int         $orderId
     * @param string|null $reason
     * @param int|null    $actorId
     * @return void
     */
    public function notifyOrderCancelled(int $orderId, ?string $reason = null, ?int $actorId = null): void {
        $this->guard(function () use ($orderId, $reason, $actorId) {
            $order = $this->loadOrder($orderId);
            if ($order === null) {
                return;
            }

            $suffix = '';
            if ($reason !== null && trim($reason) !== '') {
                $suffix = ' Reason: ' . trim($reason);
            }

            $this->createOnce(
                (int)$order['customer_id'],
                'order_cancelled',
                'Order Cancelled',
                "Order #{$orderId} has been cancelled.{$suffix}",
                $orderId,
                self::LINKS['customer'] . $orderId,
                $actorId === (int)$order['customer_id'] ? $actorId : null
            );

            $riderId = (int)($order['rider_id'] ?? 0);
            if ($riderId > 0) {
                $this->createOnce(
                    $riderId,
                    'order_cancelled',
                    'Order Cancelled',
                    "Order #{$orderId} has been cancelled and is no longer assigned to you.{$suffix}",
                    $orderId,
                    self::LINKS['rider'] . $orderId,
                    $actorId === $riderId ? $actorId : null
                );
            }

            $this->notifyAdmins(
                'order_cancelled',
                'Order Cancelled',
                sprintf('Order #%d has been cancelled.%s', $orderId, $suffix),
                $orderId,
                $actorId
            );
        });
    }

    /**
     * The customer asked for a refund: alert every admin for review.
     *
     * @param int    $orderId
     * @param string $reason
     * @return void
     */
    public function notifyRefundRequested(int $orderId, string $reason = ''): void {
        $this->guard(function () use ($orderId, $reason) {
            $this->notifyAdmins(
                'refund_requested',
                'Refund Requested',
                sprintf(
                    'A refund was requested for Order #%d.%s',
                    $orderId,
                    trim($reason) !== '' ? ' Reason: ' . trim($reason) : ''
                ),
                $orderId
            );
        });
    }

    /**
     * An administrator issued a refund.
     *
     * @param int $orderId
     * @return void
     */
    public function notifyRefundCompleted(int $orderId): void {
        $this->guard(function () use ($orderId) {
            $order = $this->loadOrder($orderId);
            if ($order === null) {
                return;
            }

            $this->createOnce(
                (int)$order['customer_id'],
                'refund_refunded',
                'Refund Issued',
                sprintf(
                    'Your refund for Order #%d has been issued back to your original payment method.',
                    $orderId
                ),
                $orderId,
                self::LINKS['customer'] . $orderId
            );
        });
    }

    /**
     * An administrator declined a refund request.
     *
     * @param int    $orderId
     * @param string $reason
     * @return void
     */
    public function notifyRefundRejected(int $orderId, string $reason = ''): void {
        $this->guard(function () use ($orderId, $reason) {
            $order = $this->loadOrder($orderId);
            if ($order === null) {
                return;
            }

            $this->createOnce(
                (int)$order['customer_id'],
                'refund_rejected',
                'Refund Declined',
                sprintf(
                    'Your refund request for Order #%d was declined.%s',
                    $orderId,
                    trim($reason) !== '' ? ' Reason: ' . trim($reason) : ''
                ),
                $orderId,
                self::LINKS['customer'] . $orderId
            );
        });
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * Fan a message out to every active administrator.
     *
     * @param string   $type
     * @param string   $title
     * @param string   $message
     * @param int|null $orderId
     * @param int|null $skipUserId Admin who caused the event (no self-alert)
     * @return void
     */
    private function notifyAdmins(
        string $type,
        string $title,
        string $message,
        ?int $orderId = null,
        ?int $skipUserId = null
    ): void {
        $stmt = $this->db->prepare("
            SELECT id FROM users
            WHERE role = 'admin' AND status = 'active'
        ");
        $stmt->execute();

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
            $this->createOnce(
                (int)$adminId,
                $type,
                $title,
                $message,
                $orderId,
                $orderId !== null ? self::LINKS['admin'] . $orderId : null,
                $skipUserId
            );
        }
    }

    /**
     * Create a notification, swallowing any failure.
     *
     * @param int         $userId
     * @param string      $type
     * @param string      $title
     * @param string      $message
     * @param int|null    $orderId
     * @param string|null $link
     * @param int|null    $skipUserId Recipient to skip (the actor)
     * @return void
     */
    private function createOnce(
        int $userId,
        string $type,
        string $title,
        string $message,
        ?int $orderId = null,
        ?string $link = null,
        ?int $skipUserId = null
    ): void {
        if ($userId <= 0 || ($skipUserId !== null && $userId === $skipUserId)) {
            return;
        }

        $this->notifications->createOnce($userId, $type, $title, $message, $orderId, $link);
    }

    /**
     * Load the fields the notifier needs for an order.
     *
     * @param int $orderId
     * @return array|null
     */
    private function loadOrder(int $orderId): ?array {
        $stmt = $this->db->prepare("
            SELECT o.id, o.customer_id, o.rider_id, o.status, o.payment_method,
                   o.payment_status, o.refund_status, c.full_name AS customer_name
            FROM orders o
            LEFT JOIN users c ON c.id = o.customer_id
            WHERE o.id = ?
            LIMIT 1
        ");
        $stmt->execute([$orderId]);

        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Render a status key as readable prose for admin messages.
     *
     * @param string $status
     * @return string
     */
    private static function humanizeStatus(string $status): string {
        return ucfirst(str_replace('_', ' ', $status));
    }

    /**
     * Run a notification routine without ever letting it propagate.
     *
     * @param callable $callback
     * @return void
     */
    private function guard(callable $callback): void {
        try {
            $callback();
        } catch (Throwable $e) {
            error_log('Order notification failed: ' . $e->getMessage());
        }
    }
}
