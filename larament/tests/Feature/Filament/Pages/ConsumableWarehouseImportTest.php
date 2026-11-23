<?php

use App\Enums\MovementReason;
use App\Enums\ProductType;
use App\Filament\Pages\ExcelImport;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\InventoryItemMovement;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ImportStrategies\ConsumableWarehouseImportStrategy;
use App\Services\ImportStrategies\ImportStrategyManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Ensure default warehouse exists
    Warehouse::firstOrCreate(
        ['is_default' => true],
        ['name' => 'المخزن الرئيسي', 'is_active' => true]
    );

    // Create user with permission
    Permission::firstOrCreate(['name' => 'manage_excel_import']);
    $this->user = User::factory()->create();
    $this->user->givePermissionTo('manage_excel_import');
    actingAs($this->user);
});

it('registers and resolves import strategies properly', function () {
    $manager = app(ImportStrategyManager::class);

    expect($manager->getAvailableStrategies())->toHaveKeys(['consumable_warehouse', 'kitchen_structure'])
        ->and($manager->getStrategy('consumable_warehouse'))->toBeInstanceOf(ConsumableWarehouseImportStrategy::class);

    $options = $manager->getStrategyOptions();
    expect($options)->toHaveKeys(['auto', 'consumable_warehouse', 'kitchen_structure']);
});

it('auto-detects consumable warehouse json format', function () {
    $manager = app(ImportStrategyManager::class);

    $jsonContent = json_encode([
        [
            'كود الصنف' => '552',
            'الصنف' => 'AE///////',
            'الباركود' => '1552',
            'المخزن الرئيسي' => 3,
        ],
    ], JSON_UNESCAPED_UNICODE);

    $tempJson = tempnam(sys_get_temp_dir(), 'test_consumable_').'.json';
    file_put_contents($tempJson, $jsonContent);

    $strategy = $manager->detectStrategy($tempJson);
    expect($strategy->getKey())->toBe('consumable_warehouse');

    @unlink($tempJson);
});

