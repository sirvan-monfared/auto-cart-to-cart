<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CardPay gateway configuration (§14 / §16)
|--------------------------------------------------------------------------
|
| Central tunables for the payment gateway. Defaults mirror the specification;
| every value can be overridden per-deployment via the listed env keys without
| touching code. Per-application overrides (token digits, expiry) live on the
| cp_applications row; the values here are the system-wide fallbacks.
|
*/

return [
    // The host application's user model — must implement
    // CartBecart\CardPay\Contracts\GatewayUser (adopt the IsGatewayUser trait
    // on your User model). The package never hardcodes a user class.
    'user' => [
        'model' => env('CARDPAY_USER_MODEL', \App\Models\User::class),
    ],

    // The ACTIVE payment driver (§5/§16): resolved through DriverRegistry.
    // 'card_transfer' is the built-in manual card-to-card method; future
    // gateway drivers plug in by registering in CardPayServiceProvider and
    // changing only this value.
    'driver' => env('CARDPAY_DRIVER', 'card_transfer'),

    // Default amount-token width and lifetime for new payments (§14.2).
    'token' => [
        'digits' => (int) env('CARDPAY_TOKEN_DIGITS', 3), // 2 → 1..99, 3 → 1..999
    ],

    'expiration_minutes' => (int) env('CARDPAY_EXPIRATION_MINUTES', 30),

    // Minutes a settled payment's amount stays reserved before it can be reused,
    // so a stale deposit SMS can't match a brand-new order at the same amount.
    'cooldown_minutes' => (int) env('CARDPAY_COOLDOWN_MINUTES', 10),

    'currency' => env('CARDPAY_CURRENCY', 'IRR'),

    // HMAC request authentication (§A5).
    'hmac' => [
        'timestamp_tolerance' => (int) env('API_TIMESTAMP_TOLERANCE', 300), // seconds
        'nonce_min' => 12,
        'nonce_max' => 190,
    ],

    // DB fixed-window rate limiter caps (§A7 / §14.1).
    'rate_limits' => [
        'api' => (int) env('API_RATE_LIMIT', 120),
        'device' => (int) env('DEVICE_RATE_LIMIT', 60),
        'login' => (int) env('LOGIN_RATE_LIMIT', 5),
        'public_status' => (int) env('PUBLIC_STATUS_RATE_LIMIT', 120),
        'window_seconds' => (int) env('CARDPAY_RATE_WINDOW', 60),
    ],

    // Idempotency ledger for payment creation (§A8).
    'idempotency' => [
        'key_min' => 8,
        'key_max' => 190,
        'ttl_hours' => 24,
    ],

    // Webhook delivery (§A6 / §FR-13).
    'webhooks' => [
        'retry_minutes' => [0, 1, 5, 15, 60],
        'connect_timeout' => 3, // seconds
        'timeout' => 8,         // seconds (total)
        'user_agent' => 'CardPay-Webhook/1.0',
        'max_response_body' => 4000,
        'max_error' => 500,
    ],

    // Per-request budgeted maintenance (§A9). Keeps the system live without cron.
    'maintenance' => [
        'expire_due' => 20,
        'release_due' => 100,
        'webhook_process_due' => 3,
        'expire_batch_elsewhere' => 50,
    ],

    // Customer receipt uploads for manual review (§SR-11).
    'uploads' => [
        'max_mb' => (int) env('UPLOAD_MAX_MB', 5),
        'allowed_mime' => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
    ],
];
