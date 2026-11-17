<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProductType;
use App\Models\Category;
use App\Models\InventoryItemMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductComponent;
use App\Models\Shift;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Orders\OrderReturnService;
use App\Services\Orders\OrderStockConversionService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function routingOrder(Product $product, float $quantity = 1): Order
{
    $order = paidRoutingOrder(40 * $quantity);
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

/** A completed, fully paid order whose header matches its lines, so it can be returned. */
function paidRoutingOrder(float $total): Order
{
    $order = Order::factory()->create([
        'status' => OrderStatus::COMPLETED,
        'payment_status' => PaymentStatus::FULL_PAID,
        'sub_total' => $total,
        'total' => $total,
        'discount' => 0,
        'temp_discount_percent' => 0,
        'service' => 0,
        'tax' => 0,
        'fees_total' => 0,
        'equipment_total' => 0,
    ]);
    Payment::factory()->create(['order_id' => $order->id, 'shift_id' => $order->shift_id, 'amount' => $total, 'method' => 'cash']);

    return $order;
}

function stockAt(int $productId, int $warehouseId): float
{
    return app(StockService::class)->getCurrentStock($productId, $warehouseId);
}

it('a shish tawook plate (main category Grill) deducts all components from Grill', function () {
    $main = Warehouse::default();
    $grill = Warehouse::factory()->create(['name' => 'الشواية']);
    $category = Category::factory()->create(['name' => 'مشاوي', 'warehouse_id' => $grill->id]);
    $chicken = Product::factory()->create(['name' => 'دجاج متبل', 'type' => ProductType::RawMaterial, 'category_id' => $category->id]);
    $bread = Product::factory()->create(['name' => 'خبز', 'type' => ProductType::RawMaterial, 'category_id' => Category::factory()]);
    $sauce = Product::factory()->create(['name' => 'صوص', 'type' => ProductType::Consumable, 'category_id' => Category::factory()]);
    $plate = Product::factory()->create(['name' => 'طبق شيش طاووق', 'type' => ProductType::Manufactured, 'category_id' => $category->id]);

    ProductComponent::create(['product_id' => $plate->id, 'component_id' => $chicken->id, 'quantity' => 0.2]);
    ProductComponent::create(['product_id' => $plate->id, 'component_id' => $bread->id, 'quantity' => 1]);
    ProductComponent::create(['product_id' => $plate->id, 'component_id' => $sauce->id, 'quantity' => 0.05]);

    $order = routingOrder($plate, 2);
    openInventoryDay($chicken);
    app(OrderStockConversionService::class)->removeStockForCompletedOrder($order);

    expect(stockAt($chicken->id, $grill->id))->toBe(-0.4)
        ->and(stockAt($bread->id, $grill->id))->toBe(-2.0)
        ->and(stockAt($sauce->id, $grill->id))->toBe(-0.1)
        ->and(stockAt($chicken->id, $main->id))->toBe(0.0)
        ->and(stockAt($bread->id, $main->id))->toBe(0.0)
        ->and(stockAt($sauce->id, $main->id))->toBe(0.0);
});

it('lemon mint in Kitchen with product override Bar deducts from Bar', function () {
    $kitchenWarehouse = Warehouse::factory()->create(['name' => 'المطبخ']);
    $bar = Warehouse::factory()->create(['name' => 'البار']);
    $kitchen = Category::factory()->create(['name' => 'المطبخ', 'warehouse_id' => $kitchenWarehouse->id]);
    $drink = Product::factory()->create([
        'name' => 'ليمون نعناع',
        'type' => ProductType::Consumable,
        'category_id' => $kitchen->id,
        'warehouse_id' => $bar->id,
    ]);

    $order = routingOrder($drink, 3);
    openInventoryDay($drink);
    app(OrderStockConversionService::class)->removeStockForCompletedOrder($order);

    expect(stockAt($drink->id, $bar->id))->toBe(-3.0)
        ->and(stockAt($drink->id, $kitchenWarehouse->id))->toBe(0.0);
});

it('nested recipe components use the warehouse of the sold item', function () {
    $grill = Warehouse::factory()->create(['name' => 'الشواية']);
    $prep = Warehouse::factory()->create(['name' => 'التجهيز']);
    $grillCategory = Category::factory()->create(['name' => 'مشاوي', 'warehouse_id' => $grill->id]);
    $prepCategory = Category::factory()->create(['name' => 'تجهيز', 'warehouse_id' => $prep->id]);
    $rawChicken = Product::factory()->create(['name' => 'دجاج خام', 'type' => ProductType::RawMaterial, 'category_id' => $prepCategory->id]);
    $oil = Product::factory()->create(['name' => 'زيت', 'type' => ProductType::RawMaterial, 'category_id' => $prepCategory->id]);
    $marinated = Product::factory()->create(['name' => 'دجاج متبل', 'type' => ProductType::Manufactured, 'category_id' => $prepCategory->id]);
    $plate = Product::factory()->create(['name' => 'طبق شيش طاووق', 'type' => ProductType::Manufactured, 'category_id' => $grillCategory->id]);

    ProductComponent::create(['product_id' => $marinated->id, 'component_id' => $rawChicken->id, 'quantity' => 1]);
    ProductComponent::create(['product_id' => $marinated->id, 'component_id' => $oil->id, 'quantity' => 0.1]);
    ProductComponent::create(['product_id' => $plate->id, 'component_id' => $marinated->id, 'quantity' => 0.25]);

    $lines = app(OrderStockConversionService::class)->convertOrderItemsToStockItems(routingOrder($plate, 2));

    expect($lines)->toHaveCount(2);
    expect(collect($lines)->firstWhere('product_id', $rawChicken->id))->toMatchArray([
        'product_id' => $rawChicken->id,
        'quantity' => 0.5,
        'warehouse_id' => $grill->id,
    ]);
    expect(collect($lines)->firstWhere('product_id', $oil->id)['warehouse_id'])->toBe($grill->id)
        ->and(collect($lines)->firstWhere('product_id', $oil->id)['quantity'])->toBe(0.05);
});

it('keeps the same product in two warehouses as two lines', function () {
    $grill = Warehouse::factory()->create(['name' => 'الشواية']);
    $bar = Warehouse::factory()->create(['name' => 'البار']);
    $grillCategory = Category::factory()->create(['name' => 'مشاوي', 'warehouse_id' => $grill->id]);
    $barCategory = Category::factory()->create(['name' => 'بار', 'warehouse_id' => $bar->id]);
    $bread = Product::factory()->create(['name' => 'خبز', 'type' => ProductType::RawMaterial]);
    $plate = Product::factory()->create(['type' => ProductType::Manufactured, 'category_id' => $grillCategory->id]);
    $drink = Product::factory()->create(['type' => ProductType::Manufactured, 'category_id' => $barCategory->id]);
    ProductComponent::create(['product_id' => $plate->id, 'component_id' => $bread->id, 'quantity' => 1]);
    ProductComponent::create(['product_id' => $drink->id, 'component_id' => $bread->id, 'quantity' => 0.5]);

    $order = paidRoutingOrder(55);
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $plate->id,
        'quantity' => 2,
        'price' => 40,
        'cost' => 20,
        'total' => 80,
    ]);
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $drink->id,
        'quantity' => 2,
        'price' => 15,
        'cost' => 5,
        'total' => 30,
    ]);

    $lines = collect(app(OrderStockConversionService::class)->convertOrderItemsToStockItems($order->load('items.product')))
        ->where('product_id', $bread->id)
        ->values();

    expect($lines)->toHaveCount(2)
        ->and($lines->firstWhere('warehouse_id', $grill->id)['quantity'])->toBe(2.0)
        ->and($lines->firstWhere('warehouse_id', $bar->id)['quantity'])->toBe(1.0);
});

