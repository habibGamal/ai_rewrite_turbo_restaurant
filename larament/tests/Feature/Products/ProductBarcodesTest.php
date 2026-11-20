<?php

use App\Enums\ProductType;
use App\Enums\SettingKey;
use App\Filament\Resources\ConsumableProducts\Pages\EditConsumableProduct;
use App\Filament\Resources\PurchaseInvoices\Pages\CreatePurchaseInvoice;
use App\Filament\Resources\Stocktakings\Pages\CreateStocktaking;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use App\Services\Products\ProductBarcodeBackfillService;
use App\Services\Products\ProductLookupResult;
use App\Services\Products\ProductLookupService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

function barcodeProduct(array $attributes = []): Product
{
    return Product::factory()->create(array_merge([
        'type' => ProductType::Consumable,
        'legacy' => false,
        'price' => 10,
        'cost' => 6,
    ], $attributes));
}

function setScalePrefix(string $prefix): void
{
    Setting::updateOrCreate(['key' => SettingKey::SCALE_BARCODE_PREFIX->value], ['value' => $prefix]);
    Cache::forget('settings.all');
}

describe('primary barcode sync', function () {
    it('creates a primary barcode row when a product has a barcode', function () {
        $product = barcodeProduct(['barcode' => ' 6221000000017 ']);

        $row = ProductBarcode::where('product_id', $product->id)->sole();
        expect($row->barcode)->toBe('6221000000017')
            ->and($row->is_primary)->toBeTrue()
            ->and((float) $row->pack_quantity)->toBe(1.0);
    });

    it('moves the primary row when the barcode changes and removes it when cleared', function () {
        $product = barcodeProduct(['barcode' => '111']);

        $product->update(['barcode' => '222']);
        expect(ProductBarcode::where('product_id', $product->id)->pluck('barcode')->all())->toBe(['222']);

        $product->update(['barcode' => null]);
        expect(ProductBarcode::where('product_id', $product->id)->count())->toBe(0);
    });

    it('promotes an extra barcode when it becomes the primary one', function () {
        $product = barcodeProduct(['barcode' => '111']);
        $product->barcodes()->create(['barcode' => '999', 'pack_quantity' => 6]);

        $product->update(['barcode' => '999']);

        $rows = ProductBarcode::where('product_id', $product->id)->get();
        expect($rows)->toHaveCount(1)
            ->and($rows->first()->barcode)->toBe('999')
            ->and($rows->first()->is_primary)->toBeTrue()
            ->and((float) $rows->first()->pack_quantity)->toBe(1.0);
    });

    it('does not steal a barcode that belongs to another product', function () {
        $owner = barcodeProduct(['barcode' => '555']);
        $other = barcodeProduct(['barcode' => '666']);

        $other->update(['barcode' => '555']);

        expect(ProductBarcode::where('barcode', '555')->value('product_id'))->toBe($owner->id)
            ->and(ProductBarcode::where('barcode', '666')->value('product_id'))->toBe($other->id);
    });

    it('deletes barcodes with the product', function () {
        $product = barcodeProduct(['barcode' => '777']);
        $product->barcodes()->create(['barcode' => '778']);

        $product->delete();

        expect(ProductBarcode::count())->toBe(0);
    });
});

