<?php

use App\Enums\MovementReason;
use App\Exceptions\ManufacturingException;
use App\Filament\Resources\ManufacturingOrders\Pages\EditManufacturingOrder;
use App\Filament\Resources\ManufacturingOrders\Pages\ViewManufacturingOrder;
use App\Models\InventoryItem;
use App\Models\InventoryItemMovement;
use App\Models\Product;
use App\Models\ProductCostHistory;
use App\Models\User;
use App\Models\Warehouse;
use App\Policies\ManufacturingOrderPolicy;
use App\Services\InventoryDailyAggregationService;
use App\Services\Manufacturing\ManufacturingOrderService;
use App\Services\Manufacturing\ManufacturingRecipeService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(SettingsService::class)->set('costing_method', 'immediate');
    $this->chicken = Product::factory()->create(['type' => 'raw_material', 'cost' => 80]);
    $this->sauce = Product::factory()->create(['type' => 'raw_material', 'cost' => 40]);
    $this->marinated = Product::factory()->create(['type' => 'raw_material', 'cost' => 50]);
    $this->source = Warehouse::default();
    $this->destination = Warehouse::factory()->create(['name' => 'التجهيزات']);
    InventoryItem::where('product_id', $this->chicken->id)->update(['quantity' => 30]);
    InventoryItem::where('product_id', $this->sauce->id)->update(['quantity' => 5]);
    openInventoryDay($this->chicken);
    $this->recipe = app(ManufacturingRecipeService::class)->create([
        'name' => 'تتبيل الدجاج',
        'inputs' => [
            ['product_id' => $this->chicken->id, 'quantity' => 20],
            ['product_id' => $this->sauce->id, 'quantity' => 1],
        ],
        'outputs' => [['product_id' => $this->marinated->id, 'is_main' => true, 'expected_quantity' => 19]],
    ]);
    $this->service = app(ManufacturingOrderService::class);
    $this->order = $this->service->createFromRecipe($this->recipe, $this->source->id, $this->destination->id, 19);
    $this->mainId = $this->order->outputs->sole()->id;
});

it('takes 20 chicken and 1 sauce from Main and receives 18.5 marinated chicken in Prep', function () {
    $this->service->complete($this->order, [$this->mainId => 18.5]);
    expect((float) InventoryItem::where('product_id', $this->chicken->id)->where('warehouse_id', $this->source->id)->sole()->quantity)->toBe(10.0)
        ->and((float) InventoryItem::where('product_id', $this->sauce->id)->where('warehouse_id', $this->source->id)->sole()->quantity)->toBe(4.0)
        ->and((float) InventoryItem::where('product_id', $this->marinated->id)->where('warehouse_id', $this->destination->id)->sole()->quantity)->toBe(18.5)
        ->and(InventoryItemMovement::where('reason', MovementReason::MANUFACTURING_OUT)->count())->toBe(2)
        ->and(InventoryItemMovement::where('reason', MovementReason::MANUFACTURING_IN)->count())->toBe(1);
    foreach (InventoryItemMovement::all() as $movement) {
        expect($movement->referenceable->is($this->order))->toBeTrue()->and($movement->user_id)->toBe(auth()->id());
    }
    expect($this->order->fresh()->closed_at)->not->toBeNull()
        ->and($this->order->fresh()->closed_by)->toBe(auth()->id())
        ->and($this->order->fresh()->business_date->toDateString())->toBe(now()->toDateString());
    app(InventoryDailyAggregationService::class)->assertNoOpenDocuments();
});

it('stores the real 1640 input cost and 88.6486 per output unit and rounds variance to minus 44.32', function () {
    $this->service->complete($this->order, [$this->mainId => 18.5]);
    $order = $this->order->fresh(['inputs', 'outputs']);
    $output = $order->outputs->sole();
    $movement = InventoryItemMovement::where('reason', MovementReason::MANUFACTURING_IN)->sole();
    expect($order->total_input_cost)->toBe('1640.00')
        ->and($order->inputs->pluck('unit_cost')->all())->toBe(['80.0000', '40.0000'])
        ->and($order->inputs->pluck('total_cost')->all())->toBe(['1600.00', '40.00'])
        ->and($output->allocated_cost)->toBe('1640.00')->and($output->unit_cost)->toBe('88.6486')
        ->and($output->actual_quantity)->toBe('18.500')->and($output->variance_quantity)->toBe('-0.500')
        ->and($output->variance_value)->toBe('-44.32')
        ->and((float) $movement->unit_cost)->toBe(88.6486)->and((float) $movement->total_cost)->toBe(1640.0)
        ->and((float) $this->marinated->fresh()->cost)->toBe(88.6486);
});

