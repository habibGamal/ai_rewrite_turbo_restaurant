<?php

use App\Enums\ProductType;
use App\Modules\Manufacturing\Filament\Resources\ManufacturingRecipes\Pages\CreateManufacturingRecipe;
use App\Modules\Manufacturing\Filament\Resources\ManufacturingRecipes\Pages\EditManufacturingRecipe;
use App\Modules\Manufacturing\Filament\Resources\ManufacturingRecipes\Pages\ListManufacturingRecipes;
use App\Models\ManufacturingRecipe;
use App\Models\Product;
use App\Models\User;
use App\Modules\Manufacturing\Services\ManufacturingRecipeService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\Repeater;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

it('requires exactly one main output', function () {
    $rawInput = Product::factory()->create(['type' => ProductType::RawMaterial]);
    $rawOut1 = Product::factory()->create(['type' => ProductType::RawMaterial]);
    $rawOut2 = Product::factory()->create(['type' => ProductType::RawMaterial]);

    $service = app(ManufacturingRecipeService::class);

    // Zero main outputs
    expect(fn () => $service->create([
        'name' => 'No Main Output Recipe',
        'is_active' => true,
        'inputs' => [
            ['product_id' => $rawInput->id, 'quantity' => 10],
        ],
        'outputs' => [
            [
                'product_id' => $rawOut1->id,
                'is_main' => false,
                'expected_quantity' => 5,
                'cost_method' => 'fixed',
                'cost_value' => 10,
            ],
        ],
    ]))->toThrow(ValidationException::class);

    // Two main outputs
    expect(fn () => $service->create([
        'name' => 'Two Main Outputs Recipe',
        'is_active' => true,
        'inputs' => [
            ['product_id' => $rawInput->id, 'quantity' => 10],
        ],
        'outputs' => [
            [
                'product_id' => $rawOut1->id,
                'is_main' => true,
                'expected_quantity' => 5,
                'cost_method' => 'percent',
                'cost_value' => 0,
            ],
            [
                'product_id' => $rawOut2->id,
                'is_main' => true,
                'expected_quantity' => 4,
                'cost_method' => 'percent',
                'cost_value' => 0,
            ],
        ],
    ]))->toThrow(ValidationException::class);

    // Exactly one main output succeeds
    $recipe = $service->create([
        'name' => 'Chicken Breakdown',
        'notes' => 'Test notes',
        'is_active' => true,
        'inputs' => [
            ['product_id' => $rawInput->id, 'quantity' => 10],
        ],
        'outputs' => [
            [
                'product_id' => $rawOut1->id,
                'is_main' => true,
                'expected_quantity' => 4,
                'cost_method' => 'percent',
                'cost_value' => 0,
            ],
            [
                'product_id' => $rawOut2->id,
                'is_main' => false,
                'expected_quantity' => 4.5,
                'cost_method' => 'fixed',
                'cost_value' => 60,
            ],
        ],
    ]);

    expect($recipe->exists)->toBeTrue()
        ->and($recipe->inputs)->toHaveCount(1)
        ->and($recipe->outputs)->toHaveCount(2)
        ->and($recipe->mainOutput->product_id)->toBe($rawOut1->id);
});

it('requires percent outputs to total under 100', function () {
    $rawInput = Product::factory()->create(['type' => ProductType::RawMaterial]);
    $mainOut = Product::factory()->create(['type' => ProductType::RawMaterial]);
    $subOut1 = Product::factory()->create(['type' => ProductType::RawMaterial]);
    $subOut2 = Product::factory()->create(['type' => ProductType::RawMaterial]);

    $service = app(ManufacturingRecipeService::class);

    // Percent outputs sum to 100 (must be strictly < 100)
    expect(fn () => $service->create([
        'name' => 'Percent 100 Recipe',
        'is_active' => true,
        'inputs' => [
            ['product_id' => $rawInput->id, 'quantity' => 10],
        ],
        'outputs' => [
            [
                'product_id' => $mainOut->id,
                'is_main' => true,
                'expected_quantity' => 5,
                'cost_method' => 'percent',
                'cost_value' => 0,
            ],
            [
                'product_id' => $subOut1->id,
                'is_main' => false,
                'expected_quantity' => 2,
                'cost_method' => 'percent',
                'cost_value' => 60,
            ],
            [
                'product_id' => $subOut2->id,
                'is_main' => false,
                'expected_quantity' => 3,
                'cost_method' => 'percent',
                'cost_value' => 40,
            ],
        ],
    ]))->toThrow(ValidationException::class);

    // Percent outputs sum to > 100
    expect(fn () => $service->create([
        'name' => 'Percent Over 100 Recipe',
        'is_active' => true,
        'inputs' => [
            ['product_id' => $rawInput->id, 'quantity' => 10],
        ],
        'outputs' => [
            [
                'product_id' => $mainOut->id,
                'is_main' => true,
                'expected_quantity' => 5,
                'cost_method' => 'percent',
                'cost_value' => 0,
            ],
            [
                'product_id' => $subOut1->id,
                'is_main' => false,
                'expected_quantity' => 2,
                'cost_method' => 'percent',
                'cost_value' => 105,
            ],
        ],
    ]))->toThrow(ValidationException::class);
});

