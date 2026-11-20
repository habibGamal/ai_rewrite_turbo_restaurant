<?php

use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\OrderReturnItem;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Shift;
use App\Models\User;
use App\Sync\Builders\OrderReturnBuilder;
use Illuminate\Support\Str;

it('builds an order return snapshot with items and refunds', function () {
    pairPlatform();

    $shift = Shift::factory()->create([
        'business_date' => '2026-10-29',
    ]);

    $order = Order::factory()->create([
        'shift_id' => $shift->id,
    ]);

    $product = Product::factory()->create();

    $orderItem = OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 2.0,
        'price' => 75.00,
        'cost' => 30.00,
        'total' => 150.00,
    ]);

    $user = User::factory()->create();

    $orderReturn = OrderReturn::create([
        'order_id' => $order->id,
        'shift_id' => $shift->id,
        'user_id' => $user->id,
        'total_refund' => 150.00,
        'reason' => 'wrong item delivered',
        'reverse_stock' => true,
    ]);

    $returnItem = OrderReturnItem::create([
        'order_return_id' => $orderReturn->id,
        'order_item_id' => $orderItem->id,
        'quantity' => 2.0,
        'refund_amount' => 150.00,
    ]);

    $refund = Refund::create([
        'order_return_id' => $orderReturn->id,
        'amount' => 150.00,
        'method' => PaymentMethod::CASH,
    ]);

    $data = app(OrderReturnBuilder::class)->build($orderReturn->uuid);

    expect($data)->toEqual([
        'order_uuid' => $order->uuid,
        'shift_uuid' => $shift->uuid,
        'terminal_session_uuid' => $orderReturn->terminalSession?->uuid,
        'business_date' => '2026-10-29',
        'total_refund' => '150.00',
        'reason' => 'wrong item delivered',
        'reverse_stock' => true,
        'is_exchange' => false,
        'items' => [
            [
                'kind' => 'item',
                'order_item_uuid' => $orderItem->uuid,
                'order_fee_uuid' => null,
                'order_equipment_uuid' => null,
                'quantity' => '2.000',
                'refund_amount' => '150.00',
                'restocked' => true,
            ],
        ],
        'refunds' => [
            [
                'method' => 'cash',
                'amount' => '150.00',
            ],
        ],
    ]);
});

it('returns null for an unknown order return uuid', function () {
    expect(app(OrderReturnBuilder::class)->build((string) Str::uuid()))->toBeNull();
});