it('falls back to default when the routed warehouse is inactive', function () {
    $grill = Warehouse::factory()->create(['name' => 'الشواية']);
    $category = Category::factory()->create(['name' => 'مشاوي', 'warehouse_id' => $grill->id]);
    $chicken = Product::factory()->create(['type' => ProductType::RawMaterial, 'category_id' => $category->id]);
    $plate = Product::factory()->create(['type' => ProductType::Manufactured, 'category_id' => $category->id]);
    ProductComponent::create(['product_id' => $plate->id, 'component_id' => $chicken->id, 'quantity' => 1]);

    $grill->update(['is_active' => false]);

    $order = routingOrder($plate);
    openInventoryDay($chicken);
    expect(app(OrderStockConversionService::class)->removeStockForCompletedOrder($order))->toBeTrue();

    expect(stockAt($chicken->id, Warehouse::default()->id))->toBe(-1.0)
        ->and(stockAt($chicken->id, $grill->id))->toBe(0.0);
});

it('cancel returns stock to the warehouse it was taken from, even after routing changed', function () {
    $grill = Warehouse::factory()->create(['name' => 'الشواية']);
    $bar = Warehouse::factory()->create(['name' => 'البار']);
    $category = Category::factory()->create(['name' => 'مشاوي', 'warehouse_id' => $grill->id]);
    $chicken = Product::factory()->create(['type' => ProductType::RawMaterial, 'category_id' => $category->id]);
    $plate = Product::factory()->create(['type' => ProductType::Manufactured, 'category_id' => $category->id]);
    ProductComponent::create(['product_id' => $plate->id, 'component_id' => $chicken->id, 'quantity' => 0.2]);

    $order = routingOrder($plate, 2);
    openInventoryDay($chicken);
    $conversion = app(OrderStockConversionService::class);
    $conversion->removeStockForCompletedOrder($order);

    $category->update(['warehouse_id' => $bar->id]);
    $order->unsetRelation('items');
    expect($conversion->addStockForCancelledOrder($order->fresh()->load('items.product')))->toBeTrue();

    expect(stockAt($chicken->id, $grill->id))->toBe(0.0)
        ->and(stockAt($chicken->id, $bar->id))->toBe(0.0)
        ->and(InventoryItemMovement::query()->where('reason', 'order_return')->where('product_id', $chicken->id)->value('warehouse_id'))->toBe($grill->id);
});

