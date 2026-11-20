<?php

use App\Http\Controllers\BadgePrintController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\InvoicePrintController;
use App\Http\Controllers\LabelJobController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderEquipmentController;
use App\Http\Controllers\Pos\CustomerDisplayController;
use App\Http\Controllers\Pos\HeldSaleController;
use App\Http\Controllers\Pos\InstantReturnController;
use App\Http\Controllers\Pos\InstantSaleController;
use App\Http\Controllers\Pos\OrderFeeController;
use App\Http\Controllers\Pos\PosProductController;
use App\Http\Controllers\PrinterController;
use App\Http\Controllers\ProductLabelController;
use App\Http\Controllers\ProductLookupController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\TerminalController;
use App\Http\Middleware\RejectBadgeSessions;
use App\Models\Order;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->to('/admin');
});

Route::get('/dashboard', function () {
    return redirect()->to('/admin');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    // A badge login is POS-only: it can't change the account's e-mail or delete it.
    Route::middleware(RejectBadgeSessions::class)->group(function () {
        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    });

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

    // Printable badge card (code shown once, right after «طباعة بادج»)
    Route::get('/users/{user}/badge/print', BadgePrintController::class)
        ->name('users.badge.print')
        ->middleware(RejectBadgeSessions::class);

    // Drawers (multiple terminals): bind device, open, take over, close
    Route::prefix('terminals')->name('terminals.')->group(function () {
        Route::get('/device', [TerminalController::class, 'device'])->name('device');
        Route::post('/device', [TerminalController::class, 'bindDevice'])->name('device.bind')->middleware('permission:terminal.bind_device');
        Route::get('/open', [TerminalController::class, 'showOpen'])->name('open');
        Route::post('/open', [TerminalController::class, 'open'])->name('open.store')->middleware('permission:terminal.open');
        Route::get('/handover', [TerminalController::class, 'showHandover'])->name('handover');
        Route::post('/handover', [TerminalController::class, 'handover'])->name('handover.store')->middleware('permission:terminal.open');
        Route::post('/close', [TerminalController::class, 'close'])->name('close')->middleware('permission:terminal.close');
        Route::post('/sessions/{session}/close', [TerminalController::class, 'closeOther'])->name('sessions.close')->middleware('permission:terminal.close_others');
    });

    // Order Management Routes
    Route::prefix('orders')->name('orders.')->middleware(['shift', 'permission:pos.access', 'order.tab'])->group(function () {
        Route::get('/', [OrderController::class, 'index'])->name('index');
        Route::get('/manage/{order}', [OrderController::class, 'manage'])->name('manage');
        Route::post('/create', [OrderController::class, 'createOrder'])->name('store');
        Route::post('/save-order/{order}', [OrderController::class, 'saveOrder'])->name('save');
        Route::post('/complete-order/{order}', [OrderController::class, 'completeOrder'])->name('complete');
        Route::post('/collect-remaining/{order}', [OrderController::class, 'collectRemaining'])->name('collectRemaining');
        Route::post('/cancel-order/{order}', [OrderController::class, 'cancelOrder'])->name('cancel');
        Route::post('/update-customer/{order}', [OrderController::class, 'updateCustomer'])->name('updateCustomer');
        Route::post('/update-type/{order}', [OrderController::class, 'updateOrderType'])->name('updateType');
        Route::post('/update-notes/{order}', [OrderController::class, 'updateOrderNotes'])->name('updateNotes');
        Route::post('/apply-discount/{order}', [OrderController::class, 'applyDiscount'])->name('applyDiscount');
        Route::post('/update-guests/{order}', [OrderController::class, 'updateGuestsCount'])->name('updateGuests');
        Route::post('/link-customer/{order}', [OrderController::class, 'linkCustomer'])->name('linkCustomer');
        Route::post('/{order}/equipment', [OrderEquipmentController::class, 'save'])->name('equipment.save');
        Route::delete('/{order}/equipment/{line}', [OrderEquipmentController::class, 'remove'])->name('equipment.remove');
        Route::post('/{order}/fees', [OrderFeeController::class, 'store'])->name('fees.store');
        Route::delete('/{order}/fees/{fee}', [OrderFeeController::class, 'destroy'])->name('fees.destroy');
    });

    // Quick operations routes
    Route::post('/quick-customer', [OrderController::class, 'quickCustomer'])->name('quickCustomer')->middleware(['shift', 'permission:pos.access']);
    Route::post('/fetch-customer-info', [OrderController::class, 'fetchCustomerInfo'])->name('fetchCustomerInfo')->middleware(['shift', 'permission:pos.access']);
    Route::post('/search-customers-by-name', [OrderController::class, 'searchCustomersByName'])->name('searchCustomersByName')->middleware(['shift', 'permission:pos.access']);
    Route::get('/products/lookup', ProductLookupController::class)->name('products.lookup')->middleware(['shift', 'permission:pos.access']);

    // Customer-facing monitor (fed by the cashier screen in the same browser)
    Route::get('/pos/customer-display', CustomerDisplayController::class)->name('pos.customer-display')->middleware('permission:pos.access');

    // Instant sale (Phase 6c): one request per sale, held carts, returns and exchanges
    Route::prefix('pos')->name('pos.')->middleware(['shift', 'permission:pos.access'])->group(function () {
        Route::post('/instant-sales', [InstantSaleController::class, 'store'])->name('instant-sales.store');
        Route::get('/products/search', [PosProductController::class, 'search'])->name('products.search');
        Route::get('/products', [PosProductController::class, 'index'])->name('products.index');
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

    // Barcode labels for a product group's variants
    Route::get('/print/labels/group/{group}', [ProductLabelController::class, 'group'])->name('labels.group');
    // Label print job in the browser (PC without the Windows print app)
    Route::get('/print/labels/jobs/{job}', [LabelJobController::class, 'browser'])->whereUuid('job')->name('labels.jobs.browser');

    // Invoice Print Routes
    Route::get('/print/invoice/{type}/{id}', [InvoicePrintController::class, 'show'])
        ->name('invoice.print')
        ->where(['type' => '(purchase_invoice|return_purchase_invoice|stocktaking|waste)', 'id' => '[0-9]+'])
        ->middleware('permission:invoice.print');
});

require __DIR__.'/auth.php';

// Label print jobs fetched by the Windows print app: signed links, no login.
// Signed over the path only, so a proxy changing scheme/host/port can't break them.
Route::middleware('signed:relative')->group(function () {
    Route::get('/labels/jobs/{job}', [LabelJobController::class, 'manifest'])->whereUuid('job')->name('labels.jobs.manifest');
    Route::get('/labels/jobs/{job}/pages/{page}', [LabelJobController::class, 'page'])->whereUuid('job')->whereNumber('page')->name('labels.jobs.page');
});
