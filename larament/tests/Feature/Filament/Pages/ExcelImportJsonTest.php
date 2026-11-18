<?php

use App\Enums\ProductType;
use App\Filament\Pages\ExcelImport;
use App\Models\Category;
use App\Models\Printer;
use App\Models\Product;
use App\Models\ProductComponent;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SpecificDataImportService;
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
        ['name' => 'المخزن الافتراضي', 'is_active' => true]
    );

    // Create user with permission
    Permission::firstOrCreate(['name' => 'manage_excel_import']);
    $this->user = User::factory()->create();
    $this->user->givePermissionTo('manage_excel_import');
    actingAs($this->user);
});

it('detects json files correctly', function () {
    $service = new SpecificDataImportService;

    $tempJson = tempnam(sys_get_temp_dir(), 'test_').'.json';
    file_put_contents($tempJson, json_encode(['categories' => ['Test Category']]));

    $tempNonJson = tempnam(sys_get_temp_dir(), 'test_').'.txt';
    file_put_contents($tempNonJson, 'This is plain text, not JSON');

    expect($service->isJsonFile($tempJson))->toBeTrue()
        ->and($service->isJsonFile($tempNonJson))->toBeFalse();

    @unlink($tempJson);
    @unlink($tempNonJson);
});

it('analyzes clean json structure correctly', function () {
    $service = new SpecificDataImportService;
    $filePath = storage_path('app/excel-imports/hh_kitchen_import_clean.json');

    if (! file_exists($filePath)) {
        $this->markTestSkipped('hh_kitchen_import_clean.json does not exist in storage.');
    }

    $result = $service->analyzeStructure($filePath);

    expect($result['success'])->toBeTrue()
        ->and($result['total_sheets'])->toBe(6)
        ->and($result['sheets'])->toHaveKeys(['Printer', 'الفئات', 'الخام', 'الاستهلاكي', 'التصنيع', 'المعياري'])
        ->and($result['sheets']['الفئات']['estimated_data_rows'])->toBe(35)
        ->and($result['sheets']['الخام']['estimated_data_rows'])->toBe(415)
        ->and($result['sheets']['التصنيع']['estimated_data_rows'])->toBe(95)
        ->and($result['sheets']['المعياري']['estimated_data_rows'])->toBe(746);
});

it('analyzes sheets-mapped json structure correctly', function () {
    $service = new SpecificDataImportService;
    $filePath = storage_path('app/excel-imports/hh_kitchen_import_sheets.json');

    if (! file_exists($filePath)) {
        $this->markTestSkipped('hh_kitchen_import_sheets.json does not exist in storage.');
    }

    $result = $service->analyzeStructure($filePath);

    expect($result['success'])->toBeTrue()
        ->and($result['total_sheets'])->toBe(6)
        ->and($result['sheets'])->toHaveKeys(['Printer', 'الفئات', 'الخام', 'الاستهلاكي', 'التصنيع', 'المعياري'])
        ->and($result['sheets']['الفئات']['estimated_data_rows'])->toBe(35)
        ->and($result['sheets']['الخام']['estimated_data_rows'])->toBe(415)
        ->and($result['sheets']['التصنيع']['estimated_data_rows'])->toBe(95)
        ->and($result['sheets']['المعياري']['estimated_data_rows'])->toBe(746);
});

it('imports clean json format and calculates manufactured cost correctly', function () {
    $service = new SpecificDataImportService;

    $jsonContent = json_encode([
        'printers' => [
            ['name' => 'طابعة المطبخ', 'ip_address' => '192.168.1.100'],
        ],
        'categories' => [
            'البيتزا',
            'خامات عامة',
        ],
        'raw_materials' => [
            [
                'name' => 'عجينة بيتزا خام',
                'category' => 'خامات عامة',
                'cost' => 15.50,
                'unit' => 'piece',
            ],
            [
                'name' => 'جبنة موتزاريلا',
                'category' => 'خامات عامة',
                'cost' => 25.00,
                'unit' => 'kg',
            ],
        ],
        'consumables' => [],
        'manufactured_products' => [
            [
                'name' => 'بيتزا مارغريتا تست',
                'category' => 'البيتزا',
                'price' => 120.00,
                'barcode' => 'PZ-101',
            ],
        ],
        'recipes' => [
            [
                'manufactured_product' => 'بيتزا مارغريتا تست',
                'component' => 'عجينة بيتزا خام',
                'quantity' => 1,
            ],
            [
                'manufactured_product' => 'بيتزا مارغريتا تست',
                'component' => 'جبنة موتزاريلا',
                'quantity' => 0.2,
            ],
        ],
    ], JSON_UNESCAPED_UNICODE);

    $tempJson = tempnam(sys_get_temp_dir(), 'test_import_').'.json';
    file_put_contents($tempJson, $jsonContent);

    $result = $service->importData($tempJson);

    expect($result['success'])->toBeTrue()
        ->and($result['imported_count'])->toBe(8)
        ->and($result['error_count'])->toBe(0);

    // Verify database state
    expect(Printer::where('name', 'طابعة المطبخ')->exists())->toBeTrue()
        ->and(Category::where('name', 'البيتزا')->exists())->toBeTrue()
        ->and(Category::where('name', 'خامات عامة')->exists())->toBeTrue();

    $rawDough = Product::where('name', 'عجينة بيتزا خام')->first();
    expect($rawDough)->not->toBeNull()
        ->and($rawDough->type)->toBe(ProductType::RawMaterial)
        ->and((float) $rawDough->cost)->toBe(15.50);

    $rawCheese = Product::where('name', 'جبنة موتزاريلا')->first();
    expect($rawCheese)->not->toBeNull()
        ->and((float) $rawCheese->cost)->toBe(25.00);

    $pizza = Product::where('name', 'بيتزا مارغريتا تست')->first();
    expect($pizza)->not->toBeNull()
        ->and($pizza->type)->toBe(ProductType::Manufactured)
        ->and((float) $pizza->price)->toBe(120.00)
        ->and($pizza->barcode)->toBe('PZ-101');

    // Verify recipe cost: 1 * 15.50 + 0.2 * 25.00 = 15.50 + 5.00 = 20.50
    expect((float) $pizza->cost)->toBe(20.50);

    expect(ProductComponent::where('product_id', $pizza->id)->count())->toBe(2);

    @unlink($tempJson);
});

