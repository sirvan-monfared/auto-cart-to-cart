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
use Illuminate\Support\Facades\Route;

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

Route::get('reviews/{review}/receipt', [AdminReceiptController::class, 'download'])
    ->name('reviews.receipt');

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

Route::get('docs', [AdminDocsController::class, 'index'])->name('docs');
Route::get('docs/{section}', [AdminDocsController::class, 'show'])
    ->where('section', '[a-z-]+')
    ->name('docs.show');

Route::get('guides/devices/android', [DeviceGuideController::class, 'android'])->name('guides.devices.android');
Route::get('guides/devices/ios-shortcut', [DeviceGuideController::class, 'ios'])->name('guides.devices.ios');
