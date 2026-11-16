<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\BadgeLoginController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    // Badge (barcode card) quick login to the POS
    Route::get('badge', [BadgeLoginController::class, 'create'])->name('badge.login');
    Route::post('badge', [BadgeLoginController::class, 'store'])->name('badge.login.store');

});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');

    Route::post('badge/switch', [BadgeLoginController::class, 'switch'])->name('badge.switch');
});
