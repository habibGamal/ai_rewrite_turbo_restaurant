<?php

use App\DTOs\Orders\CreateOrderDTO;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Exceptions\OrderException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItemChange;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Services\Orders\OrderOpeningService;
use App\Services\Orders\OrderService;

describe('ordering core', function () {
    it('recalculates an open delivery order when an existing customer delivery cost changes', function () {
        Shift::factory()->active()->create();
        $customer = Customer::factory()->create([
            'name' => 'Delivery Customer',
            'phone' => '01000000000',
            'address' => 'Old address',
            'delivery_cost' => 10,
        ]);
        $product = Product::factory()->create([
            'price' => 100,
            'cost' => 40,
        ]);
        $order = Order::factory()->create([
            'type' => OrderType::DELIVERY,
            'status' => OrderStatus::PROCESSING,
            'customer_id' => $customer->id,
            'discount' => 0,
            'temp_discount_percent' => 0,
            'sub_total' => 100,
            'service' => 10,
            'tax' => 0,
            'total' => 110,
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 100,
            'cost' => 40,
            'total' => 100,
            'item_discount' => 0,
        ]);

        $response = $this->post(route('orders.updateCustomer', $order), [
            'name' => 'Delivery Customer',
            'phone' => '01000000000',
            'address' => 'New address',
            'delivery_cost' => 25,
        ]);

        $response->assertRedirect()->assertSessionHas('success');

        $order->refresh();

        // subtotal 100 + delivery fee 25, no tax or discount. ceil(125) = 125.
        expect($order->service)->toBe('25.00')
            ->and($order->total)->toBe('125.00');
    });

    it('keeps percent and fixed item discount totals', function () {
        $percentProduct = Product::factory()->create([
            'price' => 80,
            'cost' => 30,
        ]);
        $fixedProduct = Product::factory()->create([
            'price' => 50,
            'cost' => 20,
        ]);
        $order = Order::factory()->create([
            'type' => OrderType::TAKEAWAY,
            'status' => OrderStatus::PROCESSING,
            'discount' => 0,
            'temp_discount_percent' => 0,
        ]);

        $saved = app(OrderService::class)->updateOrderItems($order->id, [
            [
                'product_id' => $percentProduct->id,
                'quantity' => 2,
                'item_discount' => 40,
                'item_discount_type' => 'percent',
                'item_discount_percent' => 25,
            ],
            [
                'product_id' => $fixedProduct->id,
                'quantity' => 3,
                'item_discount' => 15,
                'item_discount_type' => 'value',
            ],
        ]);

        // Percent: 80 * 2 * 25% = 40, capped at 160.
        // Fixed: min(15, 50 * 3) = 15.
        // sub_total = 160 + 150 = 310. discount = 40 + 15 = 55.
        // service 0, tax 0. total = ceil(310 - 55) = 255.
        // profit = 255 - (30 * 2 + 20 * 3) = 135.
        expect($saved->sub_total)->toBe('310.00')
            ->and($saved->discount)->toBe('55.00')
            ->and($saved->total)->toBe('255.00')
            ->and($saved->profit)->toBe('135.00');
    });

    it('records item changes once per save', function () {
        $order = Order::factory()->create([
            'type' => OrderType::TAKEAWAY,
            'status' => OrderStatus::PROCESSING,
        ]);
        $soup = Product::factory()->create(['name' => 'Soup', 'price' => 20, 'cost' => 8]);
        $salad = Product::factory()->create(['name' => 'Salad', 'price' => 15, 'cost' => 5]);
        $lines = [
            ['product_id' => $soup->id, 'quantity' => 1, 'notes' => null],
            ['product_id' => $salad->id, 'quantity' => 2, 'notes' => 'no onion'],
        ];

        app(OrderService::class)->updateOrderItems($order->id, $lines);

        expect(OrderItemChange::query()->where('order_id', $order->id)->count())->toBe(2);

        app(OrderService::class)->updateOrderItems($order->id, $lines);

        expect(OrderItemChange::query()->where('order_id', $order->id)->count())->toBe(2);
    });

    it('refuses to save items on a completed order', function () {
        $product = Product::factory()->create(['price' => 10, 'cost' => 4]);
        $order = Order::factory()->create([
            'type' => OrderType::TAKEAWAY,
            'status' => OrderStatus::COMPLETED,
        ]);
        $item = $order->items()->create([
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => 10,
            'cost' => 4,
            'total' => 20,
        ]);
        $replacement = Product::factory()->create();

        expect(fn () => app(OrderService::class)->updateOrderItems($order->id, [
            ['product_id' => $replacement->id, 'quantity' => 1],
        ]))->toThrow(OrderException::class, 'لا يمكن تعديل الطلب في هذه المرحلة');

        $order->refresh();

        expect($order->items)->toHaveCount(1)
            ->and($order->items->first()->id)->toBe($item->id)
            ->and($order->items->first()->quantity)->toBe(2.0);
    });

    it('does not open a dine-in order without a table number', function () {
        $user = User::factory()->create();
        $shift = Shift::factory()->create();

        expect(fn () => app(OrderOpeningService::class)->open(new CreateOrderDTO(
            typeCode: OrderType::DINE_IN->value,
            shiftId: $shift->id,
            userId: $user->id,
        )))->toThrow(OrderException::class, 'رقم الطاولة مطلوب');

        expect(Order::query()->count())->toBe(0);
    });
});
