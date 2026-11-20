<?php

use App\Modules\Manufacturing\Filament\Resources\ManufacturingOrders\Pages\CreateManufacturingOrder;
use App\Modules\Manufacturing\Filament\Resources\ManufacturingOrders\Pages\EditManufacturingOrder;
use App\Modules\Manufacturing\Filament\Resources\ManufacturingRecipes\Pages\CreateManufacturingRecipe;
use App\Modules\Manufacturing\Filament\Resources\ManufacturingRecipes\Pages\EditManufacturingRecipe;
use App\Models\InventoryItem;
use App\Models\ManufacturingOrder;
use App\Models\ManufacturingRecipe;
use App\Models\Product;
use App\Models\Warehouse;
use App\Modules\Manufacturing\Services\ManufacturingCostPreviewService;
use App\Modules\Manufacturing\Services\ManufacturingOrderService;
use App\Modules\Manufacturing\Services\ManufacturingRecipeService;
use App\Services\SettingsService;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(SettingsService::class)->set('costing_method', 'immediate');
    $this->chicken = Product::factory()->create(['name' => 'فراخ كاملة', 'type' => 'raw_material', 'cost' => 70, 'unit' => 'kg']);
    $this->spices = Product::factory()->create(['name' => 'بهارات', 'type' => 'raw_material', 'cost' => 250, 'unit' => 'kg']);
    $this->oil = Product::factory()->create(['name' => 'زيت', 'type' => 'raw_material', 'cost' => 60, 'unit' => 'liter']);
    $this->breast = Product::factory()->create(['name' => 'صدور', 'type' => 'raw_material', 'cost' => 0, 'unit' => 'kg']);
    $this->thighs = Product::factory()->create(['name' => 'أوراك', 'type' => 'raw_material', 'cost' => 0, 'unit' => 'kg']);
    $this->skin = Product::factory()->create(['name' => 'جلد', 'type' => 'raw_material', 'cost' => 0, 'unit' => 'kg']);
    $this->bones = Product::factory()->create(['name' => 'عضم', 'type' => 'raw_material', 'cost' => 0, 'unit' => 'kg']);
    $this->extras = [
        ['product_id' => $this->thighs->id, 'expected_quantity' => 4.5, 'cost_method' => 'fixed', 'cost_value' => 60],
        ['product_id' => $this->skin->id, 'expected_quantity' => 0.5, 'cost_method' => 'percent', 'cost_value' => 4],
        ['product_id' => $this->bones->id, 'expected_quantity' => 1, 'cost_method' => 'fixed', 'cost_value' => 10],
    ];
    $this->inputs = [
        ['product_id' => $this->chicken->id, 'quantity' => 10],
        ['product_id' => $this->spices->id, 'quantity' => 0.2],
        ['product_id' => $this->oil->id, 'quantity' => 0.5],
    ];
    $this->recipe = app(ManufacturingRecipeService::class)->create([
        'name' => 'تقطيع فراخ',
        'inputs' => $this->inputs,
        'outputs' => [
            ['product_id' => $this->breast->id, 'is_main' => true, 'expected_quantity' => 4],
            ...array_map(fn (array $extra) => $extra + ['is_main' => false], $this->extras),
        ],
    ]);
});

it('previews the recipe cost split: 780 → breast 480 (120/kg), thighs 270, skin 20, bones 10', function () {
    $preview = app(ManufacturingCostPreviewService::class)->previewRecipe($this->inputs, $this->breast->id, 4, $this->extras);
    $byProduct = collect($preview['outputs'])->keyBy('product_id');

    expect($preview['batch_cost'])->toBe(780.0)
        ->and($preview['error'])->toBeNull()
        ->and($byProduct[$this->breast->id]['allocated_cost'])->toBe(480.0)
        ->and($byProduct[$this->breast->id]['unit_cost'])->toBe(120.0)
        ->and($byProduct[$this->thighs->id]['allocated_cost'])->toBe(270.0)
        ->and($byProduct[$this->thighs->id]['unit_cost'])->toBe(60.0)
        ->and($byProduct[$this->skin->id]['allocated_cost'])->toBe(20.0)
        ->and($byProduct[$this->skin->id]['unit_cost'])->toBe(40.0)
        ->and($byProduct[$this->bones->id]['allocated_cost'])->toBe(10.0);
});

