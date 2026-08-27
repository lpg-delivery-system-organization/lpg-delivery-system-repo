<?php
/**
 * PayMongo Payment Client
 * LPG Delivery System v2
 *
 * Thin cURL wrapper around PayMongo's Hosted Checkout API.
 * All calls go to the backend with the secret key (never exposed to the
 * browser). Confirmation of payment is done via webhook, which this
 * client also verifies.
 *
 * Security note: the secret key must only ever be used server-side.
 */

class PayMongo {
    /** @var array{secret_key:string,public_key:string,base_url:string,payment_method_types:array,webhook_secret:string,enabled:bool} */
    private array $config;

    public function __construct(?array $config = null) {
        $this->config = $config ?? $this->loadConfig();
    }

    /**
     * Load PayMongo configuration from config/paymongo.php if present.
     *
     * @return array
     */
    private function loadConfig(): array {
        $file = __DIR__ . '/../config/paymongo.php';
        if (file_exists($file)) {
            return (array)require $file;
        }
        return [
            'secret_key'          => '',
            'public_key'          => '',
            'base_url'            => 'https://api.paymongo.com',
            'payment_method_types' => ['gcash', 'paymaya', 'card'],
            'webhook_secret'      => '',
            'enabled'             => false,
        ];
    }

    /**
     * Whether the gateway is configured and enabled.
     *
     * @return bool
     */
    public function isEnabled(): bool {
        return (bool)($this->config['enabled'] ?? false)
            && !empty($this->config['secret_key']);
    }

    /**
     * Create a Hosted Checkout Session and return its checkout URL.
     *
     * @param array $lineItems Each: name, amount(centavos int), currency, quantity
     * @param string $referenceNumber Merchant-order reference (our order id)
     * @param string $successUrl
     * @param string $cancelUrl
     * @param array $paymentMethodTypes
     * @param array $metadata key/value metadata
     * @return array{checkout_url:string,id:string}
     * @throws RuntimeException
     */
    public function createCheckoutSession(
        array $lineItems,
        string $referenceNumber,
        string $successUrl,
        string $cancelUrl,
        array $paymentMethodTypes = [],
        array $metadata = []
    ): array {
        if (!$this->isEnabled()) {
            throw new RuntimeException('PayMongo gateway is not configured.');
        }

        $payload = [
            'data' => [
                'attributes' => [
                    'line_items'              => $lineItems,
                    'payment_method_types'    => $paymentMethodTypes ?: ($this->config['payment_method_types'] ?? ['gcash']),
                    'success_url'             => $successUrl,
                    'cancel_url'              => $cancelUrl,
                    'reference_number'        => $referenceNumber,
                    'metadata'                => $metadata,
                ],
            ],
        ];

        $response = $this->request('POST', '/v1/checkout_sessions', $payload);
        $attributes = $response['data']['attributes'] ?? [];

        if (empty($attributes['checkout_url'])) {
            throw new RuntimeException('PayMongo did not return a checkout URL.');
        }

        return [
            'checkout_url' => $attributes['checkout_url'],
            'id'           => $response['data']['id'] ?? '',
        ];
    }

    /**
     * Issue a refund for a previously paid payment.
     *
     * @param string $paymentId PayMongo payment id (pay_...)
     * @param int $amountCents Amount to refund in centavos
     * @param string $reason One of: duplicate | fraudulent | others
     * @param string $notes Optional internal notes
     * @return array{id:string,amount:int,status:string}
     * @throws RuntimeException
     */
    public function createRefund(string $paymentId, int $amountCents, string $reason = 'others', string $notes = ''): array {
        if (!$this->isEnabled()) {
            throw new RuntimeException('PayMongo gateway is not configured.');
        }

        $payload = [
            'data' => [
                'attributes' => [
                    'amount'     => $amountCents,
                    'payment_id' => $paymentId,
                    'reason'     => $reason,
                    'notes'      => $notes,
                ],
            ],
        ];

        $response = $this->request('POST', '/v1/refunds', $payload);
        $resource = $response['data'] ?? [];
        $attributes = $resource['attributes'] ?? [];

        return [
            'id'     => $resource['id'] ?? '',
            'amount' => (int)($attributes['amount'] ?? $amountCents),
            'status' => (string)($attributes['status'] ?? 'pending'),
        ];
    }

    /**
     * Verify and parse an incoming webhook request.
     *
     * Verifies the HMAC signature when a webhook secret is configured.
     *
     * @param string $payloadJson raw request body
     * @param string $signatureHeader PayMongo-Signature header value
     * @return array{id:string,type:string,data:array}
     * @throws RuntimeException on invalid signature or malformed payload
     */
    public function verifyWebhook(string $payloadJson, string $signatureHeader): array {
        $webhookSecret = (string)($this->config['webhook_secret'] ?? '');
        if ($webhookSecret !== '') {
            $expected = hash_hmac('sha256', $payloadJson, $webhookSecret);
            $provided = trim($signatureHeader);
            if (!hash_equals($expected, $provided)) {
                throw new RuntimeException('Invalid PayMongo webhook signature.');
            }
        }

        $data = json_decode($payloadJson, true);
        if (!is_array($data) || !isset($data['data']['id'], $data['data']['attributes'])) {
            throw new RuntimeException('Malformed PayMongo webhook payload.');
        }

        return $data['data'];
    }

    /**
     * Perform a PayMongo API request.
     *
     * @param string $method GET|POST
     * @param string $path
     * @param array|null $body
     * @return array decoded JSON
     * @throws RuntimeException
     */
    private function request(string $method, string $path, ?array $body = null): array {
        $secretKey = (string)($this->config['secret_key'] ?? '');
        if ($secretKey === '') {
            throw new RuntimeException('PayMongo secret key is not configured.');
        }

        $url = rtrim((string)($this->config['base_url'] ?? 'https://api.paymongo.com'), '/') . $path;

        $ch = curl_init($url);
        $headers = [
            'Authorization: Basic ' . base64_encode($secretKey . ':'),
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body ?? new stdClass()));
        } elseif (strtoupper($method) === 'GET') {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        }

        $raw = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('PayMongo request failed: ' . $error);
        }

        $decoded = json_decode($raw, true);

        if ($http < 200 || $http >= 300) {
            $msg = $this->extractError($decoded);
            throw new RuntimeException("PayMongo API error ({$http}): {$msg}");
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Extract a human-readable message from a PayMongo error response.
     *
     * @param mixed $decoded
     * @return string
     */
    private function extractError($decoded): string {
        if (is_array($decoded) && isset($decoded['errors']) && is_array($decoded['errors'])) {
            $parts = [];
            foreach ($decoded['errors'] as $err) {
                if (isset($err['detail'])) {
                    $parts[] = $err['detail'];
                } elseif (isset($err['title'])) {
                    $parts[] = $err['title'];
                }
            }
            if ($parts) {
                return implode('; ', $parts);
            }
        }
        return 'Unknown PayMongo error.';
    }
}