it('partial return uses the warehouse of the original sale movement', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $shift = Shift::factory()->create(['user_id' => $user->id, 'closed' => false]);

    $grill = Warehouse::factory()->create(['name' => 'الشواية']);
    $bar = Warehouse::factory()->create(['name' => 'البار']);
    $category = Category::factory()->create(['name' => 'مشاوي', 'warehouse_id' => $grill->id]);
    $drink = Product::factory()->create([
        'name' => 'ليمون نعناع',
        'type' => ProductType::Consumable,
        'category_id' => $category->id,
        'price' => 20,
    ]);

    $order = routingOrder($drink, 2);
    openInventoryDay($drink);
    app(OrderStockConversionService::class)->removeStockForCompletedOrder($order);
    $category->update(['warehouse_id' => $bar->id]);

    $item = $order->items->first();
    app(OrderReturnService::class)->processReturn(
        $order->fresh(),
        [['order_item_id' => $item->id, 'quantity' => 1, 'refund_amount' => 20]],
        'مرتجع جزئي',
        [['method' => 'cash', 'amount' => 20]],
        $shift->id,
    );

    expect(stockAt($drink->id, $grill->id))->toBe(-1.0)
        ->and(stockAt($drink->id, $bar->id))->toBe(0.0);
});

it('partial return of a product split across warehouses uses the first sale movement', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $shift = Shift::factory()->create(['user_id' => $user->id, 'closed' => false]);

    $grill = Warehouse::factory()->create(['name' => 'الشواية']);
    $bar = Warehouse::factory()->create(['name' => 'البار']);
    $grillCategory = Category::factory()->create(['name' => 'مشاوي', 'warehouse_id' => $grill->id]);
    $barCategory = Category::factory()->create(['name' => 'بار', 'warehouse_id' => $bar->id]);
    $bread = Product::factory()->create(['name' => 'خبز', 'type' => ProductType::RawMaterial, 'cost' => 2]);
    $plate = Product::factory()->create(['type' => ProductType::Manufactured, 'category_id' => $grillCategory->id, 'price' => 40]);
    $drink = Product::factory()->create(['type' => ProductType::Manufactured, 'category_id' => $barCategory->id, 'price' => 15]);
    ProductComponent::create(['product_id' => $plate->id, 'component_id' => $bread->id, 'quantity' => 1]);
    ProductComponent::create(['product_id' => $drink->id, 'component_id' => $bread->id, 'quantity' => 1]);

    $order = paidRoutingOrder(55);
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $plate->id,
        'quantity' => 1,
        'price' => 40,
        'cost' => 10,
        'total' => 40,
    ]);
    $drinkItem = OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $drink->id,
        'quantity' => 1,
        'price' => 15,
        'cost' => 5,
        'total' => 15,
    ]);

    openInventoryDay($bread);
    app(OrderStockConversionService::class)->removeStockForCompletedOrder($order->load('items.product'));

    $barCategory->update(['warehouse_id' => $grill->id]);

    app(OrderReturnService::class)->processReturn(
        $order->fresh(),
        [['order_item_id' => $drinkItem->id, 'quantity' => 1, 'refund_amount' => 15]],
        'مرتجع جزئي',
        [['method' => 'cash', 'amount' => 15]],
        $shift->id,
    );

    expect(stockAt($bread->id, $grill->id))->toBe(0.0)
        ->and(stockAt($bread->id, $bar->id))->toBe(-1.0);
});

it('partial return falls back to stockWarehouseId when no original movement exists', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $shift = Shift::factory()->create(['user_id' => $user->id, 'closed' => false]);
    $grill = Warehouse::factory()->create(['name' => 'الشواية']);
    $category = Category::factory()->create(['name' => 'مشاوي', 'warehouse_id' => $grill->id]);
    $drink = Product::factory()->create([
        'name' => 'ليمون نعناع',
        'type' => ProductType::Consumable,
        'category_id' => $category->id,
        'price' => 20,
    ]);
    $order = routingOrder($drink, 1);
    openInventoryDay($drink);

    app(OrderReturnService::class)->processReturn(
        $order,
        [['order_item_id' => $order->items->first()->id, 'quantity' => 1, 'refund_amount' => 20]],
        'مرتجع بلا حركة أصلية',
        [['method' => 'cash', 'amount' => 20]],
        $shift->id,
    );

    expect(stockAt($drink->id, $grill->id))->toBe(1.0)
        ->and(stockAt($drink->id, Warehouse::default()->id))->toBe(0.0);
});
