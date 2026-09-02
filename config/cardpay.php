<?php

declare(strict_types=1);
use App\Models\User;

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
    /*
    |--------------------------------------------------------------------------
    | Edition (§16)
    |--------------------------------------------------------------------------
    |
    | 'full' — the bundled product: admin panel, setup wizard, multi-application
    |          merchant management.
    | 'lite' — single-merchant, API-only: the same payment engine and the same
    |          core schema, with NO panel of its own. You drive it from your own
    |          admin through the Admin JSON API (see 'admin_api' below) and
    |          create payments in-process with the CardPay facade.
    |
    */
    'edition' => env('CARDPAY_EDITION', 'full'),

    /*
    | Per-feature overrides. Leave null to inherit the edition default; set a
    | boolean to force a feature on or off regardless of edition. Defaults:
    |
    |   feature              full    lite
    |   panel                on      off
    |   setup_wizard         on      off
    |   audit                on      off
    |   db_settings          on      off
    |   applications_admin   on      off
    |   admin_api            on      on
    |   checkout             on      on
    |   merchant_api         on      on
    |   device_api           on      on
    */
    'features' => [
        'panel' => env('CARDPAY_FEATURE_PANEL'),
        'setup_wizard' => env('CARDPAY_FEATURE_SETUP_WIZARD'),
        'audit' => env('CARDPAY_FEATURE_AUDIT'),
        'db_settings' => env('CARDPAY_FEATURE_DB_SETTINGS'),
        'applications_admin' => env('CARDPAY_FEATURE_APPLICATIONS_ADMIN'),
        'admin_api' => env('CARDPAY_FEATURE_ADMIN_API'),
        'checkout' => env('CARDPAY_FEATURE_CHECKOUT'),
        'merchant_api' => env('CARDPAY_FEATURE_MERCHANT_API'),
        'device_api' => env('CARDPAY_FEATURE_DEVICE_API'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin JSON API
    |--------------------------------------------------------------------------
    |
    | The headless equivalent of the panel: every admin capability as JSON, so
    | you can render the gateway's admin screens inside your own back office.
    |
    | The default stack is SESSION based — 'web' supplies the session and CSRF
    | protection, 'auth' the logged-in host user, 'cardpay.access' the Gate. It
    | is what a same-app admin (Blade/Livewire/Inertia/fetch) needs. For a
    | separate frontend, swap in ['api', 'auth:sanctum', 'cardpay.access'].
    |
    | NOTE: never put 'merchant.hmac' here. Merchant credentials are scoped to
    | creating payments; they must not reach admin capabilities.
    |
    */
    'admin_api' => [
        'prefix' => env('CARDPAY_ADMIN_API_PREFIX', 'api/cardpay/admin'),
        'middleware' => ['web', 'auth', 'cardpay.access'],
        'route_as' => 'cardpay.admin-api.',
        'per_page' => (int) env('CARDPAY_ADMIN_API_PER_PAGE', 25),
    ],

    /*
    |--------------------------------------------------------------------------
    | The single gateway application (lite provisioning)
    |--------------------------------------------------------------------------
    |
    | Lite runs exactly one application. `cardpay:install` creates it and mints
    | its first API key. Values here are the creation defaults only — once the
    | row exists it is edited through the Admin JSON API, never re-seeded.
    |
    */
    'gateway' => [
        'slug' => env('CARDPAY_GATEWAY_SLUG', 'store'),
        'name' => env('CARDPAY_GATEWAY_NAME', 'Default Store'),
        'webhook_url' => env('CARDPAY_WEBHOOK_URL'),
        'callback_url' => env('CARDPAY_CALLBACK_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Settings fallback (used when features.db_settings is OFF)
    |--------------------------------------------------------------------------
    |
    | Full stores these in cp_settings and edits them in the panel. Lite has no
    | panel and no settings table, so the same keys resolve from here. Setting::get()
    | reads whichever source is active, so checkout branding works either way.
    |
    */
    'settings' => [
        'app_name' => env('CARDPAY_APP_NAME', 'CardPay'),
        'currency' => env('CARDPAY_CURRENCY', 'IRR'),
        'timezone' => env('CARDPAY_TIMEZONE', 'Asia/Tehran'),
        'locale' => env('CARDPAY_LOCALE', 'fa'),
        'primary_color' => env('CARDPAY_PRIMARY_COLOR', '#155EEF'),
        'accent_color' => env('CARDPAY_ACCENT_COLOR', '#12B76A'),
        'payment_title' => env('CARDPAY_PAYMENT_TITLE', 'پرداخت امن کارت به کارت'),
        'payment_help' => env(
            'CARDPAY_PAYMENT_HELP',
            'مبلغ دقیق نشان داده شده را کارت به کارت کنید؛ همان رقم به‌صورت خودکار تأیید می‌شود. مبلغ را تغییر ندهید.'
        ),
        'success_text' => env('CARDPAY_SUCCESS_TEXT', 'پرداخت شما با موفقیت تأیید شد.'),
        'expired_text' => env('CARDPAY_EXPIRED_TEXT', 'مهلت این پرداخت به پایان رسیده است.'),
    ],

    // URL path segment for the CardPay panel (e.g. /cardpay, /cardpay/payments).
    'path' => env('CARDPAY_PATH', 'cardpay'),

    // Route name prefix for panel routes (e.g. cardpay.dashboard).
    'route_as' => env('CARDPAY_ROUTE_AS', 'cardpay'),

    // Panel access: host session + Laravel Gate. Override the gate in your
    // AppServiceProvider to customize who may access the panel.
    'auth' => [
        'gate' => 'cardpay.access',
        'roles' => ['super_admin', 'admin'],
    ],

    // The host application's user model. Optional IsGatewayUser trait for
    // role/is_active columns; Spatie-style hasRole() is also supported.
    'user' => [
        'model' => env('CARDPAY_USER_MODEL', User::class),
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
