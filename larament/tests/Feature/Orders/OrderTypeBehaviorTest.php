<?php

use App\DTOs\Orders\CreateOrderDTO;
use App\Enums\OrderBehavior;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\SettingKey;
use App\Exceptions\OrderException;
use App\Models\Customer;
use App\Models\DineTable;
use App\Models\Order;
use App\Models\OrderTypeRow;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Orders\Behaviors\DineInBehavior;
use App\Orders\OrderOpening;
use App\Orders\OrderTypeCatalog;
use App\Services\Orders\OrderCalculationService;
use App\Services\Orders\OrderService;
use App\Services\SettingsService;

describe('order type catalog', function () {
    it('seeds the seven system rows with their behaviors', function () {
        $expected = [
            'dine_in' => ['label' => 'صالة', 'color' => 'success', 'icon' => 'heroicon-o-home', 'behavior' => OrderBehavior::DINE_IN],
            'takeaway' => ['label' => 'تيك أواي', 'color' => 'info', 'icon' => 'heroicon-o-shopping-bag', 'behavior' => OrderBehavior::COUNTER],
            'delivery' => ['label' => 'دليفري', 'color' => 'danger', 'icon' => 'heroicon-o-truck', 'behavior' => OrderBehavior::DELIVERY],
            'companies' => ['label' => 'شركات', 'color' => 'gray', 'icon' => 'heroicon-o-building-office', 'behavior' => OrderBehavior::COMPANIES],
            'talabat' => ['label' => 'طلبات', 'color' => 'warning', 'icon' => 'heroicon-o-device-phone-mobile', 'behavior' => OrderBehavior::COUNTER],
            'web_delivery' => ['label' => 'اونلاين دليفري', 'color' => 'primary', 'icon' => 'heroicon-o-globe-alt', 'behavior' => OrderBehavior::WEB_DELIVERY],
            'web_takeaway' => ['label' => 'اونلاين تيك أواي', 'color' => 'primary', 'icon' => 'heroicon-o-computer-desktop', 'behavior' => OrderBehavior::WEB_TAKEAWAY],
        ];

        expect(OrderTypeRow::query()->count())->toBe(7);

        foreach ($expected as $code => $attrs) {
            $row = OrderTypeRow::query()->where('code', $code)->first();

            expect($row)->not->toBeNull()
                ->and($row->label)->toBe($attrs['label'])
                ->and($row->color)->toBe($attrs['color'])
                ->and($row->icon)->toBe($attrs['icon'])
                ->and($row->behavior)->toBe($attrs['behavior'])
                ->and($row->is_system)->toBeTrue()
                ->and($row->is_active)->toBeTrue();
        }
    });

    it('resolves a custom dine-in row through the catalog', function () {
        OrderTypeRow::query()->create([
            'code' => 'lounge',
            'label' => 'لاونج',
            'color' => 'success',
            'icon' => 'heroicon-o-home',
            'is_active' => true,
            'is_system' => false,
            'behavior' => OrderBehavior::DINE_IN,
        ]);

        $behavior = app(OrderTypeCatalog::class)->behaviorFor('lounge');

        expect($behavior)->toBeInstanceOf(DineInBehavior::class);
    });

    it('reserves a table for dine-in and leaves tables alone for takeaway', function () {
        $user = User::factory()->create();
        $shift = Shift::factory()->create();

        $dineIn = app(OrderService::class)->createOrder(new CreateOrderDTO(
            typeCode: OrderType::DINE_IN->value,
            shiftId: $shift->id,
            userId: $user->id,
            opening: new OrderOpening('12'),
        ));

        $occupied = DineTable::query()->where('table_number', '12')->first();

        expect($occupied)->not->toBeNull()
            ->and($occupied->order_id)->toBe($dineIn->id);

        $takeaway = app(OrderService::class)->createOrder(new CreateOrderDTO(
            typeCode: OrderType::TAKEAWAY->value,
            shiftId: $shift->id,
            userId: $user->id,
        ));

        expect($takeaway->dine_table_number)->toBeNull()
            ->and(DineTable::query()->count())->toBe(1)
            ->and(DineTable::query()->where('order_id', $takeaway->id)->exists())->toBeFalse();
    });

    it('does not recalculate a web delivery order when it is completed', function () {
        $customer = Customer::factory()->create(['delivery_cost' => 20]);
        $product = Product::factory()->create(['price' => 100, 'cost' => 40]);
        $order = Order::factory()->create([
            'type' => OrderType::WEB_DELIVERY,
            'status' => OrderStatus::PROCESSING,
            'customer_id' => $customer->id,
            'sub_total' => 100,
            'service' => 7,
            'tax' => 0,
            'discount' => 0,
            'total' => 99,
            'profit' => 50,
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 100,
            'cost' => 40,
            'total' => 100,
        ]);

        $completed = app(OrderService::class)->completeOrder($order->id, ['cash' => 99]);

        expect($completed->status)->toBe(OrderStatus::COMPLETED)
            ->and($completed->service)->toBe('7.00')
            ->and($completed->total)->toBe('99.00');
    });

    it('charges dine-in service from the setting and delivery from the customer', function () {
        app(SettingsService::class)->set(SettingKey::DINE_IN_SERVICE_CHARGE->value, '0.10');

        $product = Product::factory()->create(['price' => 100, 'cost' => 40]);
        $dineIn = Order::factory()->create([
            'type' => OrderType::DINE_IN,
            'status' => OrderStatus::PROCESSING,
            'dine_table_number' => '4',
        ]);
        $dineIn->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 100,
            'cost' => 40,
            'total' => 100,
        ]);

        $calculatedDineIn = app(OrderCalculationService::class)->calculateOrderTotals($dineIn);

        expect($calculatedDineIn->service)->toBe('10.00');

        $customer = Customer::factory()->create(['delivery_cost' => 15]);
        $delivery = Order::factory()->create([
            'type' => OrderType::DELIVERY,
            'status' => OrderStatus::PROCESSING,
            'customer_id' => $customer->id,
        ]);
        $delivery->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 100,
            'cost' => 40,
            'total' => 80,
        ]);

        $calculatedDelivery = app(OrderCalculationService::class)->calculateOrderTotals($delivery);

        expect($calculatedDelivery->service)->toBe('15.00');
    });

    it('skips web order numbers when numbering a POS order in the same shift', function () {
        $user = User::factory()->create();
        $shift = Shift::factory()->create();

        Order::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'type' => OrderType::WEB_DELIVERY,
            'order_number' => 1,
        ]);

        $first = app(OrderService::class)->createOrder(new CreateOrderDTO(
            typeCode: OrderType::TAKEAWAY->value,
            shiftId: $shift->id,
            userId: $user->id,
        ));

        expect($first->order_number)->toBe(2);

        Order::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'type' => OrderType::WEB_TAKEAWAY,
            'order_number' => 5,
        ]);

        $second = app(OrderService::class)->createOrder(new CreateOrderDTO(
            typeCode: OrderType::TAKEAWAY->value,
            shiftId: $shift->id,
            userId: $user->id,
        ));

        expect($second->order_number)->toBe(3);
    });

    it('frees the dine-in table when the order is cancelled or completed', function () {
        $user = User::factory()->create();
        $shift = Shift::factory()->create();
        $orders = app(OrderService::class);

        $cancelled = $orders->createOrder(new CreateOrderDTO(
            typeCode: OrderType::DINE_IN->value,
            shiftId: $shift->id,
            userId: $user->id,
            opening: new OrderOpening('3'),
        ));

        $orders->cancelOrder($cancelled->id);

        expect(DineTable::query()->where('table_number', '3')->value('order_id'))->toBeNull();

        $completed = $orders->createOrder(new CreateOrderDTO(
            typeCode: OrderType::DINE_IN->value,
            shiftId: $shift->id,
            userId: $user->id,
            opening: new OrderOpening('8'),
        ));

        $orders->completeOrder($completed->id, ['cash' => 1]);

        expect($completed->fresh()->status)->toBe(OrderStatus::COMPLETED)
            ->and(DineTable::query()->where('table_number', '8')->value('order_id'))->toBeNull();
    });

    it('frees the table when leaving dine-in and keeps the table message when entering it without one', function () {
        $user = User::factory()->create();
        $shift = Shift::factory()->create();
        $orders = app(OrderService::class);

        $dineIn = $orders->createOrder(new CreateOrderDTO(
            typeCode: OrderType::DINE_IN->value,
            shiftId: $shift->id,
            userId: $user->id,
            opening: new OrderOpening('9'),
        ));

        $orders->changeOrderType($dineIn->id, OrderType::TAKEAWAY->value);

        expect($dineIn->fresh()->type)->toBe(OrderType::TAKEAWAY)
            ->and($dineIn->fresh()->dine_table_number)->toBeNull()
            ->and(DineTable::query()->where('table_number', '9')->value('order_id'))->toBeNull();

        $takeaway = $orders->createOrder(new CreateOrderDTO(
            typeCode: OrderType::TAKEAWAY->value,
            shiftId: $shift->id,
            userId: $user->id,
        ));

        expect(fn () => $orders->changeOrderType($takeaway->id, OrderType::DINE_IN->value))
            ->toThrow(OrderException::class, 'رقم الطاولة مطلوب للطلبات الداخلية');

        expect($takeaway->fresh()->type)->toBe(OrderType::TAKEAWAY)
            ->and(DineTable::query()->where('order_id', $takeaway->id)->exists())->toBeFalse();
    });
});
