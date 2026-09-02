<?php

declare(strict_types=1);

use CartBecart\CardPay\Http\Controllers\Admin\AdminReceiptController;
use CartBecart\CardPay\Http\Controllers\AdminApi\BankCardController;
use CartBecart\CardPay\Http\Controllers\AdminApi\DeviceController;
use CartBecart\CardPay\Http\Controllers\AdminApi\GatewayController;
use CartBecart\CardPay\Http\Controllers\AdminApi\IncomingSmsController;
use CartBecart\CardPay\Http\Controllers\AdminApi\OverviewController;
use CartBecart\CardPay\Http\Controllers\AdminApi\PaymentController;
use CartBecart\CardPay\Http\Controllers\AdminApi\ReportController;
use CartBecart\CardPay\Http\Controllers\AdminApi\ReviewController;
use CartBecart\CardPay\Http\Controllers\AdminApi\SmsParserController;
use CartBecart\CardPay\Http\Controllers\AdminApi\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin JSON API (§16)
|--------------------------------------------------------------------------
|
| Every capability the bundled panel has, as JSON — so a host application can
| render the gateway's admin inside its own back office with its own theme.
| In the lite edition this is the ONLY admin surface: there is no panel.
|
| Mounted by CardPayServiceProvider under config('cardpay.admin_api.prefix')
| with config('cardpay.admin_api.middleware'). The default stack is session +
| Gate ('web', 'auth', 'cardpay.access'), so these routes are authorized by the
| SAME rule as the panel — override the `cardpay.access` Gate once and both
| surfaces follow.
|
| Merchant HMAC credentials deliberately grant nothing here: they authorize
| creating payments, not administering the gateway.
|
*/

Route::get('overview', [OverviewController::class, 'stats'])->name('overview');
Route::get('features', [OverviewController::class, 'features'])->name('features');

// The single gateway application: webhook target, allow-list, token width,
// expiry, and API-key rotation. Not a collection — lite has exactly one.
Route::get('gateway', [GatewayController::class, 'show'])->name('gateway.show');
Route::put('gateway', [GatewayController::class, 'update'])->name('gateway.update');
Route::post('gateway/rotate-api-key', [GatewayController::class, 'rotateApiKey'])->name('gateway.rotate');

// Payments are read-only: settling by hand goes through the review queue so
// the state machine, token release, and webhook emission all still run.
Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
Route::get('payments/{publicId}', [PaymentController::class, 'show'])->name('payments.show');

Route::get('reviews', [ReviewController::class, 'index'])->name('reviews.index');
Route::get('reviews/{review}', [ReviewController::class, 'show'])->name('reviews.show');
Route::post('reviews/{review}/approve', [ReviewController::class, 'approve'])->name('reviews.approve');
Route::post('reviews/{review}/reject', [ReviewController::class, 'reject'])->name('reviews.reject');
// Reuses the panel's receipt endpoint: private disk, traversal guard, MIME
// allow-list, audited download. Security-sensitive logic worth not forking.
Route::get('reviews/{review}/receipt', [AdminReceiptController::class, 'download'])->name('reviews.receipt');

Route::get('cards', [BankCardController::class, 'index'])->name('cards.index');
Route::post('cards', [BankCardController::class, 'store'])->name('cards.store');
Route::get('cards/{card}', [BankCardController::class, 'show'])->name('cards.show');
Route::put('cards/{card}', [BankCardController::class, 'update'])->name('cards.update');
Route::delete('cards/{card}', [BankCardController::class, 'destroy'])->name('cards.destroy');
Route::post('cards/{card}/activate', [BankCardController::class, 'activate'])->name('cards.activate');

Route::get('devices', [DeviceController::class, 'index'])->name('devices.index');
Route::post('devices', [DeviceController::class, 'store'])->name('devices.store');
Route::get('devices/{device}', [DeviceController::class, 'show'])->name('devices.show');
Route::put('devices/{device}', [DeviceController::class, 'update'])->name('devices.update');
Route::post('devices/{device}/rotate', [DeviceController::class, 'rotate'])->name('devices.rotate');
Route::post('devices/{device}/revoke', [DeviceController::class, 'revoke'])->name('devices.revoke');

Route::get('parsers', [SmsParserController::class, 'index'])->name('parsers.index');
Route::post('parsers', [SmsParserController::class, 'store'])->name('parsers.store');
Route::get('parsers/{parser}', [SmsParserController::class, 'show'])->name('parsers.show');
Route::put('parsers/{parser}', [SmsParserController::class, 'update'])->name('parsers.update');
Route::post('parsers/{parser}/test', [SmsParserController::class, 'test'])->name('parsers.test');

Route::get('sms', [IncomingSmsController::class, 'index'])->name('sms.index');
Route::get('sms/{sms}', [IncomingSmsController::class, 'show'])->name('sms.show');

Route::get('webhooks', [WebhookController::class, 'index'])->name('webhooks.index');
Route::get('webhooks/deliveries', [WebhookController::class, 'deliveries'])->name('webhooks.deliveries');
Route::post('webhooks/deliveries/{delivery}/retry', [WebhookController::class, 'retry'])->name('webhooks.retry');

Route::get('reports', [ReportController::class, 'summary'])->name('reports.summary');
Route::get('reports/csv', [ReportController::class, 'csv'])->name('reports.csv');
