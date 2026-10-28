<?php

use App\Filament\Resources\PurchaseInvoices\Pages\EditPurchaseInvoice;
use App\Filament\Resources\PurchaseInvoices\RelationManagers\ItemsRelationManager as PurchaseItemsRelationManager;
use App\Filament\Resources\ReturnPurchaseInvoices\Pages\EditReturnPurchaseInvoice;
use App\Filament\Resources\ReturnPurchaseInvoices\RelationManagers\ItemsRelationManager as ReturnPurchaseItemsRelationManager;
use App\Filament\Resources\Stocktakings\Pages\EditStocktaking;
use App\Filament\Resources\Stocktakings\RelationManagers\ItemsRelationManager as StocktakingItemsRelationManager;
use App\Filament\Resources\Wastes\Pages\EditWaste;
use App\Filament\Resources\Wastes\RelationManagers\ItemsRelationManager as WasteItemsRelationManager;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\ReturnPurchaseInvoice;
use App\Models\ReturnPurchaseInvoiceItem;
use App\Models\Stocktaking;
use App\Models\StocktakingItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Waste;
use App\Models\WastedItem;
use App\Services\InvoicePrintService;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->admin = User::factory()->create([
        'role' => 'admin',
    ]);
    $this->actingAs($this->admin);
});

it('correctly formats product units in Product model', function () {
    expect(Product::formatUnit('piece'))->toBe('قطعة')
        ->and(Product::formatUnit('kg'))->toBe('كيلوجرام')
        ->and(Product::formatUnit('gram'))->toBe('جرام')
        ->and(Product::formatUnit('liter'))->toBe('لتر')
        ->and(Product::formatUnit('packet'))->toBe('باكت')
        ->and(Product::formatUnit('box'))->toBe('صندوق')
        ->and(Product::formatUnit('طبق'))->toBe('طبق')
        ->and(Product::formatUnit(null))->toBe('-');
});

it('displays product unit in purchase invoice relation manager', function () {
    $product = Product::factory()->create([
        'unit' => 'piece',
    ]);
    $invoice = PurchaseInvoice::factory()->create();

    $item = PurchaseInvoiceItem::factory()->create([
        'purchase_invoice_id' => $invoice->id,
        'product_id' => $product->id,
        'quantity' => 5,
        'price' => 20,
    ]);

    livewire(PurchaseItemsRelationManager::class, [
        'ownerRecord' => $invoice,
        'pageClass' => EditPurchaseInvoice::class,
    ])
        ->assertCanSeeTableRecords([$item])
        ->assertSee('قطعة');
});

it('displays product unit in return purchase invoice relation manager', function () {
    $product = Product::factory()->create([
        'unit' => 'kg',
    ]);
    $supplier = Supplier::factory()->create();
    $invoice = ReturnPurchaseInvoice::factory()->create([
        'supplier_id' => $supplier->id,
    ]);

    $item = ReturnPurchaseInvoiceItem::factory()->create([
        'return_purchase_invoice_id' => $invoice->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'price' => 50,
    ]);

    livewire(ReturnPurchaseItemsRelationManager::class, [
        'ownerRecord' => $invoice,
        'pageClass' => EditReturnPurchaseInvoice::class,
    ])
        ->assertCanSeeTableRecords([$item])
        ->assertSee('كيلوجرام');
});

it('displays product unit in stocktaking relation manager', function () {
    $product = Product::factory()->create([
        'unit' => 'box',
    ]);
    $stocktaking = Stocktaking::factory()->create();

    $item = StocktakingItem::factory()->create([
        'stocktaking_id' => $stocktaking->id,
        'product_id' => $product->id,
        'stock_quantity' => 10,
        'real_quantity' => 8,
        'price' => 30,
    ]);

    livewire(StocktakingItemsRelationManager::class, [
        'ownerRecord' => $stocktaking,
        'pageClass' => EditStocktaking::class,
    ])
        ->assertCanSeeTableRecords([$item])
        ->assertSee('صندوق');
});

it('displays product unit in waste relation manager', function () {
    $product = Product::factory()->create([
        'unit' => 'liter',
    ]);
    $waste = Waste::factory()->create();

    $item = WastedItem::factory()->create([
        'waste_id' => $waste->id,
        'product_id' => $product->id,
        'quantity' => 3,
        'price' => 15,
        'total' => 45,
    ]);

    livewire(WasteItemsRelationManager::class, [
        'ownerRecord' => $waste,
        'pageClass' => EditWaste::class,
    ])
        ->assertCanSeeTableRecords([$item])
        ->assertSee('لتر');
});

