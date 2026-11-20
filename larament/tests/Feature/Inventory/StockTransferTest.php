<?php

use App\Costing\CostingStrategy;
use App\Enums\MovementReason;
use App\Filament\Resources\StockTransfers\Pages\CreateStockTransfer;
use App\Filament\Resources\StockTransfers\Pages\EditStockTransfer;
use App\Filament\Resources\StockTransfers\Pages\ListStockTransfers;
use App\Filament\Resources\StockTransfers\Pages\ViewStockTransfer;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use App\Models\InventoryItem;
use App\Models\InventoryItemMovement;
use App\Models\InventoryItemMovementDaily;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use App\Policies\StockTransferPolicy;
use App\Services\InventoryDailyAggregationService;
use App\Services\Resources\StockTransferCalculatorService;
use App\Services\StockService;
use App\Services\StockTransferService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->product = Product::factory()->create(['name' => 'دجاج متبل', 'type' => 'raw_material', 'cost' => 85]);
    $this->prep = Warehouse::factory()->create(['name' => 'التجهيز']);
    $this->grill = Warehouse::factory()->create(['name' => 'الشواية']);
    openInventoryDay($this->product);
});

function transferDraft(Product $product, Warehouse $from, Warehouse $to, float $quantity = 10): StockTransfer
{
    $transfer = StockTransfer::factory()->create(['from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id]);
    $transfer->items()->create(['product_id' => $product->id, 'quantity' => $quantity]);

    return $transfer;
}

it('moves 10 kg from Prep to Grill at 85 with matching 850 movements and unchanged cost', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $transfer = transferDraft($this->product, $this->prep, $this->grill);
    $strategy = Mockery::mock(CostingStrategy::class);
    $strategy->shouldNotReceive('receive', 'consume', 'reverse', 'currentUnitCost');
    app()->instance(CostingStrategy::class, $strategy);
    app(StockTransferService::class)->confirm($transfer);

    $movements = $transfer->movements()->orderBy('id')->get();
    expect($movements)->toHaveCount(2)
        ->and($movements[0]->reason)->toBe(MovementReason::TRANSFER_OUT)
        ->and($movements[1]->reason)->toBe(MovementReason::TRANSFER_IN)
        ->and($movements->pluck('unit_cost')->map(fn ($v) => (float) $v)->all())->toBe([85.0, 85.0])
        ->and($movements->pluck('total_cost')->map(fn ($v) => (float) $v)->all())->toBe([850.0, 850.0])
        ->and($movements->pluck('user_id')->unique()->all())->toBe([$user->id])
        ->and((float) $this->product->fresh()->cost)->toBe(85.0)
        ->and((float) $transfer->fresh()->total_cost)->toBe(850.0)
        ->and($transfer->fresh()->closed_by)->toBe($user->id)
        ->and($transfer->fresh()->business_date->toDateString())->toBe(today()->toDateString());
    $item = $transfer->items()->sole();
    expect((float) $item->unit_cost)->toBe(85.0)->and((float) $item->total_cost)->toBe(850.0);
});

it('allows Prep 3 to send 5 leaving minus 2 while the all warehouse total is unchanged', function () {
    InventoryItem::create(['product_id' => $this->product->id, 'warehouse_id' => $this->prep->id, 'quantity' => 3]);
    $transfer = transferDraft($this->product, $this->prep, $this->grill, 5);
    app(StockTransferService::class)->confirm($transfer);
    $stock = app(StockService::class);
    expect($stock->getCurrentStock($this->product->id, $this->prep->id))->toBe(-2.0)
        ->and($stock->getCurrentStock($this->product->id, $this->grill->id))->toBe(5.0)
        ->and($stock->getCurrentStock($this->product->id))->toBe(3.0);
});

it('rejects the same source and destination', function () {
    $transfer = transferDraft($this->product, $this->prep, $this->prep);
    expect(fn () => app(StockTransferService::class)->confirm($transfer))->toThrow(ValidationException::class);
    expect(InventoryItemMovement::count())->toBe(0);
});

it('rejects manufactured products', function () {
    $menu = Product::factory()->create(['type' => 'manufactured']);
    $transfer = transferDraft($menu, $this->prep, $this->grill);
    expect(fn () => app(StockTransferService::class)->confirm($transfer))->toThrow(ValidationException::class);
});

it('requires at least one positive quantity line', function () {
    $transfer = StockTransfer::factory()->create();
    expect(fn () => app(StockTransferService::class)->confirm($transfer))->toThrow(ValidationException::class);
    $transfer->items()->create(['product_id' => $this->product->id, 'quantity' => 0]);
    expect(fn () => app(StockTransferService::class)->confirm($transfer))->toThrow(ValidationException::class);
});

it('cannot edit delete reparent or confirm an already confirmed transfer even through stale models', function () {
    $transfer = transferDraft($this->product, $this->prep, $this->grill);
    $stale = $transfer->fresh();
    $item = $transfer->items()->sole();
    app(StockTransferService::class)->confirm($transfer);
    expect(fn () => app(StockTransferService::class)->confirm($stale))->toThrow(ValidationException::class);
    expect(fn () => $stale->update(['notes' => 'changed']))->toThrow(ValidationException::class);
    expect(fn () => $stale->delete())->toThrow(ValidationException::class);
    expect(fn () => $item->update(['quantity' => 5]))->toThrow(ValidationException::class);
    expect(fn () => $item->delete())->toThrow(ValidationException::class);
    $draft = StockTransfer::factory()->create();
    expect(fn () => $item->update(['stock_transfer_id' => $draft->id]))->toThrow(ValidationException::class);
    expect(fn () => $transfer->items()->create(['product_id' => $this->product->id, 'quantity' => 1]))->toThrow(ValidationException::class);
});

it('blocks closing the day with a draft transfer and counts both transfer aggregation quantities', function () {
    $transfer = transferDraft($this->product, $this->prep, $this->grill);
    $daily = app(InventoryDailyAggregationService::class);
    expect(fn () => $daily->closeDay())->toThrow(Exception::class, 'إذن تحويل مفتوح');
    app(StockTransferService::class)->confirm($transfer);
    $daily->closeDay();
    expect((float) InventoryItemMovementDaily::where('product_id', $this->product->id)->where('warehouse_id', $this->prep->id)->sole()->transfer_out_quantity)->toBe(10.0)
        ->and((float) InventoryItemMovementDaily::where('product_id', $this->product->id)->where('warehouse_id', $this->grill->id)->sole()->transfer_in_quantity)->toBe(10.0);
});

it('rolls back both sides and the document when the destination movement fails', function () {
    $transfer = transferDraft($this->product, $this->prep, $this->grill);
    Event::listen('eloquent.creating: '.InventoryItemMovement::class, function ($movement) {
        if ($movement->reason === MovementReason::TRANSFER_IN) {
            throw new RuntimeException('destination failed');
        }
    });
    try {
        expect(fn () => app(StockTransferService::class)->confirm($transfer))->toThrow(Exception::class, 'destination failed');
    } finally {
        Event::forget('eloquent.creating: '.InventoryItemMovement::class);
    }
    expect(InventoryItemMovement::count())->toBe(0)
        ->and(app(StockService::class)->getCurrentStock($this->product->id, $this->prep->id))->toBe(0.0)
        ->and($transfer->fresh()->closed_at)->toBeNull();
});

it('merges duplicate lines and allocates rounded movement value across their document lines', function () {
    $this->product->update(['cost' => 0.3333]);
    $transfer = transferDraft($this->product, $this->prep, $this->grill, 1);
    $transfer->items()->create(['product_id' => $this->product->id, 'quantity' => 1]);
    app(StockTransferService::class)->confirm($transfer);
    expect($transfer->movements()->count())->toBe(2)
        ->and((float) $transfer->fresh()->total_cost)->toBe(0.67)
        ->and((float) $transfer->items()->sum('total_cost'))->toBe(0.67);
});

it('returns paired movements for a directly transferred consumable at current product cost', function () {
    $product = Product::factory()->create(['type' => 'consumable', 'cost' => 12.3456]);
    $transfer = StockTransfer::factory()->create(['from_warehouse_id' => $this->prep->id, 'to_warehouse_id' => $this->grill->id]);
    $movements = app(StockService::class)->transfer($this->prep->id, $this->grill->id, [
        ['product_id' => $product->id, 'quantity' => 2, 'unit_cost' => 999],
    ], $transfer);
    expect($movements)->toHaveCount(2)
        ->and($movements->pluck('unit_cost')->map(fn ($cost) => (float) $cost)->all())->toBe([12.3456, 12.3456])
        ->and($movements->pluck('total_cost')->map(fn ($cost) => (float) $cost)->all())->toBe([24.69, 24.69]);
});

it('keeps the open business date when a transfer is confirmed after midnight', function () {
    $businessDate = today()->subDay()->toDateString();
    InventoryItemMovementDaily::query()->update(['date' => $businessDate]);
    $transfer = transferDraft($this->product, $this->prep, $this->grill);
    app(StockTransferService::class)->confirm($transfer);
    expect($transfer->business_date->toDateString())->toBe($businessDate);
});

it('saves edited draft lines before confirming from the edit page', function () {
    $this->actingAs(User::factory()->admin()->create());
    $transfer = transferDraft($this->product, $this->prep, $this->grill);
    $undo = Repeater::fake();
    livewire(EditStockTransfer::class, ['record' => $transfer->id])
        ->fillForm(['notes' => 'كمية معدلة', 'items' => [
            'record-'.$transfer->items()->sole()->id => ['product_id' => $this->product->id, 'quantity' => 5],
        ]])
        ->callAction('confirm')->assertHasNoFormErrors();
    $undo();
    expect((float) $transfer->fresh()->total_cost)->toBe(425.0)
        ->and((float) $transfer->items()->sole()->quantity)->toBe(5.0)
        ->and($transfer->fresh()->notes)->toBe('كمية معدلة');
});

it('manages transfer drafts and confirmation in Filament and respects update permissions', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $viewer = User::factory()->viewer()->create();
    $viewer->givePermissionTo('view_stock_transfers');
    $policy = Gate::getPolicyFor(StockTransfer::class);
    expect($policy)->toBeInstanceOf(StockTransferPolicy::class)->and($policy->create($viewer))->toBeFalse();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    livewire(ListStockTransfers::class)->assertSuccessful();
    $undo = Repeater::fake();
    livewire(CreateStockTransfer::class)->fillForm([
        'from_warehouse_id' => $this->prep->id, 'to_warehouse_id' => $this->grill->id,
        'user_id' => $admin->id, 'items' => [['product_id' => $this->product->id, 'quantity' => 10]],
    ])->call('create')->assertHasNoFormErrors();
    $undo();
    $transfer = StockTransfer::sole();
    livewire(EditStockTransfer::class, ['record' => $transfer->id])->assertSuccessful();
    $this->actingAs($viewer);
    expect(StockTransferResource::canEdit($transfer))->toBeFalse();
    livewire(ViewStockTransfer::class, ['record' => $transfer->id])->assertActionHidden('confirm');
    $this->actingAs($admin);
    livewire(ViewStockTransfer::class, ['record' => $transfer->id])->callAction('confirm');
    expect($transfer->fresh()->closed_at)->not->toBeNull();
    livewire(EditStockTransfer::class, ['record' => $transfer->id])->assertForbidden();
    livewire(ViewStockTransfer::class, ['record' => $transfer->id])->assertSuccessful()->assertActionHidden('confirm');
});

