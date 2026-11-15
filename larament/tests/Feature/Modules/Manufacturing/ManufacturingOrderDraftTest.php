<?php

use App\Enums\ProductType;
use App\Modules\Manufacturing\Filament\Resources\ManufacturingOrders\ManufacturingOrderResource;
use App\Modules\Manufacturing\Filament\Resources\ManufacturingOrders\Pages\CreateManufacturingOrder;
use App\Modules\Manufacturing\Filament\Resources\ManufacturingOrders\Pages\EditManufacturingOrder;
use App\Modules\Manufacturing\Filament\Resources\ManufacturingOrders\Pages\ListManufacturingOrders;
use App\Models\ManufacturingOrder;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\InventoryDailyAggregationService;
use App\Modules\Manufacturing\Services\ManufacturingOrderService;
use App\Modules\Manufacturing\Services\ManufacturingRecipeService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->raw = Product::factory()->count(3)->create(['type' => ProductType::RawMaterial]);
    $this->finished = Product::factory()->count(2)->create(['type' => ProductType::Consumable]);
    $this->recipe = app(ManufacturingRecipeService::class)->create([
        'name' => 'تقطيع الدجاج',
        'inputs' => [
            ['product_id' => $this->raw[0]->id, 'quantity' => 10],
            ['product_id' => $this->raw[1]->id, 'quantity' => 0.2],
            ['product_id' => $this->raw[2]->id, 'quantity' => 0.5],
        ],
        'outputs' => [
            ['product_id' => $this->finished[0]->id, 'is_main' => true, 'expected_quantity' => 4],
            ['product_id' => $this->finished[1]->id, 'is_main' => false, 'expected_quantity' => 4.5, 'cost_method' => 'fixed', 'cost_value' => 60],
        ],
    ]);
    $this->source = Warehouse::default();
    $this->destination = Warehouse::factory()->create();
    $this->service = app(ManufacturingOrderService::class);
});

it('scales main 4 to plan 8 into 20 chicken 0.4 spices and 1 oil', function () {
    $order = $this->service->createFromRecipe($this->recipe, $this->source->id, $this->destination->id, 8);

    expect($order->closed_at)->toBeNull()
        ->and($order->user_id)->toBe(auth()->id())
        ->and($order->inputs->pluck('planned_quantity')->all())->toBe(['20.000', '0.400', '1.000'])
        ->and($order->inputs->pluck('actual_quantity')->all())->toBe(['20.000', '0.400', '1.000'])
        ->and($order->outputs->pluck('expected_quantity')->all())->toBe(['8.000', '9.000'])
        ->and($order->outputs[1]->cost_method)->toBe('fixed')
        ->and((float) $order->outputs[1]->cost_value)->toBe(60.0);
});

it('copies recipe lines so later edits and deletion of recipe lines do not change the order', function () {
    $order = $this->service->createFromRecipe($this->recipe, $this->source->id, $this->destination->id, 8);
    $this->recipe->inputs()->first()->update(['quantity' => 99]);
    $this->recipe->outputs()->where('is_main', false)->update(['expected_quantity' => 99, 'cost_value' => 99]);
    $this->recipe->inputs()->delete();
    $this->recipe->outputs()->delete();

    expect($order->fresh()->inputs->pluck('planned_quantity')->all())->toBe(['20.000', '0.400', '1.000'])
        ->and($order->fresh()->outputs[1]->expected_quantity)->toBe('9.000')
        ->and($order->fresh()->outputs[1]->cost_value)->toBe('60.0000');
});

it('lets users edit actual inputs add extras and remove inputs while retaining planned quantities', function () {
    $order = $this->service->createFromRecipe($this->recipe, $this->source->id, $this->source->id, 8);
    $order->inputs[0]->update(['actual_quantity' => 21]);
    $extra = Product::factory()->create(['type' => ProductType::RawMaterial]);
    $order->inputs()->create(['product_id' => $extra->id, 'planned_quantity' => 0, 'actual_quantity' => 0.1]);
    $order->inputs[1]->delete();

    expect($order->fresh()->inputs)->toHaveCount(3)
        ->and($order->inputs[0]->fresh()->planned_quantity)->toBe('20.000')
        ->and($order->inputs[0]->fresh()->actual_quantity)->toBe('21.000');
});