it('includes unit in InvoicePrintService data for all invoice types', function () {
    $supplier = Supplier::factory()->create();
    $product = Product::factory()->create([
        'unit' => 'piece',
    ]);

    $purchase = PurchaseInvoice::factory()->create([
        'supplier_id' => $supplier->id,
    ]);
    PurchaseInvoiceItem::factory()->create([
        'purchase_invoice_id' => $purchase->id,
        'product_id' => $product->id,
        'quantity' => 10,
        'price' => 5,
    ]);

    $returnPurchase = ReturnPurchaseInvoice::factory()->create([
        'supplier_id' => $supplier->id,
    ]);
    ReturnPurchaseInvoiceItem::factory()->create([
        'return_purchase_invoice_id' => $returnPurchase->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'price' => 5,
    ]);

    $stocktaking = Stocktaking::factory()->create();
    StocktakingItem::factory()->create([
        'stocktaking_id' => $stocktaking->id,
        'product_id' => $product->id,
        'stock_quantity' => 5,
        'real_quantity' => 4,
        'price' => 5,
    ]);

    $waste = Waste::factory()->create();
    WastedItem::factory()->create([
        'waste_id' => $waste->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'price' => 5,
        'total' => 5,
    ]);

    $service = app(InvoicePrintService::class);

    $purchaseData = $service->getPrintData('purchase_invoice', $purchase->id);
    expect($purchaseData['items'][0]['unit'])->toBe('قطعة');

    $returnData = $service->getPrintData('return_purchase_invoice', $returnPurchase->id);
    expect($returnData['items'][0]['unit'])->toBe('قطعة');

    $stockData = $service->getPrintData('stocktaking', $stocktaking->id);
    expect($stockData['items'][0]['unit'])->toBe('قطعة');

    $wasteData = $service->getPrintData('waste', $waste->id);
    expect($wasteData['items'][0]['unit'])->toBe('قطعة');
});

it('displays product unit in purchase invoice edit form repeater', function () {
    $supplier = Supplier::factory()->create();
    $product = Product::factory()->create(['unit' => 'piece']);
    $invoice = PurchaseInvoice::factory()->create(['supplier_id' => $supplier->id]);
    PurchaseInvoiceItem::factory()->create([
        'purchase_invoice_id' => $invoice->id,
        'product_id' => $product->id,
        'quantity' => 5,
        'price' => 10,
    ]);

    livewire(EditPurchaseInvoice::class, ['record' => $invoice->id])
        ->assertSuccessful()
        ->assertSee('الوحدة');
});

it('displays product unit in return purchase invoice edit form repeater', function () {
    $supplier = Supplier::factory()->create();
    $product = Product::factory()->create(['unit' => 'kg']);
    $invoice = ReturnPurchaseInvoice::factory()->create(['supplier_id' => $supplier->id]);
    ReturnPurchaseInvoiceItem::factory()->create([
        'return_purchase_invoice_id' => $invoice->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'price' => 25,
    ]);

    livewire(EditReturnPurchaseInvoice::class, ['record' => $invoice->id])
        ->assertSuccessful()
        ->assertSee('الوحدة');
});

it('displays product unit in stocktaking edit form repeater', function () {
    $product = Product::factory()->create(['unit' => 'box']);
    $stocktaking = Stocktaking::factory()->create();
    StocktakingItem::factory()->create([
        'stocktaking_id' => $stocktaking->id,
        'product_id' => $product->id,
        'stock_quantity' => 10,
        'real_quantity' => 10,
        'price' => 50,
    ]);

    livewire(EditStocktaking::class, ['record' => $stocktaking->id])
        ->assertSuccessful()
        ->assertSee('الوحدة');
});

it('displays product unit in waste edit form repeater', function () {
    $product = Product::factory()->create(['unit' => 'liter']);
    $waste = Waste::factory()->create();
    WastedItem::factory()->create([
        'waste_id' => $waste->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'price' => 30,
        'total' => 30,
    ]);

    livewire(EditWaste::class, ['record' => $waste->id])
        ->assertSuccessful()
        ->assertSee('الوحدة');
});
