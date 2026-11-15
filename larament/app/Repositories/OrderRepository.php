<?php

namespace App\Repositories;

use App\DTOs\Orders\CreateOrderDTO;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Collection;

class OrderRepository
{
    public function create(CreateOrderDTO $orderDTO, int $orderNumber): Order
    {
        return Order::create([
            'type' => $orderDTO->typeCode,
            'shift_id' => $orderDTO->shiftId,
            'user_id' => $orderDTO->userId,
            'dine_table_number' => $orderDTO->opening->tableNumber,
            'customer_id' => $orderDTO->customerId,
            'driver_id' => $orderDTO->driverId,
            'kitchen_notes' => $orderDTO->kitchenNotes,
            'order_notes' => $orderDTO->orderNotes,
            'order_number' => $orderNumber,
            'status' => OrderStatus::PROCESSING,
            'payment_status' => PaymentStatus::PENDING,
            'sub_total' => 0,
            'tax' => 0,
            'service' => 0,
            'discount' => 0,
            'temp_discount_percent' => 0,
            'total' => 0,
            'profit' => 0,
        ]);
    }

    public function findById(int $id): ?Order
    {
        return Order::find($id);
    }

    public function findByIdOrFail(int $id): Order
    {
        return Order::findOrFail($id);
    }

    public function getShiftOrders(int $shiftId): Collection
    {
        return Order::where('shift_id', $shiftId)
            ->with(['customer', 'driver', 'table', 'items.product', 'kitchenTicket.items'])
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Completed credit orders with an outstanding balance (pending or partial_paid) for the given type codes.
     *
     * @param  list<string>  $codes
     * @return Collection<int, Order>
     */
    public function receivablesForTypes(array $codes): Collection
    {
        if ($codes === []) {
            return new Collection;
        }

        return Order::query()
            ->with(['customer', 'payments'])
            ->where('status', OrderStatus::COMPLETED)
            ->whereIn('payment_status', [PaymentStatus::PENDING, PaymentStatus::PARTIAL_PAID])
            ->whereIn('type', $codes)
            ->orderBy('created_at')
            ->get()
            ->filter(function (Order $order): bool {
                $paid = (float) $order->payments->sum('amount');

                return round((float) $order->total - $paid, 2) > 0;
            })
            ->values();
    }

    /**
     * @param  list<string>  $excludedCodes
     */
    public function getNextOrderNumber(int $shiftId, array $excludedCodes): int
    {
        Shift::whereKey($shiftId)->lockForUpdate()->first();

        $lastOrder = Order::where('shift_id', $shiftId)
            ->whereNotIn('type', $excludedCodes)
            ->orderBy('order_number', 'desc')
            ->first();
        $nextNumber = $lastOrder ? $lastOrder->order_number + 1 : 1;
        while (Order::where('shift_id', $shiftId)->where('order_number', $nextNumber)->exists()) {
            $nextNumber++;
        }

        return $nextNumber;
    }

    public function update(Order $order, array $data): bool
    {
        return $order->update($data);
    }

    public function delete(Order $order): bool
    {
        return $order->delete();
    }
}
