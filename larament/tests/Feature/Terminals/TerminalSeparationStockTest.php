<?php

use App\Enums\OptionalFeature;
use App\Enums\ProductType;
use App\Enums\SettingKey;
use App\Filament\Pages\VendorConsole;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Terminal;
use App\Models\TerminalSession;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\FeatureService;
use App\Services\Orders\OrderStockConversionService;
use App\Services\SettingsService;
use App\Services\StockService;
use App\Services\StockWarehouseResolver;
use App\Terminals\TerminalSeparation;
use App\Terminals\TerminalSessionResolver;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

function separationOrder(Product $product, ?int $terminalId, float $quantity = 1): Order
{
    $order = Order::factory()->create(['terminal_id' => $terminalId]);
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => $quantity,
        'price' => 40,
        'cost' => 20,
        'total' => 40 * $quantity,
    ]);

    return $order->load('items.product');
}

function separate(bool $multiTerminal = true, bool $separation = true): void
{
    app(SettingsService::class)->set(SettingKey::FEATURE_MULTI_TERMINAL_ENABLED->value, $multiTerminal);
    app(SettingsService::class)->set(SettingKey::FEATURE_TERMINAL_SEPARATION_ENABLED->value, $separation);
}

beforeEach(function () {
    $this->kitchen = Warehouse::factory()->create(['name' => 'المطبخ']);
    $this->branchOne = Warehouse::factory()->create(['name' => 'مخزن فرعي ١']);
    $this->branchTwo = Warehouse::factory()->create(['name' => 'مخزن فرعي ٢']);
    $this->category = Category::factory()->create(['warehouse_id' => $this->kitchen->id]);
    $this->product = Product::factory()->create(['type' => ProductType::Consumable, 'category_id' => $this->category->id, 'warehouse_id' => null]);
    $this->one = Terminal::query()->create(['name' => 'منفذ ١', 'warehouse_id' => $this->branchOne->id]);
    $this->two = Terminal::query()->create(['name' => 'منفذ ٢', 'warehouse_id' => $this->branchTwo->id]);
});

it('issues by category while sales points are not separated', function () {
    separate(separation: false);

    $lines = app(OrderStockConversionService::class)->convertOrderItemsToStockItems(separationOrder($this->product, $this->one->id));

    expect($lines[0]['warehouse_id'])->toBe($this->kitchen->id);
});

it('ignores the separation flag while multiple terminals are off', function () {
    separate(multiTerminal: false);

    expect(app(FeatureService::class)->terminalSeparationEnabled())->toBeFalse();

    $lines = app(OrderStockConversionService::class)->convertOrderItemsToStockItems(separationOrder($this->product, $this->one->id));

    expect($lines[0]['warehouse_id'])->toBe($this->kitchen->id);
});

it('issues every line from the order sales point warehouse when separated, ignoring product and category', function () {
    separate();
    $override = Warehouse::factory()->create(['name' => 'البار']);
    $this->product->update(['warehouse_id' => $override->id]);

    $conversion = app(OrderStockConversionService::class);

    expect($conversion->convertOrderItemsToStockItems(separationOrder($this->product->fresh(), $this->one->id))[0]['warehouse_id'])->toBe($this->branchOne->id)
        ->and($conversion->convertOrderItemsToStockItems(separationOrder($this->product->fresh(), $this->two->id))[0]['warehouse_id'])->toBe($this->branchTwo->id);
});

it('deducts the stock from the sales point warehouse on completion', function () {
    separate();
    openInventoryDay($this->product);

    app(OrderStockConversionService::class)->removeStockForCompletedOrder(separationOrder($this->product, $this->two->id, 3));

    $stock = app(StockService::class);
    expect($stock->getCurrentStock($this->product->id, $this->branchTwo->id))->toBe(-3.0)
        ->and($stock->getCurrentStock($this->product->id, $this->kitchen->id))->toBe(0.0);
});

it('falls back to the default warehouse when the sales point has none or it is stopped', function () {
    separate();
    $this->one->update(['warehouse_id' => null]);
    $this->branchTwo->update(['is_active' => false]);

    $resolver = app(StockWarehouseResolver::class);

    expect($resolver->forTerminal($this->product, $this->one->id))->toBe(Warehouse::default()->id)
        ->and($resolver->forTerminal($this->product, $this->two->id))->toBe(Warehouse::default()->id);
});

it('uses the category rule for an order with no sales point', function () {
    separate();

    expect(app(StockWarehouseResolver::class)->forTerminal($this->product, null))->toBe($this->kitchen->id);
});

it('shows POS stock of the current device sales point', function () {
    separate();
    app(TerminalSessionResolver::class)->setTerminal($this->two);

    expect(app(StockWarehouseResolver::class)->forCurrentDevice($this->product))->toBe($this->branchTwo->id);
});

it('puts web orders on the chosen sales point and its open drawer session', function () {
    separate();
    $user = User::factory()->create();
    $shift = Shift::factory()->create(['user_id' => $user->id, 'closed' => false]);
    $session = TerminalSession::query()->create([
        'shift_id' => $shift->id,
        'terminal_id' => $this->two->id,
        'user_id' => $user->id,
        'opening_cash' => 0,
        'opened_at' => now(),
    ]);
    app(SettingsService::class)->set(SettingKey::WEB_ORDERS_TERMINAL_ID->value, (string) $this->two->id);

    expect(app(TerminalSeparation::class)->assignWebOrder($shift->id))->toBe($this->two->id)
        ->and(app(TerminalSessionResolver::class)->current()?->id)->toBe($session->id);
});

it('sends web orders to the default sales point when none is chosen, and leaves them alone when not separated', function () {
    separate();
    $shift = Shift::factory()->create(['closed' => false]);

    expect(app(TerminalSeparation::class)->assignWebOrder($shift->id))->toBe(Terminal::default()->id);

    separate(separation: false);

    expect(app(TerminalSeparation::class)->assignWebOrder($shift->id))->toBeNull();
});

it('lets the vendor turn separation on and pick the web orders sales point', function () {
    $vendor = User::factory()->vendor()->create();
    actingAs($vendor);

    Livewire::test(VendorConsole::class)
        ->set('data.features.'.OptionalFeature::MULTI_TERMINAL->value, true)
        ->set('data.features.'.OptionalFeature::TERMINAL_SEPARATION->value, true)
        ->set('data.web_orders_terminal_id', $this->two->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(app(FeatureService::class)->terminalSeparationEnabled())->toBeTrue()
        ->and((int) setting(SettingKey::WEB_ORDERS_TERMINAL_ID))->toBe($this->two->id);
});
