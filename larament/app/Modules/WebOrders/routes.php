<?php

use App\Http\Middleware\VerifyApiKey;
use App\Modules\WebOrders\Http\Controllers\Api\WebOrdersController;
use App\Modules\WebOrders\Http\Controllers\WebOrderController;
use Illuminate\Support\Facades\Route;

/*
 * Web orders module routes, loaded by WebOrdersServiceProvider. They are
 * always registered (so `route:cache` holds them). The cashier screens
 * answer 404 while the module is off; the website API endpoints keep their
 * API-key check only, as before.
 */
Route::middleware(['web', 'auth'])->group(function () {
    Route::prefix('web-orders')->name('web-orders.')->middleware(['module:web_orders', 'permission:web_order.manage', 'order.tab'])->group(function () {
        Route::get('/manage-web-order/{order}', [WebOrderController::class, 'manage'])->name('manage');
        Route::post('/accept-order/{order}', [WebOrderController::class, 'acceptOrder'])->name('accept');
        Route::post('/reject-order/{order}', [WebOrderController::class, 'rejectOrder'])->name('reject');
        Route::post('/complete-order/{order}', [WebOrderController::class, 'completeOrder'])->name('complete');
        Route::post('/out-for-delivery/{order}', [WebOrderController::class, 'outForDelivery'])->name('outForDelivery');
        Route::post('/apply-discount/{order}', [WebOrderController::class, 'applyDiscount'])->name('applyDiscount');
        Route::post('/save-order/{order}', [WebOrderController::class, 'saveOrder'])->name('saveOrder');
    });
});

// Website API (placing orders from the linked website)
Route::prefix('api')->middleware(['api', VerifyApiKey::class])->group(function () {
    Route::post('/web-orders/place-order', [WebOrdersController::class, 'createOrder']);
    Route::get('/can-accept-order', [WebOrdersController::class, 'canAcceptOrder']);
    Route::get('/get-shift-id', [WebOrdersController::class, 'getShiftId']);
});