describe('backfill', function () {
    it('copies barcodes, keeps the oldest product on duplicates and writes a report', function () {
        $first = barcodeProduct();
        $second = barcodeProduct();
        $third = barcodeProduct();
        $blank = barcodeProduct();
        // Write products.barcode directly, as the data looked before this table existed.
        DB::table('products')->where('id', $first->id)->update(['barcode' => '100']);
        DB::table('products')->where('id', $second->id)->update(['barcode' => '100']);
        DB::table('products')->where('id', $third->id)->update(['barcode' => ' 300 ']);
        DB::table('products')->where('id', $blank->id)->update(['barcode' => '  ']);
        DB::table('product_barcodes')->delete();

        $result = app(ProductBarcodeBackfillService::class)->run();

        expect($result['inserted'])->toBe(2)
            ->and($result['duplicates'])->toHaveCount(1)
            ->and($result['duplicates'][0])->toMatchArray([
                'barcode' => '100',
                'kept_product_id' => $first->id,
                'product_id' => $second->id,
            ])
            ->and(ProductBarcode::where('barcode', '100')->value('product_id'))->toBe($first->id)
            ->and(ProductBarcode::where('barcode', '300')->value('product_id'))->toBe($third->id)
            ->and($second->fresh()->barcode)->toBe('100');

        expect($result['report_path'])->not->toBeNull()
            ->and(file_get_contents($result['report_path']))->toContain('100');
        @unlink($result['report_path']);

        // Running again adds nothing.
        $again = app(ProductBarcodeBackfillService::class)->run(writeReport: false);
        expect($again['inserted'])->toBe(0)
            ->and($again['skipped_existing'])->toBe(2);
    });
});

describe('ProductLookupService', function () {
    beforeEach(function () {
        setScalePrefix('23');
        $this->lookup = app(ProductLookupService::class);
    });

    it('finds a product by its primary barcode', function () {
        $product = barcodeProduct(['barcode' => '6221000000017']);

        $result = $this->lookup->lookup('6221000000017');

        expect($result)->toBeInstanceOf(ProductLookupResult::class)
            ->and($result->product->id)->toBe($product->id)
            ->and($result->quantity)->toBe(1.0)
            ->and($result->source)->toBe('barcode');
    });

    it('finds a product by an extra barcode and uses its pack quantity', function () {
        $product = barcodeProduct(['barcode' => '6221000000017']);
        $product->barcodes()->create(['barcode' => '16221000000014', 'pack_quantity' => 12]);

        $result = $this->lookup->lookup('16221000000014');

        expect($result->product->id)->toBe($product->id)
            ->and($result->quantity)->toBe(12.0)
            ->and($result->packQuantity)->toBe(12.0);
    });

    it('reads weight from a scale label', function () {
        $product = barcodeProduct(['barcode' => '2300001', 'unit' => 'kg']);

        $result = $this->lookup->lookup('2300001012498');

        expect($result->product->id)->toBe($product->id)
            ->and($result->source)->toBe('scale')
            ->and($result->quantity)->toBe(1.249)
            ->and($result->weightKg)->toBe(1.249);
    });

    it('prefers an exact barcode over the scale reading when the code starts with the prefix', function () {
        barcodeProduct(['barcode' => '2300001']);
        $exact = barcodeProduct(['barcode' => '2300001012498']);

        $result = $this->lookup->lookup('2300001012498');

        expect($result->product->id)->toBe($exact->id)
            ->and($result->source)->toBe('barcode');
    });

    it('follows the scale prefix setting', function () {
        setScalePrefix('21');
        $product = barcodeProduct(['barcode' => '2100005']);

        expect($this->lookup->lookup('2100005005003')?->product->id)->toBe($product->id)
            ->and(app(ProductLookupService::class)->lookup('2300005005003'))->toBeNull();
    });

    it('filters by product type', function () {
        barcodeProduct(['barcode' => '444', 'type' => ProductType::RawMaterial]);

        expect($this->lookup->lookup('444', [ProductType::Consumable]))->toBeNull()
            ->and($this->lookup->lookup('444', [ProductType::RawMaterial]))->not->toBeNull();
    });

    it('returns null for unknown or empty codes', function () {
        expect($this->lookup->lookup('000'))->toBeNull()
            ->and($this->lookup->lookup('   '))->toBeNull()
            ->and($this->lookup->lookup(null))->toBeNull();
    });
});