it('accepts only raw materials and consumables as inputs and outputs', function () {
    $rawProduct = Product::factory()->create(['type' => ProductType::RawMaterial]);
    $consumableProduct = Product::factory()->create(['type' => ProductType::Consumable]);
    $manufacturedProduct = Product::factory()->create(['type' => ProductType::Manufactured]);

    $service = app(ManufacturingRecipeService::class);

    // Manufactured product as input is rejected
    expect(fn () => $service->create([
        'name' => 'Manufactured Input Recipe',
        'is_active' => true,
        'inputs' => [
            ['product_id' => $manufacturedProduct->id, 'quantity' => 1],
        ],
        'outputs' => [
            [
                'product_id' => $rawProduct->id,
                'is_main' => true,
                'expected_quantity' => 1,
                'cost_method' => 'percent',
                'cost_value' => 0,
            ],
        ],
    ]))->toThrow(ValidationException::class);

    // Manufactured product as output is rejected
    expect(fn () => $service->create([
        'name' => 'Manufactured Output Recipe',
        'is_active' => true,
        'inputs' => [
            ['product_id' => $rawProduct->id, 'quantity' => 1],
        ],
        'outputs' => [
            [
                'product_id' => $manufacturedProduct->id,
                'is_main' => true,
                'expected_quantity' => 1,
                'cost_method' => 'percent',
                'cost_value' => 0,
            ],
        ],
    ]))->toThrow(ValidationException::class);

    // Raw material + Consumable succeeds
    $recipe = $service->create([
        'name' => 'Raw and Consumable Recipe',
        'is_active' => true,
        'inputs' => [
            ['product_id' => $rawProduct->id, 'quantity' => 5],
        ],
        'outputs' => [
            [
                'product_id' => $consumableProduct->id,
                'is_main' => true,
                'expected_quantity' => 4,
                'cost_method' => 'percent',
                'cost_value' => 0,
            ],
        ],
    ]);

    expect($recipe->exists)->toBeTrue();
});

it('rejects a product that is both input and output', function () {
    $raw1 = Product::factory()->create(['type' => ProductType::RawMaterial]);
    $raw2 = Product::factory()->create(['type' => ProductType::RawMaterial]);

    $service = app(ManufacturingRecipeService::class);

    expect(fn () => $service->create([
        'name' => 'Circular Recipe',
        'is_active' => true,
        'inputs' => [
            ['product_id' => $raw1->id, 'quantity' => 10],
            ['product_id' => $raw2->id, 'quantity' => 1],
        ],
        'outputs' => [
            [
                'product_id' => $raw1->id,
                'is_main' => true,
                'expected_quantity' => 9,
                'cost_method' => 'percent',
                'cost_value' => 0,
            ],
        ],
    ]))->toThrow(ValidationException::class);
});

it('manages manufacturing recipes through Filament resource and registers permissions', function () {
    $catalog = RolesAndPermissionsSeeder::permissionsCatalog();
    $inventoryPermissions = $catalog['المشتريات والمخزون'] ?? [];

    expect($inventoryPermissions)->toHaveKeys([
        'view_manufacturing_recipes',
        'create_manufacturing_recipes',
        'update_manufacturing_recipes',
        'delete_manufacturing_recipes',
    ]);

    $admin = User::factory()->admin()->create();
    actingAs($admin);

    $chicken = Product::factory()->create(['name' => 'دجاج كامل', 'type' => ProductType::RawMaterial]);
    $breast = Product::factory()->create(['name' => 'صدور دجاج', 'type' => ProductType::RawMaterial]);
    $skin = Product::factory()->create(['name' => 'جلد دجاج', 'type' => ProductType::RawMaterial]);

    livewire(ListManufacturingRecipes::class)
        ->assertSuccessful();

    $undoRepeaterFake = Repeater::fake();

    livewire(CreateManufacturingRecipe::class)
        ->assertSuccessful()
        ->fillForm([
            'name' => 'تقطيع دجاج',
            'notes' => 'وصفة تقطيع',
            'is_active' => true,
            'main_product_id' => $breast->id,
            'batch_quantity' => 4,
            'inputs' => [
                [
                    'product_id' => $chicken->id,
                    'quantity' => 10,
                ],
            ],
            'extra_outputs' => [
                [
                    'product_id' => $skin->id,
                    'expected_quantity' => 0.5,
                    'cost_method' => 'percent',
                    'cost_value' => 4,
                ],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $recipe = ManufacturingRecipe::where('name', 'تقطيع دجاج')->first();
    expect($recipe)->not->toBeNull()
        ->and($recipe->inputs)->toHaveCount(1)
        ->and($recipe->outputs)->toHaveCount(2);

    livewire(EditManufacturingRecipe::class, ['record' => $recipe->getRouteKey()])
        ->assertSuccessful()
        ->call('save')
        ->assertHasNoFormErrors();

    expect($recipe->fresh()->outputs()->where('is_main', true)->count())->toBe(1);

    $undoRepeaterFake();
});