it('blocks closing the day while a draft manufacturing order exists', function () {
    openInventoryDay($this->raw[0]);
    $this->service->createFromRecipe($this->recipe, $this->source->id, $this->destination->id, 8);
    expect(fn () => app(InventoryDailyAggregationService::class)->closeDay())
        ->toThrow(Exception::class, 'أمر تصنيع مفتوح');
});

it('rejects nonpositive plans and inactive warehouses', function () {
    expect(fn () => $this->service->createFromRecipe($this->recipe, $this->source->id, $this->destination->id, 0))
        ->toThrow(ValidationException::class);
    $this->destination->update(['is_active' => false]);
    expect(fn () => $this->service->createFromRecipe($this->recipe, $this->source->id, $this->destination->id, 8))
        ->toThrow(ValidationException::class);
    expect(ManufacturingOrder::count())->toBe(0);
});

it('preserves the original plan and output snapshot when editing a draft', function () {
    $order = $this->service->createFromRecipe($this->recipe, $this->source->id, $this->destination->id, 8);
    expect(fn () => $order->inputs->first()->update(['planned_quantity' => 99]))
        ->toThrow(\App\Exceptions\ManufacturingException::class);
    expect(fn () => $order->outputs->first()->update(['expected_quantity' => 99]))
        ->toThrow(\App\Exceptions\ManufacturingException::class);
    expect(fn () => $order->outputs->first()->delete())->toThrow(\App\Exceptions\ManufacturingException::class);
    expect(fn () => $order->update(['planned_main_quantity' => 99]))
        ->toThrow(\App\Exceptions\ManufacturingException::class);
});

it('can reverse and recreate the order migration', function () {
    $migration = require database_path('migrations/2026_11_10_120001_create_manufacturing_orders_tables.php');
    $migration->down();
    expect(\Illuminate\Support\Facades\Schema::hasTable('manufacturing_orders'))->toBeFalse()
        ->and(\Illuminate\Support\Facades\Schema::hasTable('manufacturing_order_inputs'))->toBeFalse()
        ->and(\Illuminate\Support\Facades\Schema::hasTable('manufacturing_order_outputs'))->toBeFalse();
    $migration->up();
    $order = $this->service->createFromRecipe($this->recipe, $this->source->id, $this->destination->id, 8);
    expect($order->inputs)->toHaveCount(3)->and($order->outputs)->toHaveCount(2);
});

it('creates and edits drafts through Filament with fixed outputs and registered permissions', function () {
    $permissions = collect(RolesAndPermissionsSeeder::permissionsCatalog())->collapse()->all();
    expect($permissions)->toHaveKeys(['view_manufacturing_orders', 'create_manufacturing_orders', 'update_manufacturing_orders', 'delete_manufacturing_orders']);
    livewire(ListManufacturingOrders::class)->assertSuccessful();
    livewire(CreateManufacturingOrder::class)->fillForm([
        'manufacturing_recipe_id' => $this->recipe->id,
        'source_warehouse_id' => $this->source->id,
        'destination_warehouse_id' => $this->destination->id,
        'planned_main_quantity' => 8,
        'notes' => 'دفعة صباحية',
    ])->call('create')->assertHasNoFormErrors();
    $order = ManufacturingOrder::sole();
    expect($order->inputs)->toHaveCount(3)->and($order->notes)->toBe('دفعة صباحية');
    $undo = Repeater::fake();
    livewire(EditManufacturingOrder::class, ['record' => $order->id])
        ->assertSuccessful()
        ->fillForm(['inputs' => $order->inputs->map(fn ($input) => ['product_id' => $input->product_id, 'actual_quantity' => 22])->all()])
        ->call('save')->assertHasNoFormErrors();
    $undo();
    expect($order->fresh()->outputs->pluck('expected_quantity')->all())->toBe(['8.000', '9.000'])
        ->and(ManufacturingOrderResource::getNavigationGroup())->toBe('إدارة المخزون');
});
