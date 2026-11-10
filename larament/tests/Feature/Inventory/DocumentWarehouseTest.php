<?php

use App\Enums\ProductType;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\ReturnPurchaseInvoice;
use App\Models\ReturnPurchaseInvoiceItem;
use App\Models\Stocktaking;
use App\Models\StocktakingItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Waste;
use App\Models\WastedItem;
use App\Services\PurchaseService;
use App\Services\StockService;
use App\Services\StocktakingService;
use App\Services\WasteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function putStock(Product $product, Warehouse $warehouse, float $quantity): void
{
    InventoryItem::updateOrCreate(
        ['product_id' => $product->id, 'warehouse_id' => $warehouse->id],
        ['quantity' => $quantity],
    );
}

it('closing a purchase invoice into Main adds stock to Main only', function () {
    $main = Warehouse::default();
    $grill = Warehouse::factory()->create(['name' => 'الشواية']);
    $product = Product::factory()->create(['type' => ProductType::Consumable, 'cost' => 10]);
    openInventoryDay($product);
    putStock($product, $grill, 5);

    $invoice = PurchaseInvoice::factory()->create([
        'warehouse_id' => $main->id,
        'closed_at' => null,
    ]);
    PurchaseInvoiceItem::create([
        'purchase_invoice_id' => $invoice->id,
        'product_id' => $product->id,
        'quantity' => 4,
        'price' => 10,
        'total' => 40,
    ]);

    expect(app(PurchaseService::class)->closePurchaseInvoice($invoice))->toBeTrue();

    $stock = app(StockService::class);
    expect($stock->getCurrentStock($product->id, $main->id))->toBe(4.0)
        ->and($stock->getCurrentStock($product->id, $grill->id))->toBe(5.0);
});

it('closing a purchase return removes from the invoice warehouse', function () {
    $main = Warehouse::default();
    $grill = Warehouse::factory()->create(['name' => 'الشواية']);
    $product = Product::factory()->create(['type' => ProductType::Consumable]);
    openInventoryDay($product);
    putStock($product, $main, 8);
    putStock($product, $grill, 10);

    $invoice = ReturnPurchaseInvoice::factory()->create([
        'user_id' => User::factory(),
        'supplier_id' => Supplier::factory(),
        'warehouse_id' => $grill->id,
        'closed_at' => null,
    ]);
    ReturnPurchaseInvoiceItem::create([
        'return_purchase_invoice_id' => $invoice->id,
        'product_id' => $product->id,
        'quantity' => 3,
        'price' => 10,
        'total' => 30,
    ]);

    expect(app(PurchaseService::class)->closeReturnPurchaseInvoice($invoice))->toBeTrue();

    $stock = app(StockService::class);
    expect($stock->getCurrentStock($product->id, $grill->id))->toBe(7.0)
        ->and($stock->getCurrentStock($product->id, $main->id))->toBe(8.0);
});

it('closing a waste removes from its warehouse', function () {
    $main = Warehouse::default();
    $grill = Warehouse::factory()->create(['name' => 'الشواية']);
    $product = Product::factory()->create(['type' => ProductType::Consumable, 'cost' => 12]);
    openInventoryDay($product);
    putStock($product, $main, 9);
    putStock($product, $grill, 6);

    $waste = Waste::factory()->create([
        'user_id' => User::factory(),
        'warehouse_id' => $grill->id,
        'closed_at' => null,
    ]);
    WastedItem::create([
        'waste_id' => $waste->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'price' => 12,
        'total' => 24,
    ]);

    app(WasteService::class)->closeWaste($waste);

    $stock = app(StockService::class);
    expect($stock->getCurrentStock($product->id, $grill->id))->toBe(4.0)
        ->and($stock->getCurrentStock($product->id, $main->id))->toBe(9.0);
});

it('a stocktaking of Grill compares and adjusts Grill balances only', function () {
    $main = Warehouse::default();
    $grill = Warehouse::factory()->create(['name' => 'الشواية']);
    $product = Product::factory()->create([
        'type' => ProductType::Consumable,
        'name' => 'دجاج',
        'price' => 10,
        'cost' => 5,
    ]);
    openInventoryDay($product);
    putStock($product, $main, 30);
    putStock($product, $grill, 4);

    $stocktaking = Stocktaking::factory()->create([
        'user_id' => User::factory(),
        'warehouse_id' => $grill->id,
        'closed_at' => null,
    ]);
    StocktakingItem::create([
        'stocktaking_id' => $stocktaking->id,
        'product_id' => $product->id,
        'stock_quantity' => 4,
        'real_quantity' => 2,
        'price' => 10,
        'total' => -20,
    ]);

    expect(app(StocktakingService::class)->getChangedItemsSinceCount($stocktaking))->toBeEmpty();

    app(StocktakingService::class)->closeStocktaking($stocktaking);

    $stock = app(StockService::class);
    expect($stock->getCurrentStock($product->id, $grill->id))->toBe(2.0)
        ->and($stock->getCurrentStock($product->id, $main->id))->toBe(30.0);
});

it('reverses the document warehouse migration', function () {
    $migration = require database_path('migrations/2026_11_10_210000_add_warehouse_to_stock_documents.php');

    try {
        $migration->down();

        expect(Schema::hasColumn('purchase_invoices', 'warehouse_id'))->toBeFalse()
            ->and(Schema::hasColumn('return_purchase_invoices', 'warehouse_id'))->toBeFalse()
            ->and(Schema::hasColumn('wastes', 'warehouse_id'))->toBeFalse()
            ->and(Schema::hasColumn('stocktakings', 'warehouse_id'))->toBeFalse();
    } finally {
        if (! Schema::hasColumn('purchase_invoices', 'warehouse_id')) {
            $migration->up();
        }
    }
});
