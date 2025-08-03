<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\OrderController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Shift Management Routes
    Route::prefix('shifts')->name('shifts.')->group(function () {
        Route::get('/start', [OrderController::class, 'showStartShift'])->name('start');
        Route::post('/start', [OrderController::class, 'startShift'])->name('store');
        Route::post('/end', [OrderController::class, 'endShift'])->name('end');
    });

    // Order Management Routes
    Route::prefix('orders')->name('orders.')->group(function () {
        Route::get('/', [OrderController::class, 'index'])->name('index');
        Route::get('/manage/{order}', [OrderController::class, 'manage'])->name('manage');
        Route::post('/create', [OrderController::class, 'createOrder'])->name('store');
        Route::post('/save-order/{order}', [OrderController::class, 'saveOrder'])->name('save');
        Route::post('/complete-order/{order}', [OrderController::class, 'completeOrder'])->name('complete');
        Route::post('/cancel-order/{order}', [OrderController::class, 'cancelOrder'])->name('cancel');
        Route::post('/update-customer/{order}', [OrderController::class, 'updateCustomer'])->name('updateCustomer');
        Route::post('/update-driver/{order}', [OrderController::class, 'updateDriver'])->name('updateDriver');
        Route::post('/update-type/{order}', [OrderController::class, 'updateOrderType'])->name('updateType');
        Route::post('/update-notes/{order}', [OrderController::class, 'updateOrderNotes'])->name('updateNotes');
        Route::post('/apply-discount/{order}', [OrderController::class, 'applyDiscount'])->name('applyDiscount');
        Route::post('/print/{order}', [OrderController::class, 'printReceipt'])->name('print');
        Route::post('/print-kitchen/{order}', [OrderController::class, 'printKitchen'])->name('printKitchen');
    })->middleware(['shift']);
});

require __DIR__ . '/auth.php';
