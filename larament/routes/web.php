<?php

use App\Events\TestEvent;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\ExternalOrderController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\InvoicePrintController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\Pos\HeldSaleController;
use App\Http\Controllers\Pos\InstantReturnController;
use App\Http\Controllers\Pos\InstantSaleController;
use App\Http\Controllers\PrinterController;
use App\Http\Controllers\ProductLookupController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ShiftController;
use App\Models\Order;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->to('/admin');
});

Route::get('/dashboard', function () {
    return redirect()->to('/admin');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Shift Management Routes
    Route::prefix('shifts')->name('shifts.')->group(function () {
        Route::get('/start', [ShiftController::class, 'showStartShift'])
            ->name('start')
            ->middleware('permission:shift.start');
        Route::post('/start', [ShiftController::class, 'startShift'])
            ->name('store')
            ->middleware('permission:shift.start');
        Route::post('/end', [ShiftController::class, 'endShift'])
            ->name('end')
            ->middleware('permission:shift.end');
    });

    // Order Management Routes
    Route::prefix('orders')->name('orders.')->middleware(['shift', 'permission:pos.access', 'order.tab'])->group(function () {
        Route::get('/', [OrderController::class, 'index'])->name('index');
        Route::get('/manage/{order}', [OrderController::class, 'manage'])->name('manage');
        Route::post('/{order}/external', [ExternalOrderController::class, 'save'])->name('external.save');
        Route::delete('/{order}/external/{external}/items/{item}', [ExternalOrderController::class, 'removeLine'])->name('external.removeLine');
        Route::post('/{order}/external/{external}/fulfill', [ExternalOrderController::class, 'fulfill'])->name('external.fulfill');
        Route::post('/create', [OrderController::class, 'createOrder'])->name('store');
        Route::post('/save-order/{order}', [OrderController::class, 'saveOrder'])->name('save');
        Route::post('/complete-order/{order}', [OrderController::class, 'completeOrder'])->name('complete');
        Route::post('/collect-remaining/{order}', [OrderController::class, 'collectRemaining'])->name('collectRemaining');
        Route::post('/cancel-order/{order}', [OrderController::class, 'cancelOrder'])->name('cancel');
        Route::post('/update-customer/{order}', [OrderController::class, 'updateCustomer'])->name('updateCustomer');
        Route::post('/update-type/{order}', [OrderController::class, 'updateOrderType'])->name('updateType');
        Route::post('/update-notes/{order}', [OrderController::class, 'updateOrderNotes'])->name('updateNotes');
        Route::post('/apply-discount/{order}', [OrderController::class, 'applyDiscount'])->name('applyDiscount');
        Route::post('/link-customer/{order}', [OrderController::class, 'linkCustomer'])->name('linkCustomer');
    });

    // Quick operations routes
    Route::post('/quick-customer', [OrderController::class, 'quickCustomer'])->name('quickCustomer')->middleware(['shift', 'permission:pos.access']);
    Route::post('/fetch-customer-info', [OrderController::class, 'fetchCustomerInfo'])->name('fetchCustomerInfo')->middleware(['shift', 'permission:pos.access']);
    Route::post('/search-customers-by-name', [OrderController::class, 'searchCustomersByName'])->name('searchCustomersByName')->middleware(['shift', 'permission:pos.access']);
    Route::get('/products/lookup', ProductLookupController::class)->name('products.lookup')->middleware(['shift', 'permission:pos.access']);

    // Instant sale (Phase 6c): one request per sale, held carts, returns and exchanges
    Route::prefix('pos')->name('pos.')->middleware(['shift', 'permission:pos.access'])->group(function () {
        Route::post('/instant-sales', [InstantSaleController::class, 'store'])->name('instant-sales.store');
        Route::get('/held-sales', [HeldSaleController::class, 'index'])->name('held-sales.index');
        Route::post('/held-sales', [HeldSaleController::class, 'store'])->name('held-sales.store');
        Route::delete('/held-sales/{heldSale}', [HeldSaleController::class, 'destroy'])->name('held-sales.destroy');
        Route::get('/instant-returns/lookup', [InstantReturnController::class, 'lookup'])->name('instant-returns.lookup');
        Route::post('/instant-returns', [InstantReturnController::class, 'store'])->name('instant-returns.store');
    });

    // Printer Management Routes
    Route::post('/orders/print/{order}', [OrderController::class, 'printReceipt'])->name('print')->middleware(['permission:pos.access', 'order.tab']);
    Route::post('/printers-of-products', [OrderController::class, 'getPrintersOfProducts'])->name('printers.products')->middleware('permission:pos.access');
    Route::post('/open-cashier-drawer', [OrderController::class, 'openCashierDrawer'])->name('cashier.openDrawer')->middleware('permission:cashier.open_drawer');

    // Admin Printer Management Routes
    Route::prefix('admin/printers')->name('admin.printers.')->middleware('permission:view_printers')->group(function () {
        Route::post('/test', [PrinterController::class, 'testPrinter'])->name('test');
        Route::post('/scan', [PrinterController::class, 'scanNetwork'])->name('scan');
    });

    // Expense Management Routes
    Route::prefix('expenses')->name('expenses.')->middleware(['shift', 'permission:pos.access', 'permission:pos.tab.system.expenses'])->group(function () {
        Route::get('/', [ExpenseController::class, 'index'])->name('index');
        Route::post('/', [ExpenseController::class, 'store'])->name('store');
        Route::put('/{expense}', [ExpenseController::class, 'update'])->name('update');
        Route::delete('/{expense}', [ExpenseController::class, 'destroy'])->name('destroy');
    });

    // Inventory Management Routes
    Route::prefix('inventory')->name('inventory.')->group(function () {
        Route::get('/day-status', [InventoryController::class, 'dayStatus'])->name('dayStatus');
        Route::post('/toggle-day', [InventoryController::class, 'toggleDay'])->name('toggleDay')->middleware('permission:inventory.toggle_day');
    });

    // Invoice Print Routes
    Route::get('/print/invoice/{type}/{id}', [InvoicePrintController::class, 'show'])
        ->name('invoice.print')
        ->where(['type' => '(purchase_invoice|return_purchase_invoice|stocktaking|waste)', 'id' => '[0-9]+'])
        ->middleware('permission:invoice.print');
});

require __DIR__.'/auth.php';

// Simple test event
Route::get('/test-simple', function () {
    Log::info('Dispatching simple test event');

    $event = new TestEvent('This is a test message');
    event($event);

    Log::info('Simple test event dispatched');

    return response()->json([
        'message' => 'Simple test event dispatched',
        'timestamp' => now()->format('H:i:s'),
    ]);
});
