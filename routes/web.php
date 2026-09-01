<?php

use CartBecart\CardPay\Http\Controllers\Admin\AdminDocsController;
use CartBecart\CardPay\Http\Controllers\Admin\AdminPanelController;
use CartBecart\CardPay\Http\Controllers\Admin\AdminReceiptController;
use CartBecart\CardPay\Http\Controllers\Admin\ApplicationAdminController;
use CartBecart\CardPay\Http\Controllers\Admin\BankCardAdminController;
use CartBecart\CardPay\Http\Controllers\Admin\DeviceAdminController;
use CartBecart\CardPay\Http\Controllers\Admin\DeviceGuideController;
use CartBecart\CardPay\Http\Controllers\Admin\ReportsController;
use CartBecart\CardPay\Http\Controllers\Admin\ReviewAdminController;
use CartBecart\CardPay\Http\Controllers\Admin\SettingsAdminController;
use CartBecart\CardPay\Http\Controllers\Admin\SmsParserAdminController;
use CartBecart\CardPay\Http\Controllers\Admin\SystemInfoController;
use CartBecart\CardPay\Http\Controllers\Checkout\CheckoutPageController;
use CartBecart\CardPay\Http\Controllers\Checkout\ManualReviewController;
use CartBecart\CardPay\Http\Controllers\Setup\SetupController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

// Legacy post-login path: kept as a named redirect so links and starter
// conventions keep working — the panel itself lives at /admin.
Route::get('dashboard', fn (): RedirectResponse => auth()->check()
    ? redirect('/admin')
    : redirect()->route('login'))->name('dashboard');

// Setup wizard: reachable only before the install lock exists; the `installed`
// middleware 404s the whole surface afterwards.
Route::middleware(['installed'])->prefix('setup')->name('setup.')->group(function (): void {
    Route::get('/', [SetupController::class, 'index'])->name('index');
    Route::post('database', [SetupController::class, 'installDatabase'])->name('database');
    Route::get('admin', [SetupController::class, 'showAdmin'])->name('admin');
    Route::post('admin', [SetupController::class, 'storeAdmin'])->name('admin.store');
    Route::get('finalize', [SetupController::class, 'showFinalize'])->name('finalize');
    Route::post('finalize', [SetupController::class, 'finalize'])->name('finalize.complete');
});

// Hosted checkout: customer page + report form. Session web middleware
// (CSRF for the form) applies by default; the page itself is public.
Route::get('p/{publicId}', [CheckoutPageController::class, 'show']);
Route::post('p/{publicId}/manual-review', [ManualReviewController::class, 'store']);

// Admin panel: active-admin session required (`admin` gate).
Route::middleware(['admin'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/', [AdminPanelController::class, 'dashboard'])->name('dashboard');
    Route::get('payments', [AdminPanelController::class, 'payments'])->name('payments');
    Route::get('payments/{publicId}', [AdminPanelController::class, 'paymentDetail'])->name('payments.show');
    Route::get('reviews', [AdminPanelController::class, 'reviews'])->name('reviews');
    Route::post('reviews/{review}/approve', [ReviewAdminController::class, 'approve'])
        ->name('reviews.approve');
    Route::post('reviews/{review}/reject', [ReviewAdminController::class, 'reject'])
        ->name('reviews.reject');
    Route::get('sms-log', [AdminPanelController::class, 'smsLog'])->name('sms');
    Route::get('webhooks', [AdminPanelController::class, 'webhooks'])->name('webhooks');
    Route::post('webhooks/deliveries/{delivery}/retry', [AdminPanelController::class, 'retryWebhook'])
        ->name('webhooks.retry');
    Route::get('audit', [AdminPanelController::class, 'auditLog'])->name('audit');

    // Receipt access: private-disk files served only through this admin-gated,
    // audited endpoint.
    Route::get('reviews/{review}/receipt', [AdminReceiptController::class, 'download'])
        ->name('reviews.receipt');

    // CRUD
    Route::get('cards', [BankCardAdminController::class, 'index'])->name('cards');
    Route::post('cards', [BankCardAdminController::class, 'store'])->name('cards.store');
    Route::put('cards/{card}', [BankCardAdminController::class, 'update'])->name('cards.update');
    Route::delete('cards/{card}', [BankCardAdminController::class, 'destroy'])->name('cards.destroy');
    Route::post('cards/{card}/activate', [BankCardAdminController::class, 'activate'])->name('cards.activate');

    Route::get('parsers', [SmsParserAdminController::class, 'index'])->name('parsers');
    Route::post('parsers', [SmsParserAdminController::class, 'store'])->name('parsers.store');
    Route::put('parsers/{parser}', [SmsParserAdminController::class, 'update'])->name('parsers.update');
    Route::post('parsers/{parser}/live-test', [SmsParserAdminController::class, 'liveTest'])->name('parsers.test');

    Route::get('applications', [ApplicationAdminController::class, 'index'])->name('applications');
    Route::post('applications', [ApplicationAdminController::class, 'store'])->name('applications.store');
    Route::put('applications/{application}', [ApplicationAdminController::class, 'update'])->name('applications.update');
    Route::post('applications/{application}/rotate', [ApplicationAdminController::class, 'rotate'])->name('applications.rotate');

    Route::get('devices', [DeviceAdminController::class, 'index'])->name('devices');
    Route::post('devices', [DeviceAdminController::class, 'store'])->name('devices.store');
    Route::put('devices/{device}', [DeviceAdminController::class, 'update'])->name('devices.update');
    Route::post('devices/{device}/rotate', [DeviceAdminController::class, 'rotate'])->name('devices.rotate');
    Route::post('devices/{device}/revoke', [DeviceAdminController::class, 'revoke'])->name('devices.revoke');

    Route::get('settings', [SettingsAdminController::class, 'index'])->name('settings');
    Route::put('settings', [SettingsAdminController::class, 'update'])->name('settings.update');

    Route::get('reports', [ReportsController::class, 'index'])->name('reports');
    Route::get('reports/csv', [ReportsController::class, 'csv'])->name('reports.csv');

    Route::get('system', [SystemInfoController::class, 'index'])->name('system');

    // Persian documentation hub + per-section guides.
    Route::get('docs', [AdminDocsController::class, 'index'])->name('docs');
    Route::get('docs/{section}', [AdminDocsController::class, 'show'])
        ->where('section', '[a-z-]+')
        ->name('docs.show');

    // Per-platform device onboarding guides, Persian RTL.
    Route::get('guides/devices/android', [DeviceGuideController::class, 'android'])->name('guides.devices.android');
    Route::get('guides/devices/ios-shortcut', [DeviceGuideController::class, 'ios'])->name('guides.devices.ios');
});

require __DIR__.'/settings.php';
