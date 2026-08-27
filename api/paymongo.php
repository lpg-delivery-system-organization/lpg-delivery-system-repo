<?php
/**
 * PayMongo Webhook Endpoint
 * LPG Delivery System v2
 *
 * Receives payment webhooks from PayMongo and confirms orders.
 *
 * This endpoint is intentionally PUBLIC (no login/session) because PayMongo
 * calls it server-to-server. Security relies on:
 *   - signature verification (PayMongo-Signature) when a webhook secret is set
 *   - looking up orders only by the checkout session reference number we set
 *
 * Configure the webhook URL in the PayMongo dashboard to point here, e.g.
 *   http://localhost/lpg-delivery-system-repo/api/paymongo.php
 * and subscribe to the 'checkout_session.payment.paid' event.
 */

// Ensure JSON response header
if (!headers_sent()) {
    header('Content-Type: application/json; charset=UTF-8');
}

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Product.php';
require_once __DIR__ . '/../classes/Order.php';
require_once __DIR__ . '/../classes/PayMongo.php';

// Only accept POST webhooks from PayMongo.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['success' => false, 'error' => 'Method not allowed.'], 405);
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false || $rawBody === '') {
    json_response(['success' => false, 'error' => 'Empty request body.'], 400);
}

$sentinel = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? $_SERVER['HTTP_Webhook-Signature'] ?? '';

try {
    $paymongo = new PayMongo();
    $event = $paymongo->verifyWebhook($rawBody, $sentinel);

    $eventType = $event['attributes']['type'] ?? ($event['type'] ?? '');
    $resource  = $event['attributes']['data'] ?? $event['data'] ?? [];
    $eventId   = $event['id'] ?? 'unknown';
    $attributes = $resource['attributes'] ?? [];

    error_log("[PayMongo] Received webhook event '{$eventType}' ({$eventId}).");

    // We only act on paid checkout sessions. Everything else is acknowledged.
    if ($eventType === 'checkout_session.payment.paid') {
        $reference = (string)($attributes['reference_number'] ?? '');
        if (trim($reference) === '') {
            // Fallback: the session id is stored on the order as payment_reference.
            $reference = (string)($resource['id'] ?? '');
        }

        if (trim($reference) === '') {
            json_response(['success' => false, 'error' => 'Missing reference number.'], 400);
        }

        // reference_number is our order id. Prefer an exact match on the
        // stored payment_reference (session id) but support order-id lookup.
        $order = (new Order())->findByPaymentReference($reference);
        if (!$order && ctype_digit($reference)) {
            $order = (new Order())->findById((int)$reference);
        }

        if (!$order) {
            error_log("[PayMongo] No order found for payment reference '{$reference}'.");
            json_response(['success' => true, 'message' => 'No matching order (acknowledged).'], 200);
        }

        if ($order['payment_status'] === 'paid') {
            json_response(['success' => true, 'message' => 'Order already confirmed.'], 200);
        }

        $orderModel = new Order();
        $paymentId = '';
        foreach ((array)($attributes['payments'] ?? []) as $payment) {
            if (!empty($payment['id'])) {
                $paymentId = (string)$payment['id'];
                break;
            }
        }
        $orderModel->confirmPayment((int)$order['id'], $paymentId !== '' ? $paymentId : null);

        error_log("[PayMongo] Order #{$order['id']} confirmed as paid (payment_id={$paymentId}).");
        json_response(['success' => true, 'message' => 'Payment confirmed.'], 200);
    }

    // Any other event (including checkout_session.payment.failed) is ack'ed.
    json_response(['success' => true, 'received' => $eventType], 200);

} catch (Throwable $e) {
    error_log('[PayMongo] Webhook error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => $e->getMessage()], 400);
}