it('uses the strategy cost at completion and edited actual inputs while retaining the plan', function () {
    $this->chicken->update(['cost' => 90]);
    $this->order->inputs->first()->update(['actual_quantity' => 21]);
    $this->service->complete($this->order, [$this->mainId => 18.5]);
    $input = $this->order->inputs->first()->fresh();
    expect($input->planned_quantity)->toBe('20.000')->and($input->actual_quantity)->toBe('21.000')
        ->and($input->unit_cost)->toBe('90.0000')->and($input->total_cost)->toBe('1890.00')
        ->and($this->order->fresh()->total_input_cost)->toBe('1930.00');
});

it('under average mixes the output with stock already held across warehouses', function () {
    app(SettingsService::class)->set('costing_method', 'weighted_average');
    InventoryItem::where('product_id', $this->marinated->id)->update(['quantity' => 10]);
    $this->service->complete($this->order, [$this->mainId => 18.5]);
    expect((float) $this->marinated->fresh()->cost)->toBe(75.0877)
        ->and($this->order->fresh()->outputs->sole()->unit_cost)->toBe('88.6486');
});

it('under off records 88.6486 on the movement while keeping product cost unchanged', function () {
    app(SettingsService::class)->set('costing_method', 'off');
    $this->service->complete($this->order, [$this->mainId => 18.5]);
    expect((float) $this->marinated->fresh()->cost)->toBe(50.0)
        ->and((float) InventoryItemMovement::where('reason', MovementReason::MANUFACTURING_IN)->sole()->unit_cost)->toBe(88.6486);
});

it('works with the same source and destination warehouse', function () {
    $order = $this->service->createFromRecipe($this->recipe, $this->source->id, $this->source->id, 19);
    $this->service->complete($order, [$order->outputs->sole()->id => 18.5]);
    expect(InventoryItemMovement::distinct()->pluck('warehouse_id')->all())->toBe([$this->source->id])
        ->and((float) InventoryItem::where('product_id', $this->marinated->id)->sole()->quantity)->toBe(18.5);
});

it('requires the main actual quantity to be above zero', function (float $quantity) {
    expect(fn () => $this->service->complete($this->order, [$this->mainId => $quantity]))
        ->toThrow(ManufacturingException::class, 'أكبر من صفر');
    expect(InventoryItemMovement::count())->toBe(0)->and($this->order->fresh()->closed_at)->toBeNull();
})->with([0, -1, 0.0001]);

it('rejects missing foreign and nonnumeric actual output quantities', function (string $case) {
    $quantities = match ($case) {
        'missing' => [], 'foreign' => [$this->mainId => 18.5, 99999 => 1],
        'text' => [$this->mainId => 'invalid'], 'infinite' => [$this->mainId => INF],
    };
    expect(fn () => $this->service->complete($this->order, $quantities))->toThrow(ManufacturingException::class);
    expect(InventoryItemMovement::count())->toBe(0);
})->with(['missing', 'foreign', 'text', 'infinite']);

it('uses the open business day even after midnight', function () {
    \App\Models\InventoryItemMovementDaily::query()->update(['date' => now()->subDay()->toDateString()]);
    $this->service->complete($this->order, [$this->mainId => 18.5]);
    expect($this->order->fresh()->business_date->toDateString())->toBe(now()->subDay()->toDateString());
});

it('is read only once completed including stale instances child lines deletion and repeat completion', function () {
    $stale = $this->order->fresh();
    $input = $this->order->inputs->first();
    $this->service->complete($this->order, [$this->mainId => 18.5]);
    expect(fn () => $stale->update(['notes' => 'changed']))->toThrow(ManufacturingException::class);
    expect(fn () => $stale->delete())->toThrow(ManufacturingException::class);
    expect(fn () => $input->update(['actual_quantity' => 99]))->toThrow(ManufacturingException::class);
    expect(fn () => $input->delete())->toThrow(ManufacturingException::class);
    expect(fn () => $stale->inputs()->create(['product_id' => $this->sauce->id, 'actual_quantity' => 1]))->toThrow(ManufacturingException::class);
    expect(fn () => $stale->outputs->sole()->update(['actual_quantity' => 99]))->toThrow(ManufacturingException::class);
    expect(fn () => $this->service->complete($stale, [$this->mainId => 18.5]))->toThrow(ManufacturingException::class);
    expect(InventoryItemMovement::count())->toBe(3);
    $policy = new ManufacturingOrderPolicy;
    expect($policy->update(auth()->user(), $stale->fresh()))->toBeFalse()
        ->and($policy->delete(auth()->user(), $stale->fresh()))->toBeFalse();
    livewire(EditManufacturingOrder::class, ['record' => $stale->id])->assertForbidden();
});

