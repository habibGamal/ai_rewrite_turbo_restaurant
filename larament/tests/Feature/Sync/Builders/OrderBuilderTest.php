<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ReturnStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Sync\Builders\OrderBuilder;
use Carbon\Carbon;
use Illuminate\Support\Str;

it('builds a completed order snapshot with items and payments', function () {
    pairPlatform();

    $shift = Shift::factory()->create([
        'business_date' => '2026-10-29',
    ]);

    $cashier = User::factory()->create();

    $customer = Customer::factory()->create([
        'phone' => '01012345678',
        'region' => 'Maadi',
    ]);

    $order = Order::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $cashier->id,
        'customer_id' => $customer->id,
        'order_number' => 57,
        'status' => OrderStatus::COMPLETED,
        'type' => 'delivery',
        'payment_status' => PaymentStatus::FULL_PAID,
        'return_status' => ReturnStatus::NONE,
        'sub_total' => 420.00,
        'discount' => 20.00,
        'tax' => 0.00,
        'service' => 0.00,
        'total' => 400.00,
        'profit' => 120.00,
        'web_pos_diff' => null,
    ]);

    $order->created_at = Carbon::parse('2026-10-29 21:02:55', 'Africa/Cairo');
    $order->save();

    $product = Product::factory()->create([
        'product_ref' => 'PRD-100',
    ]);

    $orderItem = OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 2.0,
        'price' => 150.00,
        'cost' => 62.25,
        'item_discount' => 0.00,
        'item_discount_type' => null,
        'total' => 300.00,
    ]);

    $payment = Payment::factory()->create([
        'order_id' => $order->id,
        'shift_id' => $shift->id,
        'method' => PaymentMethod::CASH,
        'amount' => 400.00,
    ]);
    $payment->created_at = Carbon::parse('2026-10-29 21:05:00', 'Africa/Cairo');
    $payment->save();

    $data = app(OrderBuilder::class)->build($order->uuid);

    expect($data)->toMatchArray([
        'number' => '57',
        'shift_uuid' => $shift->uuid,
        'business_date' => '2026-10-29',
        'status' => 'completed',
        'type' => 'delivery',
        'payment_status' => 'full_paid',
        'return_status' => 'none',
        'created_at' => '2026-10-29T21:02:55+03:00',
        'cashier_uuid' => $cashier->uuid,
        'driver_uuid' => null,
        'customer_hash' => hash('sha256', '01012345678test-secret'),
        'region' => 'Maadi',
        'sub_total' => '420.00',
        'discount' => '20.00',
        'tax' => '0.00',
        'service' => '0.00',
        'total' => '400.00',
        'profit' => '120.00',
        'web_pos_diff' => null,
        'fees_total' => '0.00',
        'equipment_total' => '0.00',
        'equipment_cost' => '0.00',
        'guests_count' => null,
    ])
        ->and(array_keys($data))->toEqual([
            'number',
            'shift_uuid',
            'business_date',
            'type',
            'status',
            'payment_status',
            'return_status',
            'created_at',
            'cashier_uuid',
            'driver_uuid',
            'customer_hash',
            'region',
            'sub_total',
            'discount',
            'tax',
            'service',
            'total',
            'profit',
            'web_pos_diff',
            'fees_total',
            'equipment_total',
            'equipment_cost',
            'guests_count',
            'items',
            'payments',
            'external_orders',
        ])
        ->and($data['external_orders'])->toBe([])
        ->and($data['items'][0])->toEqual([
            'uuid' => $orderItem->uuid,
            'product_uuid' => $product->uuid,
            'product_ref' => 'PRD-100',
            'quantity' => '2.000',
            'price' => '150.00',
            'unit_cost' => '62.25',
            'item_discount' => '0.00',
            'item_discount_type' => null,
            'total' => '300.00',
        ])
        ->and($data['payments'][0])->toEqual([
            'method' => 'cash',
            'amount' => '400.00',
            'shift_uuid' => $shift->uuid,
            'paid_at' => '2026-10-29T21:05:00+03:00',
        ])
        ->and($data)->not->toHaveKeys(['customer_name', 'customer_phone', 'address', 'password', 'kitchen_notes', 'order_notes'])
        ->and($data['payments'][0])->not->toHaveKey('uuid');
});

it('returns null for an unknown order uuid', function () {
    expect(app(OrderBuilder::class)->build((string) Str::uuid()))->toBeNull();
});