it('analyzes and imports consumable products with barcode, category, prices and warehouse quantities', function () {
    $strategy = new ConsumableWarehouseImportStrategy;

    $jsonContent = json_encode([
        [
            'كود الصنف' => '552',
            'الصنف' => 'AE///////',
            'الباركود' => '1552',
            'التصنيف' => 'بنطلون',
            'سعر البيع' => 700.0,
            'سعر التكلفة' => 350.0,
            'المخزن الرئيسي' => 3,
            'الإجمالي' => 3,
        ],
        [
            'كود الصنف' => '519',
            'الصنف' => 'brada polo',
            'الباركود' => '1519',
            'التصنيف' => 'تيشرت',
            'سعر البيع' => 1300.0,
            'سعر التكلفة' => 850.0,
            'المخزن الرئيسي' => 4,
            'الإجمالي' => 4,
        ],
        [
            'كود الصنف' => 'الإجمالي الكلي',
            'الصنف' => '',
            'الباركود' => '',
            'المخزن الرئيسي' => 7,
            'الإجمالي' => 7,
        ],
    ], JSON_UNESCAPED_UNICODE);

    $tempJson = tempnam(sys_get_temp_dir(), 'test_consumable_').'.json';
    file_put_contents($tempJson, $jsonContent);

    // 1. Analyze structure (verifying total column is omitted and category/prices included)
    $analysis = $strategy->analyzeStructure($tempJson);
    expect($analysis['success'])->toBeTrue()
        ->and($analysis['total_sheets'])->toBe(1)
        ->and($analysis['sheets']['المنتجات الاستهلاكية والمخازن']['estimated_data_rows'])->toBe(2)
        ->and($analysis['sheets']['المنتجات الاستهلاكية والمخازن']['headers'])->not->toContain('الإجمالي')
        ->and($analysis['sheets']['المنتجات الاستهلاكية والمخازن']['headers'])->toContain('المخزن الرئيسي')
        ->and($analysis['sheets']['المنتجات الاستهلاكية والمخازن']['headers'])->toContain('التصنيف')
        ->and($analysis['sheets']['المنتجات الاستهلاكية والمخازن']['headers'])->toContain('سعر البيع')
        ->and($analysis['sheets']['المنتجات الاستهلاكية والمخازن']['headers'])->toContain('سعر التكلفة');

    // 2. Import data
    $result = $strategy->import($tempJson);
    expect($result['success'])->toBeTrue()
        ->and($result['imported_count'])->toBe(2)
        ->and($result['error_count'])->toBe(0);

    // 3. Verify Database: Products
    $prod1 = Product::where('barcode', '1552')->with('category')->first();
    expect($prod1)->not->toBeNull()
        ->and($prod1->name)->toBe('AE///////')
        ->and($prod1->type)->toBe(ProductType::Consumable)
        ->and($prod1->product_ref)->toBe('552')
        ->and($prod1->barcode)->toBe('1552')
        ->and($prod1->category?->name)->toBe('بنطلون')
        ->and((float) $prod1->price)->toBe(700.0)
        ->and((float) $prod1->cost)->toBe(350.0);

    $prod2 = Product::where('barcode', '1519')->with('category')->first();
    expect($prod2)->not->toBeNull()
        ->and($prod2->name)->toBe('brada polo')
        ->and($prod2->type)->toBe(ProductType::Consumable)
        ->and($prod2->product_ref)->toBe('519')
        ->and($prod2->barcode)->toBe('1519')
        ->and($prod2->category?->name)->toBe('تيشرت')
        ->and((float) $prod2->price)->toBe(1300.0)
        ->and((float) $prod2->cost)->toBe(850.0);

    // 4. Verify primary barcode sync
    expect(ProductBarcode::where('barcode', '1552')->where('product_id', $prod1->id)->where('is_primary', true)->exists())->toBeTrue()
        ->and(ProductBarcode::where('barcode', '1519')->where('product_id', $prod2->id)->where('is_primary', true)->exists())->toBeTrue();

    // 5. Verify Warehouse & Inventory Items
    $warehouse = Warehouse::where('name', 'المخزن الرئيسي')->first();
    expect($warehouse)->not->toBeNull();

    $inv1 = InventoryItem::where('product_id', $prod1->id)->where('warehouse_id', $warehouse->id)->first();
    expect($inv1)->not->toBeNull()
        ->and((float) $inv1->quantity)->toBe(3.0);

    $inv2 = InventoryItem::where('product_id', $prod2->id)->where('warehouse_id', $warehouse->id)->first();
    expect($inv2)->not->toBeNull()
        ->and((float) $inv2->quantity)->toBe(4.0);

    // 6. Verify Initial Stock Movements
    expect(InventoryItemMovement::where('product_id', $prod1->id)->where('reason', MovementReason::INITIAL_STOCK)->exists())->toBeTrue()
        ->and(InventoryItemMovement::where('product_id', $prod2->id)->where('reason', MovementReason::INITIAL_STOCK)->exists())->toBeTrue();

    @unlink($tempJson);
});

it('can import full 264 products from enriched json and matches category, cost, price and warehouse quantity', function () {
    $strategy = new ConsumableWarehouseImportStrategy;
    $filePath = storage_path('app/excel-imports/consumable_products_warehouse.json');

    if (! file_exists($filePath)) {
        $this->markTestSkipped('consumable_products_warehouse.json does not exist.');
    }

    $result = $strategy->import($filePath);

    expect($result['success'])->toBeTrue()
        ->and($result['imported_count'])->toBe(264)
        ->and($result['error_count'])->toBe(0);

    $warehouse = Warehouse::where('name', 'المخزن الرئيسي')->first();
    expect($warehouse)->not->toBeNull();

    // Verify sum of inventory in the main warehouse equals 2361
    $totalStock = InventoryItem::where('warehouse_id', $warehouse->id)->sum('quantity');
    expect((float) $totalStock)->toBe(2361.0);

    // Verify classic trousers (barcode 1491): category = بنطلون, price = 950, cost = 500, qty = 43
    $trousers = Product::where('barcode', '1491')->with('category')->first();
    expect($trousers)->not->toBeNull()
        ->and($trousers->name)->toBe('classic trousers')
        ->and($trousers->type)->toBe(ProductType::Consumable)
        ->and($trousers->category?->name)->toBe('بنطلون')
        ->and((float) $trousers->price)->toBe(950.0)
        ->and((float) $trousers->cost)->toBe(500.0);

    $trousersStock = InventoryItem::where('product_id', $trousers->id)->where('warehouse_id', $warehouse->id)->value('quantity');
    expect((float) $trousersStock)->toBe(43.0);

    // Verify last bb (barcode 1953): category = تيشرت, price = 200, cost = 100, qty = 102
    $lastBb = Product::where('barcode', '1953')->with('category')->first();
    expect($lastBb)->not->toBeNull()
        ->and($lastBb->name)->toBe('last bb')
        ->and($lastBb->type)->toBe(ProductType::Consumable)
        ->and($lastBb->category?->name)->toBe('تيشرت')
        ->and((float) $lastBb->price)->toBe(200.0)
        ->and((float) $lastBb->cost)->toBe(100.0);

    $lastBbStock = InventoryItem::where('product_id', $lastBb->id)->where('warehouse_id', $warehouse->id)->value('quantity');
    expect((float) $lastBbStock)->toBe(102.0);
});

