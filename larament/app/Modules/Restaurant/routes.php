<?php

use App\Modules\Restaurant\Http\Controllers\FloorLayoutController;
use App\Modules\Restaurant\Http\Controllers\KitchenController;
use App\Modules\Restaurant\Http\Controllers\RestaurantPosController;
use Illuminate\Support\Facades\Route;

/*
 * Restaurant module routes, loaded by RestaurantServiceProvider. They are
 * always registered (so `route:cache` holds them) and answer 404 while the
 * module is off.
 */
Route::middleware(['web', 'auth', 'module:restaurant'])->group(function () {
    Route::get('/floor/designer/{code}', [FloorLayoutController::class, 'show'])->middleware('permission:update_order_types')->name('floor.designer');
    Route::put('/floor/save/{code}', [FloorLayoutController::class, 'save'])->middleware('permission:update_order_types')->name('floor.save');

    // Kitchen Display Screen
    Route::prefix('kitchen')->name('kitchen.')->group(function () {
        Route::get('/', [KitchenController::class, 'index'])
            ->name('index')
            ->middleware('permission:kitchen.access');

        Route::post('/items/{item}/done', [KitchenController::class, 'finishItem'])
            ->name('items.done')
            ->middleware('permission:kitchen.access');
        Route::post('/items/{item}/undo', [KitchenController::class, 'undoItem'])
            ->name('items.undo')
            ->middleware('permission:kitchen.access');
        Route::post('/tickets/{ticket}/done', [KitchenController::class, 'finishTicket'])
            ->name('tickets.done')
            ->middleware('permission:kitchen.access');
        Route::post('/tickets/{ticket}/acknowledge', [KitchenController::class, 'acknowledge'])
            ->name('tickets.acknowledge')
            ->middleware('permission:kitchen.access');
        Route::post('/tickets/{ticket}/rush', [KitchenController::class, 'rush'])
            ->name('tickets.rush')
            ->middleware('permission:kitchen.access|pos.access');
    });

    Route::get('/table-types', [RestaurantPosController::class, 'tableTypes'])->name('tableTypes')->middleware(['shift', 'permission:pos.access']);
    Route::post('/print-in-kitchen', [RestaurantPosController::class, 'printInKitchen'])->name('print.kitchen')->middleware('permission:pos.access');
});