it('allocates fixed then percentage then remainder and skips zero output movements', function () {
    $bones = Product::factory()->create(['type' => 'raw_material']);
    $skin = Product::factory()->create(['type' => 'raw_material']);
    $thighs = Product::factory()->create(['type' => 'raw_material']);
    $unused = Product::factory()->create(['type' => 'raw_material']);
    $this->chicken->update(['cost' => 37]);
    foreach ([[$bones, 1, 'fixed', 10], [$thighs, 4.5, 'fixed', 60], [$skin, 0.5, 'percent', 4], [$unused, 1, 'percent', 10]] as [$product, $expected, $method, $value]) {
        $this->recipe->outputs()->create(['product_id' => $product->id, 'expected_quantity' => $expected, 'cost_method' => $method, 'cost_value' => $value]);
    }
    $order = $this->service->createFromRecipe($this->recipe, $this->source->id, $this->destination->id, 19);
    $quantities = $order->outputs->mapWithKeys(fn ($line) => [$line->id => $line->is_main ? 4 : ($line->product_id === $unused->id ? 0 : (float) $line->expected_quantity)])->all();
    $this->service->complete($order, $quantities);
    $outputs = $order->fresh()->outputs->keyBy('product_id');
    expect($outputs[$bones->id]->allocated_cost)->toBe('10.00')
        ->and($outputs[$thighs->id]->allocated_cost)->toBe('270.00')
        ->and($outputs[$skin->id]->allocated_cost)->toBe('20.00')
        ->and($outputs[$this->marinated->id]->allocated_cost)->toBe('480.00')
        ->and($outputs[$unused->id]->allocated_cost)->toBe('0.00')
        ->and(InventoryItemMovement::where('reason', MovementReason::MANUFACTURING_IN)->count())->toBe(4);
});

it('rolls back stock movements line costs and closure if allocation exceeds the pot', function () {
    $extra = Product::factory()->create(['type' => 'raw_material']);
    $this->recipe->outputs()->create(['product_id' => $extra->id, 'expected_quantity' => 1, 'cost_method' => 'fixed', 'cost_value' => 2000]);
    $order = $this->service->createFromRecipe($this->recipe, $this->source->id, $this->destination->id, 19);
    expect(fn () => $this->service->complete($order, $order->outputs->mapWithKeys(fn ($line) => [$line->id => (float) $line->expected_quantity])->all()))
        ->toThrow(ManufacturingException::class);
    expect(InventoryItemMovement::count())->toBe(0)->and($order->fresh()->closed_at)->toBeNull()
        ->and($order->fresh()->total_input_cost)->toBe('0.00')
        ->and($order->inputs->first()->fresh()->total_cost)->toBe('0.00')
        ->and((float) InventoryItem::where('product_id', $this->chicken->id)->sole()->quantity)->toBe(30.0);
});

it('rolls back received stock product costs and history if a later receipt fails', function () {
    $extra = Product::factory()->create(['type' => 'raw_material', 'cost' => 7]);
    $this->recipe->outputs()->create(['product_id' => $extra->id, 'expected_quantity' => 1, 'cost_method' => 'percent', 'cost_value' => 10]);
    $order = $this->service->createFromRecipe($this->recipe, $this->source->id, $this->destination->id, 19);
    $historyCount = ProductCostHistory::count();
    \Illuminate\Support\Facades\Event::listen('eloquent.creating: '.InventoryItemMovement::class, function ($movement) use ($extra) {
        if ($movement->reason === MovementReason::MANUFACTURING_IN && $movement->product_id === $extra->id) {
            throw new RuntimeException('Receipt failed');
        }
    });
    expect(fn () => $this->service->complete($order, $order->outputs->mapWithKeys(fn ($line) => [$line->id => (float) $line->expected_quantity])->all()))
        ->toThrow(\App\Exceptions\StockOperationException::class, 'Receipt failed');
    expect(InventoryItemMovement::count())->toBe(0)
        ->and(ProductCostHistory::count())->toBe($historyCount)
        ->and((float) $this->marinated->fresh()->cost)->toBe(50.0)
        ->and(InventoryItem::where('product_id', $this->marinated->id)->where('warehouse_id', $this->destination->id)->exists())->toBeFalse()
        ->and($order->fresh()->closed_at)->toBeNull();
});

