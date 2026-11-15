<?php

use App\Modules\ExternalSellers\Http\Controllers\ExternalOrderController;
use Illuminate\Support\Facades\Route;

/*
 * External sellers module routes, loaded by ExternalSellersServiceProvider.
 * Always registered (so `route:cache` holds them); the controller refuses
 * while the module (the outsourced-orders switch) is off, as before.
 */
Route::middleware(['web', 'auth', 'shift', 'permission:pos.access', 'order.tab'])
    ->prefix('orders')
    ->name('orders.')
    ->group(function () {
        Route::post('/{order}/external', [ExternalOrderController::class, 'save'])->name('external.save');
        Route::delete('/{order}/external/{external}/items/{item}', [ExternalOrderController::class, 'removeLine'])->name('external.removeLine');
        Route::post('/{order}/external/{external}/fulfill', [ExternalOrderController::class, 'fulfill'])->name('external.fulfill');
    });
