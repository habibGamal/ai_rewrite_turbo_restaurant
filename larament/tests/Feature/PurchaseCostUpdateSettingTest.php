<?php

use App\Enums\SettingKey;
use App\Models\InventoryItemMovementDaily;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('updates product cost on purchase invoice close by default', function () {
    $product = Product::factory()->create([
        'cost' => 50.00,
        'price' => 100.00,
    ]);

    // Open a day so shouldDayBeOpen() passes
    InventoryItemMovementDaily::create([
        'product_id' => $product->id,
        'date' => now()->toDateString(),
        'closed_at' => null,
        'start_quantity' => 0,
        'end_quantity' => 0,
    ]);

    $settingsService = app(SettingsService::class);
    expect($settingsService->shouldUpdateProductCostOnPurchase())->toBeTrue();

    $supplier = Supplier::factory()->create();
    $user = User::factory()->create();

    $invoice = PurchaseInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'user_id' => $user->id,
        'closed_at' => null,
    ]);

    PurchaseInvoiceItem::create([
        'purchase_invoice_id' => $invoice->id,
        'product_id' => $product->id,
        'quantity' => 5,
        'price' => 80.00,
        'total' => 400.00,
    ]);

    $purchaseService = app(PurchaseService::class);
    $result = $purchaseService->closePurchaseInvoice($invoice);

    expect($result)->toBeTrue();
    expect((float) $product->fresh()->cost)->toBe(80.00);
    expect($invoice->fresh()->closed_at)->not->toBeNull();
});

it('prevents update of product cost on purchase invoice close when setting is disabled', function () {
    $product = Product::factory()->create([
        'cost' => 50.00,
        'price' => 100.00,
    ]);

    // Open a day so shouldDayBeOpen() passes
    InventoryItemMovementDaily::create([
        'product_id' => $product->id,
        'date' => now()->toDateString(),
        'closed_at' => null,
        'start_quantity' => 0,
        'end_quantity' => 0,
    ]);

    $settingsService = app(SettingsService::class);
    $settingsService->set(SettingKey::UPDATE_PRODUCT_COST_ON_PURCHASE->value, 'false');

    $supplier = Supplier::factory()->create();
    $user = User::factory()->create();

    $invoice = PurchaseInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'user_id' => $user->id,
        'closed_at' => null,
    ]);

    PurchaseInvoiceItem::create([
        'purchase_invoice_id' => $invoice->id,
        'product_id' => $product->id,
        'quantity' => 5,
        'price' => 80.00,
        'total' => 400.00,
    ]);

    $purchaseService = app(PurchaseService::class);
    $result = $purchaseService->closePurchaseInvoice($invoice);

    expect($result)->toBeTrue();
    // Cost must remain the original 50.00
    expect((float) $product->fresh()->cost)->toBe(50.00);
    // Invoice is still closed successfully
    expect($invoice->fresh()->closed_at)->not->toBeNull();
});
