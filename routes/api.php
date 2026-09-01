<?php

declare(strict_types=1);

use CartBecart\CardPay\Http\Controllers\Api\DeviceSmsController;
use CartBecart\CardPay\Http\Controllers\Api\PaymentApiController;
use CartBecart\CardPay\Http\Controllers\Api\PublicStatusController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Merchant API (§11.2)
|--------------------------------------------------------------------------
|
| Registered with the `api` prefix, so paths below resolve under /api/v1/*.
| The merchant surface (#1–4) is HMAC-authenticated (X-CardPay-* headers) then
| rate-limited per application — see MerchantHmacAuth. The payment id in the
| path is the opaque public id (PAY…); lookups are ownership-scoped inside the
| service, so there is no route-model binding to leak a foreign payment.
|
| The device surfaces (#8–9) relay bank deposit SMS: incoming-sms uses full
| device HMAC (X-Device-* headers), shortcut-sms uses the static key+secret
| fingerprint check for iOS Shortcuts. Both are rate-limited per IP (pre-auth)
| and per device (post-auth).
|
*/

Route::prefix('v1')->middleware('merchant.hmac')->group(function (): void {
    Route::post('payments', [PaymentApiController::class, 'store']);
    Route::get('payments/{payment}', [PaymentApiController::class, 'show']);
    Route::post('payments/{payment}/verify', [PaymentApiController::class, 'verify']);
    Route::post('payments/{payment}/cancel', [PaymentApiController::class, 'cancel']);
});

Route::prefix('v1/devices')->group(function (): void {
    Route::post('incoming-sms', [DeviceSmsController::class, 'incomingSms'])
        ->middleware('device.hmac');
    Route::post('shortcut-sms', [DeviceSmsController::class, 'shortcutSms'])
        ->middleware('device.shortcut');
});

// Public checkout polling (§FR-8 #2) — no auth by design; guarded by rate
// limit + opaque public_id + minimal response body.
Route::get('v1/public/payments/{publicId}/status', [PublicStatusController::class, 'show']);