it('shows the allocation error instead of numbers when fixed outputs exceed the batch cost', function () {
    $preview = app(ManufacturingCostPreviewService::class)->previewRecipe(
        $this->inputs, $this->breast->id, 4,
        [['product_id' => $this->thighs->id, 'expected_quantity' => 4.5, 'cost_method' => 'fixed', 'cost_value' => 500]],
    );

    expect($preview['outputs'])->toBe([])->and($preview['error'])->not->toBeNull();
});

it('creates exactly one main output from the recipe header fields', function () {
    $undo = Repeater::fake();
    livewire(CreateManufacturingRecipe::class)
        ->fillForm([
            'name' => 'وصفة جديدة',
            'is_active' => true,
            'main_product_id' => $this->breast->id,
            'batch_quantity' => 4,
            'inputs' => $this->inputs,
            'extra_outputs' => $this->extras,
        ])
        ->call('create')
        ->assertHasNoFormErrors();
    $undo();

    $recipe = ManufacturingRecipe::where('name', 'وصفة جديدة')->sole();
    expect($recipe->outputs()->where('is_main', true)->count())->toBe(1)
        ->and($recipe->mainOutput->product_id)->toBe($this->breast->id)
        ->and((float) $recipe->mainOutput->expected_quantity)->toBe(4.0)
        ->and($recipe->outputs()->where('is_main', false)->count())->toBe(3)
        ->and($recipe->inputs()->count())->toBe(3);
});

