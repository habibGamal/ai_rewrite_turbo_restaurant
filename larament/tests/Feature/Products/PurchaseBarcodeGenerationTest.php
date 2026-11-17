<?php

use App\Enums\OptionalFeature;
use App\Enums\SettingKey;
use App\Exceptions\OrderException;
use App\Filament\Actions\Forms\GeneratePurchaseBarcodesAction;
use App\Filament\Pages\Settings;
use App\Filament\Resources\PurchaseInvoices\Pages\CreatePurchaseInvoice;
use App\Filament\Resources\PurchaseInvoices\PurchaseInvoiceResource;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\User;
use App\Services\FeatureService;
use App\Services\Products\SequentialBarcodeService;
use App\Services\SettingsService;
use Filament\Actions\Testing\TestAction;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

function purchaseProduct(?string $barcode = null): Product
{
    return Product::factory()->create(['type' => 'raw_material', 'barcode' => $barcode, 'cost' => 10, 'price' => 20]);
}

beforeEach(function () {
    app(FeatureService::class)->enable(OptionalFeature::PURCHASE_BARCODES);
});

it('numbers barcodes from 0001 by default and moves the counter on', function () {
    $a = purchaseProduct();
    $b = purchaseProduct();

    $assigned = app(SequentialBarcodeService::class)->assignMissing([$a->id, $b->id]);

    expect($assigned)->toBe([$a->id => '0001', $b->id => '0002'])
        ->and($a->fresh()->barcode)->toBe('0001')
        ->and(ProductBarcode::where('barcode', '0002')->value('product_id'))->toBe($b->id)
        ->and(app(SettingsService::class)->get(SettingKey::PURCHASE_BARCODE_NEXT_NUMBER->value))->toBe('3');
});

it('uses the configured prefix, digit count and start number', function () {
    $settings = app(SettingsService::class);
    $settings->set(SettingKey::PURCHASE_BARCODE_PREFIX->value, 'CL');
    $settings->set(SettingKey::PURCHASE_BARCODE_DIGITS->value, '6');
    $settings->set(SettingKey::PURCHASE_BARCODE_NEXT_NUMBER->value, '250');
    $product = purchaseProduct();

    expect(app(SequentialBarcodeService::class)->preview())->toBe('CL000250');

    app(SequentialBarcodeService::class)->assignMissing([$product->id]);

    expect($product->fresh()->barcode)->toBe('CL000250');
});

it('skips numbers already taken and leaves products that have a barcode alone', function () {
    purchaseProduct('0001');
    $extra = purchaseProduct('5000');
    ProductBarcode::create(['product_id' => $extra->id, 'barcode' => '0002']);
    $hasOne = purchaseProduct('MFR-123');
    $missing = purchaseProduct();

    $assigned = app(SequentialBarcodeService::class)->assignMissing([$hasOne->id, $missing->id]);

    expect($assigned)->toBe([$missing->id => '0003'])
        ->and($hasOne->fresh()->barcode)->toBe('MFR-123');
});

it('never produces a code the POS would read as a scale label', function () {
    $settings = app(SettingsService::class);
    $settings->set(SettingKey::SCALE_BARCODE_PREFIX->value, '23');
    // 23 + 11 digits = 13-digit codes starting with the scale prefix: every number collides.
    $settings->set(SettingKey::PURCHASE_BARCODE_PREFIX->value, '23');
    $settings->set(SettingKey::PURCHASE_BARCODE_DIGITS->value, '11');
    $product = purchaseProduct();

    expect(fn () => app(SequentialBarcodeService::class)->assignMissing([$product->id]))
        ->toThrow(RuntimeException::class);
    expect($product->fresh()->barcode)->toBeNull();
});

it('refuses to generate while the vendor has the feature off', function () {
    app(FeatureService::class)->disable(OptionalFeature::PURCHASE_BARCODES);

    app(SequentialBarcodeService::class)->assignMissing([purchaseProduct()->id]);
})->throws(OrderException::class);

it('generates barcodes for the invoice lines from the purchase invoice form', function () {
    actingAs(User::factory()->admin()->create());
    $withCode = purchaseProduct('EAN-1');
    $first = purchaseProduct();
    $second = purchaseProduct();

    $page = livewire(CreatePurchaseInvoice::class);
    $page->set('data.items', [
        ['product_id' => $first->id, 'quantity' => 3, 'price' => 10],
        ['product_id' => $withCode->id, 'quantity' => 1, 'price' => 10],
        ['product_id' => $second->id, 'quantity' => 2, 'price' => 10],
    ]);

    $page->callAction(TestAction::make('generatePurchaseBarcodes')->schemaComponent('purchaseImportActions', 'form'))
        ->assertHasNoActionErrors();

    $items = collect($page->get('data.items'))->keyBy('product_id');
    expect($first->fresh()->barcode)->toBe('0001')
        ->and($second->fresh()->barcode)->toBe('0002')
        ->and($withCode->fresh()->barcode)->toBe('EAN-1')
        ->and($items[$first->id]['barcode'])->toBe('0001')
        ->and($items[$second->id]['barcode'])->toBe('0002');
});

it('hides the button and the barcode column while the feature is off', function () {
    actingAs(User::factory()->admin()->create());
    expect(GeneratePurchaseBarcodesAction::make()->isVisible())->toBeTrue();

    app(FeatureService::class)->disable(OptionalFeature::PURCHASE_BARCODES);

    expect(GeneratePurchaseBarcodesAction::make()->isVisible())->toBeFalse()
        ->and(PurchaseInvoiceResource::showsBarcodes())->toBeFalse();
});

it('hides the button from users who cannot edit products', function () {
    $role = Role::firstOrCreate(['name' => 'purchasing', 'guard_name' => 'web']);
    $role->givePermissionTo(Permission::firstOrCreate(['name' => 'create_purchase_invoices', 'guard_name' => 'web']));
    $user = User::factory()->create();
    $user->syncRoles([$role]);
    actingAs($user->fresh());

    expect(GeneratePurchaseBarcodesAction::make()->isVisible())->toBeFalse();
});

it('carries the product barcode onto lines added to the invoice', function () {
    actingAs(User::factory()->admin()->create());
    $product = purchaseProduct('0042');

    $page = livewire(CreatePurchaseInvoice::class)
        ->set('data.product_selector', $product->id);

    expect(collect($page->get('data.items'))->first()['barcode'])->toBe('0042');
});

it('shows and saves the series settings only while the feature is on', function () {
    actingAs(User::factory()->admin()->create());

    livewire(Settings::class)
        ->assertFormFieldIsVisible(SettingKey::PURCHASE_BARCODE_NEXT_NUMBER->value)
        ->fillForm([
            SettingKey::PURCHASE_BARCODE_PREFIX->value => 'A',
            SettingKey::PURCHASE_BARCODE_DIGITS->value => 5,
            SettingKey::PURCHASE_BARCODE_NEXT_NUMBER->value => 77,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(SequentialBarcodeService::class)->preview())->toBe('A00077');

    app(FeatureService::class)->disable(OptionalFeature::PURCHASE_BARCODES);
    livewire(Settings::class)
        ->assertFormFieldIsHidden(SettingKey::PURCHASE_BARCODE_NEXT_NUMBER->value);
});
