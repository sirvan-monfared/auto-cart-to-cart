<?php

use CartBecart\CardPay\Http\Controllers\Setup\SetupController;
use Illuminate\Support\Facades\Route;

Route::get('/', [SetupController::class, 'index'])->name('index');
Route::post('database', [SetupController::class, 'installDatabase'])->name('database');
Route::get('admin', [SetupController::class, 'showAdmin'])->name('admin');
Route::post('admin', [SetupController::class, 'storeAdmin'])->name('admin.store');
Route::get('finalize', [SetupController::class, 'showFinalize'])->name('finalize');
Route::post('finalize', [SetupController::class, 'finalize'])->name('finalize.complete');