it('costs duplicate input products once and distributes the movement cost back to the lines', function () {
    $this->order->inputs()->create(['product_id' => $this->chicken->id, 'planned_quantity' => 0, 'actual_quantity' => 2]);
    $this->service->complete($this->order, [$this->mainId => 18.5]);
    expect(InventoryItemMovement::where('reason', MovementReason::MANUFACTURING_OUT)->count())->toBe(2)
        ->and($this->order->fresh()->total_input_cost)->toBe('1800.00')
        ->and($this->order->fresh()->inputs->sum('total_cost'))->toBe(1800.0);
});

it('distributes rounded duplicate input costs without creating a negative final line', function () {
    $this->order->inputs()->delete();
    $this->chicken->update(['cost' => 5]);
    for ($i = 0; $i < 4; $i++) {
        $this->order->inputs()->create(['product_id' => $this->chicken->id, 'planned_quantity' => 0, 'actual_quantity' => 0.001]);
    }
    $this->service->complete($this->order, [$this->mainId => 18.5]);
    $inputs = $this->order->fresh()->inputs;
    expect(round($inputs->sum('total_cost'), 2))->toBe(0.02)
        ->and($inputs->every(fn ($line) => (float) $line->total_cost >= 0))->toBeTrue()
        ->and(InventoryItemMovement::where('reason', MovementReason::MANUFACTURING_OUT)->count())->toBe(1);
});

it('saves edited inputs before completing from the edit page', function () {
    $input = $this->order->inputs->first();
    $output = $this->order->outputs->first();
    $undo = \Filament\Forms\Components\Repeater::fake();
    livewire(EditManufacturingOrder::class, ['record' => $this->order->id])
        ->fillForm([
            'inputs' => [
                ['id' => $input->id, 'product_id' => $input->product_id, 'actual_quantity' => 21],
                ['id' => $this->order->inputs[1]->id, 'product_id' => $this->order->inputs[1]->product_id, 'actual_quantity' => 1],
            ],
            'outputs' => [
                ['id' => $output->id, 'product_id' => $output->product_id, 'is_main' => true, 'expected_quantity' => 19, 'actual_quantity' => 18.5],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();
    $undo();
    expect($output->fresh()->actual_quantity)->toBe('18.500');
    livewire(EditManufacturingOrder::class, ['record' => $this->order->id])
        ->callAction('complete')
        ->assertHasNoActionErrors();
    expect($input->fresh()->actual_quantity)->toBe('21.000')
        ->and($input->fresh()->planned_quantity)->toBe('20.000')
        ->and($this->order->fresh()->total_input_cost)->toBe('1720.00')
        ->and($this->order->fresh()->closed_at)->not->toBeNull();
});

it('completes through Filament using prefilled output quantities and displays variance', function () {
    $this->order->outputs->first()->update(['actual_quantity' => 18.5]);
    livewire(ViewManufacturingOrder::class, ['record' => $this->order->id])
        ->assertSuccessful()
        ->callAction('complete')
        ->assertHasNoActionErrors();
    livewire(ViewManufacturingOrder::class, ['record' => $this->order->id])
        ->assertSuccessful()->assertActionHidden('complete')->assertActionHidden('edit')
        ->assertSee('انحراف الكمية')->assertSee('-0.500')->assertSee('٤٤٫٣٢');
});

it('does not expose completion to a user without update permission', function () {
    $user = User::factory()->watcher()->create();
    $user->givePermissionTo('view_manufacturing_orders');
    $this->actingAs($user);
    livewire(ViewManufacturingOrder::class, ['record' => $this->order->id])->assertSuccessful()->assertActionHidden('complete');
    expect($this->order->fresh()->closed_at)->toBeNull();
});

it('shows an Arabic failure notification and keeps the draft when the business day is closed', function () {
    \App\Models\InventoryItemMovementDaily::query()->update(['closed_at' => now()]);
    $this->order->outputs->first()->update(['actual_quantity' => 18.5]);
    livewire(ViewManufacturingOrder::class, ['record' => $this->order->id])
        ->callAction('complete')
        ->assertNotified(\Filament\Notifications\Notification::make()
            ->title('تعذر إكمال التصنيع')
            ->body('فشل في تحديث المخزون: يجب فتح اليوم قبل إجراء أي عمليات على المخزون')->danger());
    expect($this->order->fresh()->closed_at)->toBeNull()->and(InventoryItemMovement::count())->toBe(0);
});