it('loads an existing recipe main output into the header and the rest into the extra table', function () {
    $page = livewire(EditManufacturingRecipe::class, ['record' => $this->recipe->getRouteKey()])
        ->assertFormSet(['main_product_id' => $this->breast->id, 'batch_quantity' => 4]);

    $extraIds = collect($page->get('data.extra_outputs'))->pluck('product_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    expect($extraIds)->toBe(collect([$this->thighs->id, $this->skin->id, $this->bones->id])->sort()->values()->all());
});

it('fills the order tables as soon as the recipe and quantity are chosen, before saving', function () {
    $page = livewire(CreateManufacturingOrder::class)
        ->set('data.planned_main_quantity', 8)
        ->set('data.manufacturing_recipe_id', $this->recipe->id);

    $inputs = collect($page->get('data.inputs'))->keyBy('product_id');
    $outputs = collect($page->get('data.outputs'))->keyBy('product_id');

    expect((float) $inputs[$this->chicken->id]['planned_quantity'])->toBe(20.0)
        ->and((float) $inputs[$this->spices->id]['planned_quantity'])->toBe(0.4)
        ->and((float) $inputs[$this->oil->id]['planned_quantity'])->toBe(1.0)
        ->and((float) $inputs[$this->chicken->id]['actual_quantity'])->toBe(20.0)
        ->and((float) $outputs[$this->breast->id]['expected_quantity'])->toBe(8.0)
        ->and((bool) $outputs[$this->breast->id]['is_main'])->toBeTrue()
        ->and((float) $outputs[$this->thighs->id]['expected_quantity'])->toBe(9.0)
        ->and((float) $outputs[$this->skin->id]['expected_quantity'])->toBe(1.0)
        ->and((float) $outputs[$this->bones->id]['expected_quantity'])->toBe(2.0);
    $page->assertSee('= 2 دفعة');
});

it('saves the actual quantities and an added extra output exactly as entered', function () {
    $page = livewire(CreateManufacturingOrder::class)
        ->set('data.planned_main_quantity', 8)
        ->set('data.manufacturing_recipe_id', $this->recipe->id);

    $inputs = $page->get('data.inputs');
    foreach ($inputs as $key => $row) {
        if ((int) $row['product_id'] === $this->chicken->id) {
            $page->set("data.inputs.{$key}.actual_quantity", 21);
        }
    }
    $neck = Product::factory()->create(['name' => 'رقاب', 'type' => 'raw_material', 'unit' => 'kg']);
    $outputs = $page->get('data.outputs');
    $outputs['extra-neck'] = [
        'product_id' => $neck->id, 'is_main' => false, 'expected_quantity' => 0, 'actual_quantity' => 0.7,
        'cost_method' => 'fixed', 'cost_value' => 5,
    ];
    $page->set('data.outputs', $outputs)->call('create')->assertHasNoFormErrors();

    $order = ManufacturingOrder::latest('id')->firstOrFail();
    expect((float) $order->inputs()->where('product_id', $this->chicken->id)->value('actual_quantity'))->toBe(21.0)
        ->and((float) $order->inputs()->where('product_id', $this->chicken->id)->value('planned_quantity'))->toBe(20.0)
        ->and((float) $order->outputs()->where('product_id', $neck->id)->value('actual_quantity'))->toBe(0.7)
        ->and($order->outputs()->where('product_id', $neck->id)->value('cost_method'))->toBe('fixed')
        ->and($order->outputs()->where('is_main', true)->count())->toBe(1);
});

it('re-scales planned quantities when the quantity to produce changes on a draft', function () {
    $order = app(ManufacturingOrderService::class)->createFromRecipe($this->recipe, Warehouse::default()->id, Warehouse::default()->id, 4);

    livewire(EditManufacturingOrder::class, ['record' => $order->getRouteKey()])
        ->set('data.planned_main_quantity', 12)
        ->call('save')
        ->assertHasNoFormErrors();

    $order->refresh();
    expect((float) $order->planned_main_quantity)->toBe(12.0)
        ->and((float) $order->inputs()->where('product_id', $this->chicken->id)->value('planned_quantity'))->toBe(30.0)
        ->and((float) $order->outputs()->where('product_id', $this->thighs->id)->value('expected_quantity'))->toBe(13.5);
});

it('completes a draft opened and completed without edits, using the planned quantities as actual', function () {
    InventoryItem::where('product_id', $this->chicken->id)->update(['quantity' => 50]);
    openInventoryDay($this->chicken);
    $order = app(ManufacturingOrderService::class)->createFromRecipe($this->recipe, Warehouse::default()->id, Warehouse::default()->id, 4);

    livewire(EditManufacturingOrder::class, ['record' => $order->getRouteKey()])
        ->callAction('complete')
        ->assertHasNoActionErrors();

    $order->refresh();
    expect($order->closed_at)->not->toBeNull()
        ->and((float) $order->total_input_cost)->toBe(780.0)
        ->and((float) $order->outputs()->where('is_main', true)->value('actual_quantity'))->toBe(4.0)
        ->and((float) $order->outputs()->where('is_main', true)->value('allocated_cost'))->toBe(480.0);
});

it('renders the recipe view page with its expected cost section', function () {
    livewire(\App\Modules\Manufacturing\Filament\Resources\ManufacturingRecipes\Pages\ViewManufacturingRecipe::class, ['record' => $this->recipe->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('التكلفة المتوقعة للمخرجات')
        ->assertSee('صدور')
        ->assertSee('120');
});

it('renders the completed order view with summary cards and line tables', function () {
    InventoryItem::where('product_id', $this->chicken->id)->update(['quantity' => 50]);
    openInventoryDay($this->chicken);
    $order = app(ManufacturingOrderService::class)->createFromRecipe($this->recipe, Warehouse::default()->id, Warehouse::default()->id, 4);
    app(ManufacturingOrderService::class)->complete($order, $order->outputs->pluck('expected_quantity', 'id')->all());

    livewire(\App\Modules\Manufacturing\Filament\Resources\ManufacturingOrders\Pages\ViewManufacturingOrder::class, ['record' => $order->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('إجمالي تكلفة المدخلات')
        ->assertSee('تكلفة الوحدة من المنتج الرئيسي')
        ->assertSee('120.0000')
        ->assertSee('نسبة الإنتاج');
});