it('calculates stock transfer totals using StockTransferCalculatorService', function () {
    $rice = Product::factory()->create(['cost' => 30]);
    $meat = Product::factory()->create(['cost' => 150]);

    $items = [
        ['product_id' => $rice->id, 'quantity' => 5, 'unit_cost' => 30],
        ['product_id' => $meat->id, 'quantity' => 2, 'unit_cost' => 150],
    ];

    expect(StockTransferCalculatorService::calculateTotal($items))->toBe(450.0);

    $prepared = StockTransferCalculatorService::prepareItemData([
        'product_id' => $rice->id,
        'quantity' => 4,
        'unit_cost' => 30,
        'price' => 30,
        'product_name' => 'Rice',
        'unit' => 'kg',
        'stock_quantity' => 20,
    ]);

    expect($prepared)->toHaveKeys(['product_id', 'quantity', 'unit_cost', 'total_cost'])
        ->and($prepared['total_cost'])->toBe(120.0)
        ->and($prepared)->not->toHaveKey('product_name')
        ->and($prepared)->not->toHaveKey('unit')
        ->and($prepared)->not->toHaveKey('stock_quantity');
});

it('can add products to stock transfer using product selector component', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    livewire(CreateStockTransfer::class)
        ->set('data.from_warehouse_id', $this->prep->id)
        ->set('data.to_warehouse_id', $this->grill->id)
        ->set('data.product_selector', $this->product->id)
        ->assertSet('data.items.0.product_id', $this->product->id)
        ->assertSet('data.items.0.quantity', 1)
        ->assertSet('data.items.0.unit_cost', 85)
        ->assertSet('data.total_cost', 85);
});

it('reverses the transfer migration cleanly', function () {
    $migration = require database_path('migrations/2026_11_10_220001_create_stock_transfers_tables.php');
    $migration->down();
    expect(Schema::hasTable('stock_transfers'))->toBeFalse();
    $migration->up();
    expect(Schema::hasTable('stock_transfer_items'))->toBeTrue();
});
