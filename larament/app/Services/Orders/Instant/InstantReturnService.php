<?php

namespace App\Services\Orders\Instant;

use App\DTOs\Orders\InstantSaleData;
use App\Enums\OrderStatus;
use App\Exceptions\OrderException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\OrderReturnItem;
use App\Models\Product;
use App\Services\Orders\OrderReturnService;
use Illuminate\Support\Facades\DB;

/**
 * F10 on the instant-sale screen: return lines of a completed sale and,
 * for an exchange, sell the new lines as a second instant sale in the same
 * transaction. The customer settles only the difference: the refund pays
 * the exchange first, and the rest is paid by the customer or handed back.
 */
class InstantReturnService
{
    public function __construct(
        private readonly OrderReturnService $returns,
        private readonly InstantSaleService $sales,
    ) {}

    public function findCompleted(string $number, int $currentShiftId): ?Order
    {
        $number = trim($number);
        if ($number === '' || ! ctype_digit($number)) {
            return null;
        }

        // A receipt number is per shift: the current shift first, then the id.
        $order = Order::query()
            ->where('shift_id', $currentShiftId)
            ->where('order_number', (int) $number)
            ->where('status', OrderStatus::COMPLETED)
            ->first()
            ?? Order::query()
                ->whereKey((int) $number)
                ->where('status', OrderStatus::COMPLETED)
                ->first();

        return $order instanceof Order ? $order : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(Order $order): array
    {
        $order->load('items.product');
        $returned = OrderReturnItem::query()
            ->whereIn('order_item_id', $order->items->modelKeys())
            ->selectRaw('order_item_id, SUM(quantity) as quantity')
            ->groupBy('order_item_id')
            ->pluck('quantity', 'order_item_id');

        $items = [];
        foreach ($order->items as $item) {
            if (! $item instanceof OrderItem) {
                continue;
            }

            $product = $item->product;
            $items[] = [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'name' => $product instanceof Product ? $product->name : '',
                'quantity' => (float) $item->quantity,
                'price' => (float) $item->price,
                'returnable_quantity' => max(0.0, (float) $item->quantity - (float) ($returned[$item->id] ?? 0)),
                'unit_refund' => $this->unitRefund($order, $item),
            ];
        }

        return [
            'id' => $order->id,
            'order_number' => (int) $order->order_number,
            'created_at' => $order->created_at,
            'total' => (float) $order->total,
            'items' => $items,
        ];
    }

    /**
     * @param  list<array{order_item_id: int, quantity: float}>  $lines
     * @param  array<string, float>  $tenders  what the customer hands over for the difference
     * @param  array{client_uuid: string, type: string, items: list<array<string, mixed>>}|null  $exchange
     * @return array{return: OrderReturn, exchange: ?InstantSaleResult, refund: float, net: float}
     */
    public function process(
        Order $order,
        array $lines,
        string $reason,
        int $shiftId,
        int $userId,
        array $tenders = [],
        ?array $exchange = null,
        string $refundMethod = 'cash',
    ): array {
        if ($order->status !== OrderStatus::COMPLETED) {
            throw new OrderException('لا يمكن إرجاع طلب غير مكتمل');
        }

        if ($lines === []) {
            throw new OrderException('يجب تحديد صنف واحد على الأقل للإرجاع');
        }

        return DB::transaction(function () use ($order, $lines, $reason, $shiftId, $userId, $tenders, $exchange, $refundMethod): array {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $order->load('items');

            $returnItems = [];
            foreach ($lines as $line) {
                $item = $order->items->firstWhere('id', (int) $line['order_item_id']);
                if (! $item instanceof OrderItem) {
                    throw new OrderException('صنف الطلب غير موجود');
                }

                $returnItems[] = [
                    'order_item_id' => $item->id,
                    'quantity' => (float) $line['quantity'],
                    'refund_amount' => round($this->unitRefund($order, $item) * (float) $line['quantity'], 2),
                ];
            }

            $refund = round((float) array_sum(array_column($returnItems, 'refund_amount')), 2);

            try {
                $orderReturn = $this->returns->processReturn(
                    order: $order,
                    returnItems: $returnItems,
                    reason: $reason !== '' ? $reason : ($exchange !== null ? 'استبدال' : 'مرتجع'),
                    refundDistribution: $refund > 0 ? [['method' => $refundMethod, 'amount' => $refund]] : [],
                    shiftId: $shiftId,
                );
            } catch (OrderException $e) {
                throw $e;
            } catch (\Exception $e) {
                throw new OrderException($e->getMessage());
            }

            $sale = null;
            $net = -$refund;

            if ($exchange !== null && $exchange['items'] !== []) {
                // The refund pays the new lines first; the customer's tenders cover the rest.
                $sale = $this->sales->sellInTransaction(new InstantSaleData(
                    clientUuid: $exchange['client_uuid'],
                    typeCode: $exchange['type'],
                    shiftId: $shiftId,
                    userId: $userId,
                    items: $exchange['items'],
                    tenders: $tenders,
                    customerId: $order->customer_id,
                    credit: $refund,
                    creditMethod: $refundMethod,
                ));

                $net = round((float) $sale->order->total - $refund, 2);
            }

            return ['return' => $orderReturn, 'exchange' => $sale, 'refund' => $refund, 'net' => $net];
        });
    }

    /**
     * What one unit of the line refunds: its price after the line discount,
     * scaled by the order-level discount (and the rounding of the total).
     */
    private function unitRefund(Order $order, OrderItem $item): float
    {
        $quantity = (float) $item->quantity;
        if ($quantity <= 0) {
            return 0.0;
        }

        $gross = (float) $order->sub_total + (float) $order->service + (float) $order->tax;
        $ratio = $gross > 0 ? min(1.0, (float) $order->total / $gross) : 1.0;

        return round(((float) $item->total / $quantity) * $ratio, 2);
    }
}
