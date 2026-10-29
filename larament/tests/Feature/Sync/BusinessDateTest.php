<?php

use App\Models\InventoryItem;
use App\Models\InventoryItemMovementDaily;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\ReturnPurchaseInvoice;
use App\Models\ReturnPurchaseInvoiceItem;
use App\Models\Stocktaking;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Waste;
use App\Services\PurchaseService;
use App\Services\ShiftService;
use App\Services\StocktakingService;
use App\Services\WasteService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carbon::setTestNow();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('stamps the open business day on a shift started after midnight', function () {
    InventoryItemMovementDaily::factory()->create([
        'date' => '2026-10-29',
        'closed_at' => null,
    ]);

    Carbon::setTestNow('2026-10-30 00:30:00');

    $user = User::factory()->create();
    $shift = app(ShiftService::class)->startShift(500, $user);

    expect($shift->business_date)->not->toBeNull()
        ->and($shift->business_date->toDateString())->toBe('2026-10-29');
});

it('stamps business_date when closing waste, purchase, purchase return and stocktaking', function () {
    InventoryItemMovementDaily::factory()->create([
        'date' => '2026-10-29',
        'closed_at' => null,
    ]);

    Carbon::setTestNow('2026-10-30 00:30:00');

    $user = User::factory()->create();
    $supplier = Supplier::factory()->create();

    // 1. Waste
    $waste = Waste::factory()->create([
        'user_id' => $user->id,
        'closed_at' => null,
        'business_date' => null,
    ]);
    app(WasteService::class)->closeWaste($waste);
    expect($waste->fresh()->business_date)->not->toBeNull()
        ->and($waste->fresh()->business_date->toDateString())->toBe('2026-10-29');

    // 2. Purchase Invoice
    $purchaseInvoice = PurchaseInvoice::factory()->create([
        'user_id' => $user->id,
        'supplier_id' => $supplier->id,
        'closed_at' => null,
        'business_date' => null,
    ]);
    $product1 = Product::factory()->create();
    PurchaseInvoiceItem::create([
        'purchase_invoice_id' => $purchaseInvoice->id,
        'product_id' => $product1->id,
        'quantity' => 5,
        'price' => 80.00,
        'total' => 400.00,
    ]);
    app(PurchaseService::class)->closePurchaseInvoice($purchaseInvoice);
    expect($purchaseInvoice->fresh()->business_date)->not->toBeNull()
        ->and($purchaseInvoice->fresh()->business_date->toDateString())->toBe('2026-10-29');

    // 3. Return Purchase Invoice
    $returnInvoice = ReturnPurchaseInvoice::factory()->create([
        'user_id' => $user->id,
        'supplier_id' => $supplier->id,
        'closed_at' => null,
        'business_date' => null,
    ]);
    $product2 = Product::factory()->create();
    InventoryItem::create([
        'product_id' => $product2->id,
        'quantity' => 50,
    ]);
    ReturnPurchaseInvoiceItem::create([
        'return_purchase_invoice_id' => $returnInvoice->id,
        'product_id' => $product2->id,
        'quantity' => 2,
        'price' => 40.00,
        'total' => 80.00,
    ]);
    app(PurchaseService::class)->closeReturnPurchaseInvoice($returnInvoice);
    expect($returnInvoice->fresh()->business_date)->not->toBeNull()
        ->and($returnInvoice->fresh()->business_date->toDateString())->toBe('2026-10-29');

    // 4. Stocktaking
    $stocktaking = Stocktaking::factory()->create([
        'user_id' => $user->id,
        'closed_at' => null,
        'business_date' => null,
    ]);
    app(StocktakingService::class)->closeStocktaking($stocktaking);
    expect($stocktaking->fresh()->business_date)->not->toBeNull()
        ->and($stocktaking->fresh()->business_date->toDateString())->toBe('2026-10-29');
});

it('backfills business_date from start_at / closed_at for existing rows', function () {
    $user = User::factory()->create();
    $supplier = Supplier::factory()->create();

    $shiftId = DB::table('shifts')->insertGetId([
        'user_id' => $user->id,
        'start_at' => '2026-10-15 14:00:00',
        'start_cash' => 100,
        'closed' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $wasteId = DB::table('wastes')->insertGetId([
        'user_id' => $user->id,
        'total' => 50,
        'closed_at' => '2026-10-16 18:30:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $purchaseId = DB::table('purchase_invoices')->insertGetId([
        'user_id' => $user->id,
        'supplier_id' => $supplier->id,
        'total' => 200,
        'closed_at' => '2026-10-17 11:15:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $returnId = DB::table('return_purchase_invoices')->insertGetId([
        'user_id' => $user->id,
        'supplier_id' => $supplier->id,
        'total' => 80,
        'closed_at' => '2026-10-18 09:45:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $stocktakingId = DB::table('stocktakings')->insertGetId([
        'user_id' => $user->id,
        'total' => 0,
        'closed_at' => '2026-10-19 22:00:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    /** @var object $migration */
    $migration = include database_path('migrations/2026_11_01_000002_add_business_date_columns.php');
    $migration->backfill();

    expect(DB::table('shifts')->where('id', $shiftId)->value('business_date'))->toBe('2026-10-15')
        ->and(DB::table('wastes')->where('id', $wasteId)->value('business_date'))->toBe('2026-10-16')
        ->and(DB::table('purchase_invoices')->where('id', $purchaseId)->value('business_date'))->toBe('2026-10-17')
        ->and(DB::table('return_purchase_invoices')->where('id', $returnId)->value('business_date'))->toBe('2026-10-18')
        ->and(DB::table('stocktakings')->where('id', $stocktakingId)->value('business_date'))->toBe('2026-10-19');
});
