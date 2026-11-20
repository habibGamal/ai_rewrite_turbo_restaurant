<?php

namespace App\Services\Orders;

use App\Core\Events\OrderReturned;
use App\Enums\OptionalFeature;
use App\Enums\OrderStatus;
use App\Enums\ReturnStatus;
use App\Exceptions\OrderException;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\OrderReturnItem;
use App\Models\Refund;
use App\Services\FeatureService;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderReturnService
{
    public function __construct(
        private readonly OrderStockConversionService $stockConversionService,
        private readonly ReturnRefundCalculator $refunds,
    ) {}

    /**
     * Process an order return
     *
     * A line goes back to stock unless $reverseStock is false or the line says
     * 'restocked' => false (damaged: it already left stock at sale, so no movement).
     *
     * A line is one of:
     *  - an item: ['order_item_id' => int, 'quantity' => float, 'refund_amount' => ?float, 'restocked' => ?bool]
     *    The item refund is at most what the customer paid for those units after
     *    discounts (see ReturnRefundCalculator); left out, it is that amount.
     *  - a custom fee, refunded by amount (in full or in part): ['order_fee_id' => int, 'refund_amount' => float]
     *  - an equipment line, by quantity, the refund at most qty × unit price:
     *    ['order_equipment_id' => int, 'quantity' => int, 'refund_amount' => float]
     * Fee and equipment lines never move stock, and equipment cost stays on the
     * order (it was consumed).
     *
     * @param  array  $returnItems  lines as above
     * @param  array  $refundDistribution  [['method' => string, 'amount' => float], ...]
     *
     * @throws Exception
     */
    public function processReturn(
        Order $order,
        array $returnItems,
        string $reason,
        array $refundDistribution,
        int $shiftId,
        bool $reverseStock = true
    ): OrderReturn {
        shouldDayBeOpen();
        app(FeatureService::class)->assertEnabled(OptionalFeature::RETURNS);

        try {
            DB::beginTransaction();

            // Lock the order so two returns at once can't both refund it.
            $order = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $order->load(['items', 'fees', 'equipmentLines', 'returns.items', 'payments']);

            $this->assertReturnable($order);

            if ($returnItems === []) {
                throw new Exception('يجب تحديد صنف واحد على الأقل للإرجاع');
            }

            // Validate return items (and fill item refunds left out)
            $returnItems = $this->validateReturnItems($order, $returnItems);

            $returnItems = array_map(fn (array $item): array => self::isItemLine($item)
                ? [...$item, 'restocked' => $reverseStock && ($item['restocked'] ?? true)]
                : [
                    ...$item,
                    // A fee is refunded by amount only; quantity stays 0.
                    'quantity' => isset($item['order_fee_id']) ? 0 : $item['quantity'],
                    'restocked' => false,
                ], $returnItems);
            $anyRestocked = collect($returnItems)->contains('restocked', true);

            // Calculate total refund
            $totalRefund = collect($returnItems)->sum('refund_amount');

            // Validate refund distribution
            $this->validateRefundDistribution($refundDistribution, $totalRefund);

            // Create order return record
            $orderReturn = OrderReturn::create([
                'order_id' => $order->id,
                'user_id' => auth()->id(),
                'shift_id' => $shiftId,
                'total_refund' => $totalRefund,
                'reason' => $reason,
                'reverse_stock' => $anyRestocked,
            ]);

            // Create return items
            foreach ($returnItems as $item) {
                OrderReturnItem::create([
                    'order_return_id' => $orderReturn->id,
                    'order_item_id' => $item['order_item_id'] ?? null,
                    'order_fee_id' => $item['order_fee_id'] ?? null,
                    'order_equipment_id' => $item['order_equipment_id'] ?? null,
                    'quantity' => $item['quantity'],
                    'refund_amount' => $item['refund_amount'],
                    'restocked' => $item['restocked'],
                ]);
            }

            // Create refunds
            foreach ($refundDistribution as $refund) {
                Refund::create([
                    'order_return_id' => $orderReturn->id,
                    'amount' => $refund['amount'],
                    'method' => $refund['method'],
                ]);
            }

            // Put the restocked lines back; damaged lines move no stock.
            $restockedItems = array_values(array_filter($returnItems, fn (array $item): bool => self::isItemLine($item) && $item['restocked']));
            if ($restockedItems !== []) {
                $this->reverseStockForReturnedItems($order, $restockedItems);
            }

            // Update order return status
            $this->updateOrderReturnStatus($order);

            OrderReturned::dispatch($orderReturn, $order);

            DB::commit();

            Log::info('Order return processed successfully', [
                'order_id' => $order->id,
                'return_id' => $orderReturn->id,
                'total_refund' => $totalRefund,
                'reverse_stock' => $reverseStock,
            ]);

            return $orderReturn;

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to process order return', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Only a completed order the customer has fully paid can be returned. A
     * return on an order still owing money would refund cash that was never
     * collected while the debt stayed on the customer's account.
     *
     * @throws OrderException
     */
    private function assertReturnable(Order $order): void
    {
        if ($order->status !== OrderStatus::COMPLETED) {
            throw new OrderException('لا يمكن إرجاع طلب غير مكتمل');
        }

        $paid = round((float) $order->payments->sum('amount'), 2);

        if ($paid + 0.005 < round((float) $order->total, 2)) {
            throw new OrderException(sprintf(
                'لا يمكن إرجاع طلب لم يُسدَّد بالكامل (المتبقي %s ج.م). حصّل المتبقي أولاً ثم أجرِ المرتجع.',
                number_format(round((float) $order->total, 2) - $paid, 2),
            ));
        }
    }

    private static function isItemLine(array $item): bool
    {
        return ! isset($item['order_fee_id']) && ! isset($item['order_equipment_id']);
    }

    /**
     * Validate return lines against what is left on each item, fee and
     * equipment line.
     */
    private function validateReturnItems(Order $order, array $returnItems): array
    {
        // Earlier returns plus the lines already checked in this request, so two
        // lines on the same item, fee or equipment cannot over-refund it.
        $returned = collect($order->returns->flatMap->items->all());

        foreach ($returnItems as $index => $item) {
            if (self::isItemLine($item) && ! isset($item['refund_amount']) && isset($item['order_item_id'], $item['quantity'])) {
                $item['refund_amount'] = $this->itemRefundAfter($order, (int) $item['order_item_id'], (float) $item['quantity'], $returned);
                $returnItems[$index] = $item;
            }

            $this->validateReturnLine($order, $item, $returned);
            $returned->push([
                'order_item_id' => self::isItemLine($item) ? ($item['order_item_id'] ?? null) : null,
                'order_fee_id' => $item['order_fee_id'] ?? null,
                'order_equipment_id' => $item['order_equipment_id'] ?? null,
                'quantity' => isset($item['order_fee_id']) ? 0 : (float) ($item['quantity'] ?? 0),
                'refund_amount' => (float) ($item['refund_amount'] ?? 0),
            ]);
        }

        return $returnItems;
    }

    /**
     * The refund for $quantity units of an item line after the given returns.
     *
     * @param  Collection<int, mixed>  $returned
     */
    private function itemRefundAfter(Order $order, int $orderItemId, float $quantity, Collection $returned): float
    {
        $lines = $returned->filter(fn ($line) => (int) data_get($line, 'order_item_id') === $orderItemId);

        return $this->refunds->refundFor(
            $order,
            $orderItemId,
            $quantity,
            (float) $lines->sum(fn ($line) => (float) data_get($line, 'quantity')),
            (float) $lines->sum(fn ($line) => (float) data_get($line, 'refund_amount')),
        );
    }

    /**
     * What returning $quantity units of an item refunds now, after the
     * order's earlier returns.
     */
    public function itemRefund(Order $order, int $orderItemId, float $quantity): float
    {
        $order->load(['items', 'returns.items']);

        return $this->itemRefundAfter($order, $orderItemId, $quantity, collect($order->returns->flatMap->items->all()));
    }

    /**
     * @param  Collection<int, mixed>  $returned
     */
    private function validateReturnLine(Order $order, array $item, Collection $returned): void
    {
        if (isset($item['order_fee_id'], $item['order_equipment_id']) || (isset($item['order_item_id']) && ! self::isItemLine($item))) {
            throw new Exception('سطر المرتجع يجب أن يشير إلى صنف أو رسوم أو معدة واحدة فقط');
        }

        if ((float) ($item['refund_amount'] ?? 0) < 0) {
            throw new Exception('مبلغ الاسترجاع يجب أن يكون موجباً');
        }

        if (isset($item['order_fee_id'])) {
            $fee = $order->fees->firstWhere('id', $item['order_fee_id']);
            if (! $fee) {
                throw new Exception('الرسوم غير موجودة في هذا الطلب');
            }

            $left = round((float) $fee->amount - (float) $returned->where('order_fee_id', $fee->id)->sum('refund_amount'), 2);
            $amount = round((float) $item['refund_amount'], 2);
            if ($amount <= 0) {
                throw new Exception("مبلغ استرجاع الرسوم «{$fee->name}» يجب أن يكون أكبر من صفر");
            }
            if ($amount > $left + 0.001) {
                throw new Exception("مبلغ استرجاع الرسوم «{$fee->name}» أكبر من المتاح. المتاح: {$left}, المطلوب: {$amount}");
            }

            return;
        }

        if (isset($item['order_equipment_id'])) {
            $line = $order->equipmentLines->firstWhere('id', $item['order_equipment_id']);
            if (! $line) {
                throw new Exception('المعدة غير موجودة في هذا الطلب');
            }

            $quantity = (float) ($item['quantity'] ?? 0);
            $left = (int) $line->quantity - (float) $returned->where('order_equipment_id', $line->id)->sum('quantity');
            if ($quantity <= 0 || floor($quantity) !== $quantity) {
                throw new Exception("كمية المعدة «{$line->name}» يجب أن تكون عدداً صحيحاً أكبر من صفر");
            }
            if ($quantity > $left) {
                throw new Exception("الكمية المطلوب إرجاعها للمعدة «{$line->name}» أكبر من المتاح. المتاح: {$left}, المطلوب: {$quantity}");
            }
            $max = round($quantity * (float) $line->unit_price, 2);
            if ((float) $item['refund_amount'] > $max + 0.001) {
                throw new Exception("مبلغ استرجاع المعدة «{$line->name}» أكبر من قيمتها ({$max})");
            }

            return;
        }

        $orderItem = $order->items->firstWhere('id', $item['order_item_id'] ?? null);

        if (! $orderItem) {
            throw new Exception('صنف الطلب غير موجود');
        }

        // Calculate already returned quantity
        $alreadyReturned = $returned
            ->where('order_item_id', $orderItem->id)
            ->sum('quantity');

        $availableForReturn = $orderItem->quantity - $alreadyReturned;

        if ($item['quantity'] > $availableForReturn) {
            throw new Exception(
                "الكمية المطلوب إرجاعها للصنف {$orderItem->product->name} أكبر من المتاح. ".
                "المتاح: {$availableForReturn}, المطلوب: {$item['quantity']}"
            );
        }

        if ($item['quantity'] <= 0) {
            throw new Exception('الكمية يجب أن تكون أكبر من صفر');
        }

        // Never more than the customer paid for these units after discounts.
        $max = $this->itemRefundAfter($order, $orderItem->id, (float) $item['quantity'], $returned);
        if ((float) $item['refund_amount'] > $max + 0.01) {
            throw new Exception(
                "مبلغ استرجاع الصنف {$orderItem->product->name} أكبر من المستحق بعد الخصومات. ".
                "المستحق: {$max}, المطلوب: {$item['refund_amount']}"
            );
        }
    }

    /**
     * Validate that refund distribution sum equals total refund
     */
    private function validateRefundDistribution(array $refundDistribution, float $totalRefund): void
    {
        $distributionSum = collect($refundDistribution)->sum('amount');

        if (abs($distributionSum - $totalRefund) > 0.01) {
            throw new Exception(
                "مجموع توزيع الاسترجاع ({$distributionSum}) لا يساوي إجمالي الاسترجاع ({$totalRefund})"
            );
        }

        foreach ($refundDistribution as $refund) {
            if ($refund['amount'] <= 0) {
                throw new Exception('مبلغ الاسترجاع يجب أن يكون أكبر من صفر');
            }
        }
    }

    /**
     * Reverse stock for returned items
     */
    private function reverseStockForReturnedItems(Order $order, array $returnItems): void
    {
        // Create a temporary order with only returned items for stock calculation
        $tempOrder = new Order;
        $tempOrder->id = $order->id;
        $tempOrder->terminal_id = $order->terminal_id;
        $tempOrder->setRelation('items', collect());

        foreach ($returnItems as $item) {
            $originalItem = $order->items->firstWhere('id', $item['order_item_id']);
            if ($originalItem) {
                $clonedItem = $originalItem->replicate();
                $clonedItem->quantity = $item['quantity'];
                $tempOrder->items->push($clonedItem);
            }
        }

        // Use stock conversion service to add stock back
        $stockItems = $this->stockConversionService->convertOrderItemsToStockItems($tempOrder);

        if (! empty($stockItems)) {
            $this->stockConversionService->addStockForCancelledOrder($tempOrder);
        }
    }

    /**
     * Update order return status: FULL only when every item, fee and
     * equipment line is fully refunded.
     */
    private function updateOrderReturnStatus(Order $order): void
    {
        $order->load(['items', 'fees', 'equipmentLines', 'returns.items']);
        $returned = $order->returns->flatMap->items;

        if ($returned->isEmpty()) {
            $returnStatus = ReturnStatus::NONE;
        } else {
            $itemsLeft = $order->items->sum(fn ($item) => max(0, (float) $item->quantity - (float) $returned->where('order_item_id', $item->id)->sum('quantity')));
            $feesLeft = $order->fees->sum(fn ($fee) => max(0, round((float) $fee->amount - (float) $returned->where('order_fee_id', $fee->id)->sum('refund_amount'), 2)));
            $equipmentLeft = $order->equipmentLines->sum(fn ($line) => max(0, (int) $line->quantity - (float) $returned->where('order_equipment_id', $line->id)->sum('quantity')));

            $returnStatus = ($itemsLeft + $feesLeft + $equipmentLeft) <= 0.001
                ? ReturnStatus::FULL_RETURN
                : ReturnStatus::PARTIAL_RETURN;
        }

        $order->update(['return_status' => $returnStatus]);
    }

    /** What can still be refunded on a fee line, in money. */
    public function getRefundableFeeAmount(Order $order, int $orderFeeId): float
    {
        $order->loadMissing(['fees', 'returns.items']);
        $fee = $order->fees->firstWhere('id', $orderFeeId);
        if (! $fee) {
            return 0;
        }

        return max(0, round((float) $fee->amount - (float) $order->returns->flatMap->items->where('order_fee_id', $orderFeeId)->sum('refund_amount'), 2));
    }

    /** Uses of an equipment line not yet refunded. */
    public function getAvailableEquipmentQuantity(Order $order, int $orderEquipmentId): int
    {
        $order->loadMissing(['equipmentLines', 'returns.items']);
        $line = $order->equipmentLines->firstWhere('id', $orderEquipmentId);
        if (! $line) {
            return 0;
        }

        return max(0, (int) $line->quantity - (int) $order->returns->flatMap->items->where('order_equipment_id', $orderEquipmentId)->sum('quantity'));
    }

    /**
     * Get available quantity for return for a specific order item
     */
    public function getAvailableQuantityForReturn(Order $order, int $orderItemId): float
    {
        $order->load(['items', 'returns.items']);

        $orderItem = $order->items->firstWhere('id', $orderItemId);

        if (! $orderItem) {
            return 0;
        }

        $alreadyReturned = $order->returns->flatMap->items
            ->where('order_item_id', $orderItemId)
            ->sum('quantity');

        return $orderItem->quantity - $alreadyReturned;
    }

    /**
     * Get return summary for an order
     */
    public function getReturnSummary(Order $order): array
    {
        $order->load(['items', 'returns.items', 'returns.refunds']);

        $summary = [];

        foreach ($order->items as $item) {
            $alreadyReturned = $order->returns->flatMap->items
                ->where('order_item_id', $item->id)
                ->sum('quantity');

            $summary[] = [
                'order_item_id' => $item->id,
                'product_name' => $item->product->name,
                'original_quantity' => $item->quantity,
                'returned_quantity' => $alreadyReturned,
                'available_for_return' => $item->quantity - $alreadyReturned,
            ];
        }

        return $summary;
    }
}