it('imports sheets-mapped json format with Arabic keys', function () {
    $service = new SpecificDataImportService;

    $jsonContent = json_encode([
        'Printer' => [
            ['printer_name' => 'طابعة البار', 'ipAddr' => '192.168.1.101'],
        ],
        'الفئات' => [
            ['الفئات' => 'مشروبات'],
        ],
        'الخام' => [
            [
                'أسم المنتج' => 'بن اسبريسو',
                'الفئة' => 'مشروبات',
                'التكلفة' => 300.0,
                'الوحدة' => 'كجم',
            ],
        ],
        'الاستهلاكي' => [
            [
                'أسم المنتج' => 'كوب عصير سفري',
                'الفئة' => 'مشروبات',
                'التكلفة' => 2.5,
                'السعر' => 0.0,
                'الوحدة' => 'قطعة',
                'barcode' => 'CUP-1',
            ],
        ],
        'التصنيع' => [
            [
                'أسم المنتج' => 'كوب قهوة اسبريسو',
                'الفئة' => 'مشروبات',
                'السعر' => 45.0,
                'barcode' => 'ESP-1',
            ],
        ],
        'المعياري' => [
            [
                'أسم المنتج المصنع' => 'كوب قهوة اسبريسو',
                'أسم المكون' => 'بن اسبريسو',
                'الكمية' => 0.02,
            ],
        ],
    ], JSON_UNESCAPED_UNICODE);

    $tempJson = tempnam(sys_get_temp_dir(), 'test_sheet_import_').'.json';
    file_put_contents($tempJson, $jsonContent);

    $result = $service->importData($tempJson);

    expect($result['success'])->toBeTrue()
        ->and($result['error_count'])->toBe(0);

    $espresso = Product::where('name', 'كوب قهوة اسبريسو')->first();
    expect($espresso)->not->toBeNull()
        ->and((float) $espresso->price)->toBe(45.0)
        // 0.02 * 300 = 6.0
        ->and((float) $espresso->cost)->toBe(6.0);

    $cup = Product::where('name', 'كوب عصير سفري')->first();
    expect($cup)->not->toBeNull()
        ->and($cup->type)->toBe(ProductType::Consumable)
        ->and((float) $cup->cost)->toBe(2.5);

    @unlink($tempJson);
});

it('renders the Excel/JSON import filament page and handles actions', function () {
    Storage::fake('local');

    $jsonContent = json_encode([
        'categories' => ['مشروبات باردة'],
        'raw_materials' => [
            ['name' => 'سكر', 'category' => 'مشروبات باردة', 'cost' => 10.0, 'unit' => 'كجم'],
        ],
        'consumables' => [],
        'manufactured_products' => [],
        'recipes' => [],
    ], JSON_UNESCAPED_UNICODE);

    $uploadedFile = UploadedFile::fake()->createWithContent('import.json', $jsonContent);

    // Store file in temporary disk path
    $path = $uploadedFile->store('excel-imports', 'local');

    livewire(ExcelImport::class)
        ->assertSuccessful()
        ->assertSee('رفع ملف Excel / JSON')
        ->set('data.excel_file', [$path])
        ->call('analyzeFile')
        ->assertHasNoErrors()
        ->assertSet('analysisResult.success', true)
        ->assertSet('analysisResult.sheets.الفئات.estimated_data_rows', 1)
        ->call('importData')
        ->assertHasNoErrors()
        ->assertSet('importResult.success', true);

    expect(Category::where('name', 'مشروبات باردة')->exists())->toBeTrue()
        ->and(Product::where('name', 'سكر')->exists())->toBeTrue();
});

it('can successfully import the full hh_kitchen_import_clean.json file', function () {
    $service = new SpecificDataImportService;
    $filePath = storage_path('app/excel-imports/hh_kitchen_import_clean.json');

    if (! file_exists($filePath)) {
        $this->markTestSkipped('hh_kitchen_import_clean.json does not exist in storage.');
    }

    $result = $service->importData($filePath);

    expect($result['success'])->toBeTrue()
        ->and($result['error_count'])->toBe(0)
        ->and($result['imported_count'])->toBeGreaterThan(1200);

    // Verify pizza product exists and has cost > 0
    $pizza = Product::where('name', 'بيتزا دجاج رانش')->first();
    expect($pizza)->not->toBeNull()
        ->and((float) $pizza->price)->toBe(185.0)
        ->and((float) $pizza->cost)->toBeGreaterThan(0);
});
