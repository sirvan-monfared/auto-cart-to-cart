<?php

use CartBecart\CardPay\Http\Controllers\Checkout\CheckoutPageController;
use CartBecart\CardPay\Http\Controllers\Checkout\ManualReviewController;
use Illuminate\Support\Facades\Route;

// Hosted checkout: customer page + report form. Session web middleware
// (CSRF for the form) applies by default; the page itself is public.
Route::get('p/{publicId}', [CheckoutPageController::class, 'show']);
Route::post('p/{publicId}/manual-review', [ManualReviewController::class, 'store']);