describe('POS lookup endpoint', function () {
    beforeEach(function () {
        $this->user = User::factory()->admin()->create();
        Shift::factory()->create(['user_id' => $this->user->id, 'end_at' => null, 'closed' => false]);
        actingAs($this->user);
    });

    it('returns the product and quantity for a known code', function () {
        $product = barcodeProduct(['barcode' => '8001']);
        $product->barcodes()->create(['barcode' => '8002', 'pack_quantity' => 6]);

        $this->getJson(route('products.lookup', ['code' => '8002']))
            ->assertOk()
            ->assertJson(['found' => true, 'product_id' => $product->id, 'quantity' => 6]);
    });

    it('answers 404 for an unknown code and for raw materials', function () {
        barcodeProduct(['barcode' => '8003', 'type' => ProductType::RawMaterial]);

        $this->getJson(route('products.lookup', ['code' => 'nope']))->assertNotFound()->assertJson(['found' => false]);
        $this->getJson(route('products.lookup', ['code' => '8003']))->assertNotFound();
    });
});

describe('back office scanning', function () {
    beforeEach(function () {
        actingAs(User::factory()->admin()->create());
    });

    it('adds a scanned case to a purchase invoice and adds more on a second scan', function () {
        $product = barcodeProduct(['barcode' => '9001', 'cost' => 5]);
        $product->barcodes()->create(['barcode' => '9002', 'pack_quantity' => 12]);

        $component = livewire(CreatePurchaseInvoice::class)
            ->fillForm(['barcode_scan' => '9002'])
            ->fillForm(['barcode_scan' => '9001']);

        $items = array_values($component->get('data.items'));
        expect($items)->toHaveCount(1)
            ->and($items[0]['product_id'])->toBe($product->id)
            ->and((float) $items[0]['quantity'])->toBe(13.0)
            ->and((float) $component->get('data.total'))->toBe(65.0)
            ->and($component->get('data.barcode_scan'))->toBeNull();
    });

    it('counts scanned items in a stocktaking', function () {
        $product = barcodeProduct(['barcode' => '9101', 'type' => ProductType::RawMaterial, 'cost' => 2]);

        $component = livewire(CreateStocktaking::class)
            ->fillForm(['barcode_scan' => '9101'])
            ->fillForm(['barcode_scan' => '9101']);

        $items = array_values($component->get('data.items'));
        expect($items)->toHaveCount(1)
            ->and($items[0]['product_id'])->toBe($product->id)
            ->and((float) $items[0]['real_quantity'])->toBe(2.0);
    });

    it('rejects a primary barcode that another product already uses', function () {
        barcodeProduct(['barcode' => '9201']);
        $product = barcodeProduct(['barcode' => '9202', 'category_id' => Category::factory()]);

        livewire(EditConsumableProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['barcode' => '9201'])
            ->call('save')
            ->assertHasFormErrors(['barcode']);
    });

    it('saves extra barcodes from the product form and rejects one owned by another product', function () {
        barcodeProduct(['barcode' => '9301']);
        $product = barcodeProduct(['barcode' => '9302']);

        livewire(EditConsumableProduct::class, ['record' => $product->getRouteKey()])
            ->set('data.extraBarcodes', [['barcode' => '9301', 'pack_quantity' => 1]])
            ->call('save')
            ->assertHasFormErrors();

        livewire(EditConsumableProduct::class, ['record' => $product->getRouteKey()])
            ->set('data.extraBarcodes', [['barcode' => '9303', 'pack_quantity' => 24]])
            ->call('save')
            ->assertHasNoFormErrors();

        $extra = ProductBarcode::where('barcode', '9303')->sole();
        expect($extra->product_id)->toBe($product->id)
            ->and($extra->is_primary)->toBeFalse()
            ->and((float) $extra->pack_quantity)->toBe(24.0);
    });
});

it('finds a retired (inactive) SKU only when a stock document asks for it', function () {
    $product = barcodeProduct(['barcode' => '7007', 'is_active' => false]);

    expect(app(ProductLookupService::class)->lookup('7007'))->toBeNull()
        ->and(app(ProductLookupService::class)->lookup('7007', includeInactive: true)?->product->id)->toBe($product->id);
});
