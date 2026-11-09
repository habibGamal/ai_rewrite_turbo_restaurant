<?php

namespace App\Sync\Builders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Sync\Builders\Concerns\IgnoresKey;
use App\Sync\DocumentType;
use App\Sync\Payload;

class OrderBuilder implements DocumentBuilder
{
    use IgnoresKey;

    public function type(): DocumentType
    {
        return DocumentType::Order;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function build(string $uuid): ?array
    {
        /** @var Order|null $order */
        $order = Order::query()
            ->with(['shift', 'user', 'customer', 'driver', 'items.product', 'payments.shift'])
            ->where('uuid', $uuid)
            ->first();

        if (! $order) {
            return null;
        }

        $shift = $order->shift;
        $shiftUuid = $shift ? $shift->getAttribute('uuid') : null;

        $items = [];
        /** @var OrderItem $item */
        foreach ($order->items as $item) {
            $product = $item->product;
            $items[] = [
                'uuid' => (string) $item->getAttribute('uuid'),
                'product_uuid' => $product ? $product->getAttribute('uuid') : null,
                'product_ref' => $product ? $product->getAttribute('product_ref') : null,
                'quantity' => Payload::qty($item->quantity),
                'price' => Payload::money($item->price),
                'unit_cost' => Payload::money($item->cost),
                'item_discount' => Payload::money($item->item_discount ?? 0),
                'item_discount_type' => $item->item_discount_type,
                'total' => Payload::money($item->total),
            ];
        }

        $payments = [];
        /** @var Payment $payment */
        foreach ($order->payments as $payment) {
            $paymentShift = $payment->shift;
            $paymentShiftUuid = $paymentShift ? $paymentShift->getAttribute('uuid') : $shiftUuid;

            $payments[] = [
                'method' => $payment->method->value,
                'amount' => Payload::money($payment->amount),
                'shift_uuid' => $paymentShiftUuid,
                'paid_at' => Payload::ts($payment->created_at),
            ];
        }

        $user = $order->user;
        $driver = $order->driver;
        $customer = $order->customer;

        $driverUuid = $driver ? $driver->getAttribute('uuid') : null;
        $customerPhone = $customer ? (string) $customer->getAttribute('phone') : null;
        $customerRegion = $customer ? (string) $customer->getAttribute('region') : null;

        return [
            'number' => $order->order_number !== null ? (string) $order->order_number : null,
            'shift_uuid' => $shiftUuid,
            'business_date' => Payload::date($shift ? $shift->getAttribute('business_date') : null),
            'type' => $order->type,
            'status' => $order->status->value,
            'payment_status' => $order->payment_status->value,
            'return_status' => $order->return_status?->value,
            'created_at' => Payload::ts($order->created_at),
            'cashier_uuid' => $user ? $user->getAttribute('uuid') : null,
            'driver_uuid' => $driverUuid,
            'customer_hash' => Payload::customerHash($customerPhone),
            'region' => $customerRegion,
            'sub_total' => Payload::money($order->sub_total),
            'discount' => Payload::money($order->discount),
            'tax' => Payload::money($order->tax),
            'service' => Payload::money($order->service),
            'total' => Payload::money($order->total),
            'profit' => Payload::money($order->profit),
            'web_pos_diff' => Payload::money($order->web_pos_diff),
            'items' => $items,
            'payments' => $payments,
        ];
    }
}
