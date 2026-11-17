<?php

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\ManagementController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PrintAgentController;
use App\Http\Middleware\VerifyApiKey;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Turbo Label app on a drawer's computer (Bearer = the drawer's pairing token)
Route::prefix('print-agent')->name('print-agent.')->middleware('throttle:900,1')->group(function () {
    Route::post('/hello', [PrintAgentController::class, 'hello'])->name('hello');
    Route::get('/jobs', [PrintAgentController::class, 'jobs'])->name('jobs');
    Route::post('/jobs/{job}', [PrintAgentController::class, 'finish'])->whereNumber('job')->name('finish');
});

// Health check endpoints
Route::get('/check', [ApiController::class, 'check']);
Route::post('/check', [ApiController::class, 'check']);

// Management endpoints
Route::prefix('management')->name('management.')->group(function () {
    Route::post('/deploy', [ManagementController::class, 'deploy'])->name('deploy');
    Route::post('/stop', [ManagementController::class, 'stop'])->name('stop');
    Route::post('/start', [ManagementController::class, 'start'])->name('start');
    Route::post('/custom-script', [ManagementController::class, 'customScript'])->name('custom-script');
    Route::get('/status', [ManagementController::class, 'status'])->name('status');
});

// Protected endpoints requiring API key
Route::middleware(VerifyApiKey::class)->group(function () {
    // Product search and management
    Route::get('/products/product_search', [ApiController::class, 'productSearch']);
    Route::post('/validate_products', [ApiController::class, 'validateProducts']);
    Route::get('/all-products', [ApiController::class, 'allProducts']);
    Route::get('/all-products-refs-master', [ApiController::class, 'allProductsRefsMaster']);
    Route::get('/all-products-prices-master', [ApiController::class, 'allProductsPricesMaster']);
    Route::get('/all-products-recipes-master', [ApiController::class, 'allProductsRecipesMaster']);
    Route::get('/get-products-master', [ApiController::class, 'getProductsMaster']);
    Route::get('/get-products-master-by-refs', [ApiController::class, 'getProductsMasterByRefs']);
    Route::get('/get-products-prices-master', [ApiController::class, 'getProductsPricesMaster']);

    Route::post('/save-order/{order}', [OrderController::class, 'saveOrder'])->name('save');
});
