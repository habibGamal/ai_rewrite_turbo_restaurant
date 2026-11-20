<?php

use App\Modules\Delivery\Http\Controllers\DriverPosController;
use Illuminate\Support\Facades\Route;

/*
 * Delivery module routes, loaded by DeliveryServiceProvider. They are always
 * registered (so `route:cache` holds them) and answer 404 while the module
 * is off.
 */
Route::middleware(['web', 'auth', 'module:delivery'])->group(function () {
    Route::prefix('orders')->name('orders.')->middleware(['shift', 'permission:pos.access', 'order.tab'])->group(function () {
        Route::post('/update-driver/{order}', [DriverPosController::class, 'updateDriver'])->name('updateDriver');
        Route::post('/link-driver/{order}', [DriverPosController::class, 'linkDriver'])->name('linkDriver');
    });

    Route::post('/quick-driver', [DriverPosController::class, 'quickDriver'])->name('quickDriver')->middleware(['shift', 'permission:pos.access']);
    Route::post('/fetch-driver-info', [DriverPosController::class, 'fetchDriverInfo'])->name('fetchDriverInfo')->middleware(['shift', 'permission:pos.access']);
});
