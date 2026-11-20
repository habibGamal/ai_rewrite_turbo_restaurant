<?php

namespace Tests\Support;

use App\DTOs\Orders\CreateOrderDTO;
use App\Enums\ProductType;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Models\Warehouse;
use App\Orders\OrderOpening;
use App\Services\Orders\OrderService;

/**
 * Fixtures for the Phase 0 safety-net (characterization) tests.
 *
 * These tests pin how the restaurant flows behave today so the modular-core
 * refactor (events, registry, module moves) can be checked against them.
 * They go through the public services and HTTP routes only, never through
 * the internals that the refactor is expected to move.
 */
final class SafetyNet
{
    /**
     * An admin cashier with an open shift. The inventory day is opened per product in product().
     *
     * @return array{user: User, shift: Shift}
     */
    public static function till(): array
    {
        $user = User::factory()->admin()->create();
        $shift = Shift::factory()->active()->create(['user_id' => $user->id]);
        auth()->login($user);

        return ['user' => $user, 'shift' => $shift];
    }

    /**
     * A product that goes to the kitchen (has a category) and is tracked in stock.
     */
    public static function product(float $price = 50, float $cost = 20, float $stock = 100, array $attributes = []): Product
    {
        $category = Category::factory()->create();
        $product = Product::factory()->create(array_merge([
            'category_id' => $category->id,
            'price' => $price,
            'cost' => $cost,
            'type' => ProductType::Consumable,
        ], $attributes));

        InventoryItem::query()->updateOrCreate(
            ['product_id' => $product->id, 'warehouse_id' => Warehouse::default()->id],
            ['quantity' => $stock],
        );
        \openInventoryDay($product);

        return $product;
    }

    public static function stock(Product $product): float
    {
        return (float) InventoryItem::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', Warehouse::default()->id)
            ->value('quantity');
    }

    public static function open(Shift $shift, User $user, string $type = 'takeaway', ?string $table = null): Order
    {
        return app(OrderService::class)->createOrder(new CreateOrderDTO(
            typeCode: $type,
            shiftId: $shift->id,
            userId: $user->id,
            opening: new OrderOpening($table),
        ));
    }

    /**
     * @param  list<array{0: Product, 1: float|int}>  $lines
     */
    public static function save(Order $order, array $lines): Order
    {
        return app(OrderService::class)->updateOrderItems($order->id, array_map(
            fn (array $line): array => ['product_id' => $line[0]->id, 'quantity' => $line[1]],
            $lines,
        ));
    }
}
