<?php

use App\Enums\OptionalFeature;
use App\Filament\Pages\OutsourceProductImport;
use App\Models\ExternalProduct;
use App\Models\ExternalSeller;
use App\Models\User;
use App\Modules\ExternalSellers\Filament\Resources\ExternalSellers\Pages\EditExternalSeller;
use App\Modules\ExternalSellers\Filament\Resources\ExternalSellers\RelationManagers\ProductsRelationManager;
use App\Services\FeatureService;
use App\Services\OutsourceProductImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(FeatureService::class)->enable(OptionalFeature::OUTSOURCED_ORDERS);

    Permission::firstOrCreate(['name' => 'manage_excel_import']);
    Permission::firstOrCreate(['name' => 'view_external_sellers']);
    Permission::firstOrCreate(['name' => 'create_external_sellers']);

    $this->user = User::factory()->admin()->create();
    $this->user->givePermissionTo(['manage_excel_import', 'view_external_sellers', 'create_external_sellers']);
    actingAs($this->user);
});

it('detects and analyzes outsource json menu correctly', function () {
    $service = new OutsourceProductImportService;
    $filePath = storage_path('app/excel-imports/memo_sushi_outsource_menu.json');

    if (! file_exists($filePath)) {
        $this->markTestSkipped('memo_sushi_outsource_menu.json does not exist.');
    }

    $analysis = $service->analyzeStructure($filePath);

    expect($analysis['success'])->toBeTrue()
        ->and($analysis['seller']['name'])->toBe('Memo Sushi - ميمو سوشي')
        ->and($analysis['seller']['phone'])->toBe('01037919134')
        ->and($analysis['total_products'])->toBe(108)
        ->and($analysis['categories'])->toContain('RAW', 'RAW SPECIAL', 'NIGIRI', 'SASHIMI', 'TAMAKI', 'HOSOMAKI', 'FRIED', 'COOKED ROLLS', 'RICE PAPER', 'NOODLES', 'SWEET & SOUR', 'SOUP', 'APPETIZERS', 'SALAD')
        ->and($analysis['sample_data'])->toHaveCount(10);
});

it('imports memo sushi menu into database with 108 products', function () {
    $service = new OutsourceProductImportService;
    $filePath = storage_path('app/excel-imports/memo_sushi_outsource_menu.json');

    if (! file_exists($filePath)) {
        $this->markTestSkipped('memo_sushi_outsource_menu.json does not exist.');
    }

    $result = $service->importData($filePath);

    expect($result['success'])->toBeTrue()
        ->and($result['imported_count'])->toBe(108)
        ->and($result['error_count'])->toBe(0);

    $seller = ExternalSeller::where('name', 'Memo Sushi - ميمو سوشي')->first();
    expect($seller)->not->toBeNull()
        ->and($seller->phone)->toBe('01037919134')
        ->and($seller->products()->count())->toBe(108);

    // Verify sample items across sections
    $philly4 = ExternalProduct::where('external_seller_id', $seller->id)
        ->where('name', 'Philadelphia (4 Pcs)')
        ->first();
    expect($philly4)->not->toBeNull()
        ->and((float) $philly4->price)->toBe(130.0);

    $philly8 = ExternalProduct::where('external_seller_id', $seller->id)
        ->where('name', 'Philadelphia (8 Pcs)')
        ->first();
    expect($philly8)->not->toBeNull()
        ->and((float) $philly8->price)->toBe(250.0);

    $volcano8 = ExternalProduct::where('external_seller_id', $seller->id)
        ->where('name', 'Volcano (8 Pcs)')
        ->first();
    expect($volcano8)->not->toBeNull()
        ->and((float) $volcano8->price)->toBe(320.0);

    $noodles = ExternalProduct::where('external_seller_id', $seller->id)
        ->where('name', 'Noodles Shrimp')
        ->first();
    expect($noodles)->not->toBeNull()
        ->and((float) $noodles->price)->toBe(200.0);
});

it('supports importing for a specific existing seller', function () {
    $service = new OutsourceProductImportService;

    $seller = ExternalSeller::create([
        'name' => 'مطعم السوشي المميز',
        'phone' => '01000000000',
        'is_active' => true,
    ]);

    $tempJson = tempnam(sys_get_temp_dir(), 'test_seller_').'.json';
    file_put_contents($tempJson, json_encode([
        'products' => [
            ['name' => 'سوشي مشكل', 'price' => 150.0],
            ['name' => 'رول جمبري', 'price' => 120.0],
        ],
    ], JSON_UNESCAPED_UNICODE));

    $result = $service->importData($tempJson, $seller->id);

    expect($result['success'])->toBeTrue()
        ->and($result['seller']['id'])->toBe($seller->id)
        ->and($result['imported_count'])->toBe(2)
        ->and($seller->products()->count())->toBe(2);

    @unlink($tempJson);
});

it('renders OutsourceProductImport page and executes analyze and import actions', function () {
    Storage::fake('local');

    $jsonContent = json_encode([
        'seller' => [
            'name' => 'بائع تجريبي',
            'phone' => '01111111111',
        ],
        'products' => [
            ['name' => 'صنف تجريبي 1', 'price' => 50.0, 'category' => 'مقبلات'],
            ['name' => 'صنف تجريبي 2', 'price' => 75.0, 'category' => 'رئيسي'],
        ],
    ], JSON_UNESCAPED_UNICODE);

    $uploadedFile = UploadedFile::fake()->createWithContent('outsource.json', $jsonContent);
    $path = $uploadedFile->store('outsource-imports', 'local');

    livewire(OutsourceProductImport::class)
        ->assertSuccessful()
        ->assertSee('استيراد أصناف البائعين الخارجيين')
        ->set('data.import_file', [$path])
        ->call('analyzeFile')
        ->assertHasNoErrors()
        ->assertSet('analysisResult.success', true)
        ->assertSet('analysisResult.total_products', 2)
        ->call('importData')
        ->assertHasNoErrors()
        ->assertSet('importResult.success', true)
        ->assertSet('importResult.imported_count', 2);

    expect(ExternalSeller::where('name', 'بائع تجريبي')->exists())->toBeTrue()
        ->and(ExternalProduct::where('name', 'صنف تجريبي 1')->exists())->toBeTrue();
});

it('can import products via ProductsRelationManager action', function () {
    Storage::fake('local');

    $seller = ExternalSeller::create(['name' => 'بائع ريليشن', 'phone' => '0123456789']);

    $jsonContent = json_encode([
        'products' => [
            ['name' => 'صنف مباشر 1', 'price' => 99.0],
        ],
    ], JSON_UNESCAPED_UNICODE);

    $uploadedFile = UploadedFile::fake()->createWithContent('rel.json', $jsonContent);
    $path = $uploadedFile->store('outsource-imports', 'local');

    livewire(ProductsRelationManager::class, [
        'ownerRecord' => $seller,
        'pageClass' => EditExternalSeller::class,
    ])
        ->assertSuccessful()
        ->callTableAction('importProducts', data: ['file' => [$path]])
        ->assertHasNoTableActionErrors();

    expect($seller->products()->where('name', 'صنف مباشر 1')->exists())->toBeTrue();
});