it('matches and updates existing products by barcode when importing pricing and category metadata', function () {
    $strategy = new ConsumableWarehouseImportStrategy;
    $warehouse = Warehouse::where('name', 'المخزن الرئيسي')->first();

    // 1. Create product before import with default prices and initial warehouse stock
    $product = Product::create([
        'name' => 'Existing Polo',
        'barcode' => '1001',
        'type' => ProductType::Consumable,
        'category_id' => Category::firstOrCreate(['name' => 'عام'])->id,
        'price' => 100.0,
        'cost' => 50.0,
        'unit' => 'piece',
        'warehouse_id' => $warehouse->id,
    ]);

    InventoryItem::updateOrCreate(
        ['product_id' => $product->id, 'warehouse_id' => $warehouse->id],
        ['quantity' => 15.0]
    );

    // 2. Import catalog sheet row matching on barcode 1001 (from user's sheet: 1001,بنطلون,MTJ,950,390)
    $jsonContent = json_encode([
        [
            'الباركود' => '1001',
            'التصنيف' => 'بنطلون',
            'الصنف' => 'MTJ',
            'سعر البيع' => 950.0,
            'سعر التكلفة' => 390.0,
        ],
    ], JSON_UNESCAPED_UNICODE);

    $tempJson = tempnam(sys_get_temp_dir(), 'test_catalog_').'.json';
    file_put_contents($tempJson, $jsonContent);

    $result = $strategy->import($tempJson);

    expect($result['success'])->toBeTrue()
        ->and($result['imported_count'])->toBe(1)
        ->and($result['error_count'])->toBe(0);

    // 3. Verify existing product was updated matching barcode 1001
    $updated = Product::where('barcode', '1001')->with('category')->first();
    expect($updated->id)->toBe($product->id)
        ->and($updated->name)->toBe('MTJ')
        ->and($updated->category?->name)->toBe('بنطلون')
        ->and((float) $updated->price)->toBe(950.0)
        ->and((float) $updated->cost)->toBe(390.0);

    // 4. Existing inventory stock was preserved
    $stock = InventoryItem::where('product_id', $updated->id)->where('warehouse_id', $warehouse->id)->value('quantity');
    expect((float) $stock)->toBe(15.0);

    @unlink($tempJson);
});

it('works seamlessly through ExcelImport Filament page with consumable warehouse strategy', function () {
    Storage::fake('local');

    $jsonContent = json_encode([
        [
            'code' => '552',
            'name' => 'AE///////',
            'barcode' => '1552',
            'category' => 'بنطلون',
            'price' => 700.0,
            'cost' => 350.0,
            'warehouse' => 'المخزن الرئيسي',
            'quantity' => 3,
        ],
    ], JSON_UNESCAPED_UNICODE);

    $uploadedFile = UploadedFile::fake()->createWithContent('consumables.json', $jsonContent);
    $path = $uploadedFile->store('excel-imports', 'local');

    livewire(ExcelImport::class)
        ->assertSuccessful()
        ->set('data.import_type', 'consumable_warehouse')
        ->set('data.excel_file', [$path])
        ->call('analyzeFile')
        ->assertHasNoErrors()
        ->assertSet('analysisResult.success', true)
        ->assertSet('analysisResult.sheets.المنتجات الاستهلاكية والمخازن.estimated_data_rows', 1)
        ->call('importData')
        ->assertHasNoErrors()
        ->assertSet('importResult.success', true)
        ->assertSet('importResult.imported_count', 1);

    $imported = Product::where('barcode', '1552')->with('category')->first();
    expect($imported)->not->toBeNull()
        ->and($imported->type)->toBe(ProductType::Consumable)
        ->and($imported->category?->name)->toBe('بنطلون')
        ->and((float) $imported->price)->toBe(700.0)
        ->and((float) $imported->cost)->toBe(350.0);
});
