<?php
/**
 * PayMongo Payment Configuration
 * LPG Delivery System v2
 *
 * NOTE: This file contains a shared PayMongo TEST key so the whole team
 * can test online payment. For production, replace with your own live key
 * (sk_live_...) and add it to your local .gitignore.
 */

return [
    // Never expose the secret key to the browser. Use the test key for
    // development (sk_test_...) and the live key (sk_live_...) in production.
    'secret_key' => getenv('PAYMONGO_SECRET_KEY') ?: 'sk_test_6DQ5RQxQL1HvEgBYdUYqf2GM',

    // Public key is only used if you embed PayMongo's JS widgets.
    'public_key' => '',

    'base_url'   => 'https://api.paymongo.com',

    // Payment methods offered by Hosted Checkout for online payment.
    // Customers can still choose GCash, Maya, etc. on the PayMongo page.
    'payment_method_types' => ['gcash', 'paymaya', 'card'],

    // Webhook secret from PayMongo dashboard (Settings -> Webhooks).
    // Leave empty to skip signature verification in development.
    'webhook_secret' => '',

    'enabled' => true,
];
