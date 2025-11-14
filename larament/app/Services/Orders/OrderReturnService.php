<?php

namespace App\Services\Orders;

use App\Enums\MovementReason;
use App\Enums\ReturnStatus;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\OrderReturnItem;
use App\Models\Refund;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderReturnService
{
    public function __construct(
        private readonly OrderStockConversionService $stockConversionService
    ) {
    }

    /**
     * Process an order return
     *
     * @param Order $order
     * @param array $returnItems [['order_item_id' => int, 'quantity' => float, 'refund_amount' => float], ...]
     * @param string $reason
     * @param array $refundDistribution [['method' => string, 'amount' => float], ...]
     * @param int $shiftId
     * @param bool $reverseStock
     * @return OrderReturn
     * @throws \Exception
     */
    public function processReturn(
        Order $order,
        array $returnItems,
        string $reason,
        array $refundDistribution,
        int $shiftId,
        bool $reverseStock = true
    ): OrderReturn {
        try {
            DB::beginTransaction();

            // Load order with items and previous returns
            $order->load(['items', 'returns.items']);

            // Validate return items
            $this->validateReturnItems($order, $returnItems);

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
                'reverse_stock' => $reverseStock,
            ]);

            // Create return items
            foreach ($returnItems as $item) {
                OrderReturnItem::create([
                    'order_return_id' => $orderReturn->id,
                    'order_item_id' => $item['order_item_id'],
                    'quantity' => $item['quantity'],
                    'refund_amount' => $item['refund_amount'],
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

            // Reverse stock if requested
            if ($reverseStock) {
                $this->reverseStockForReturnedItems($order, $returnItems);
            }

            // Update order return status
            $this->updateOrderReturnStatus($order);

            DB::commit();

            Log::info("Order return processed successfully", [
                'order_id' => $order->id,
                'return_id' => $orderReturn->id,
                'total_refund' => $totalRefund,
                'reverse_stock' => $reverseStock,
            ]);

            return $orderReturn;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to process order return", [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Validate return items against available quantities
     */
    private function validateReturnItems(Order $order, array $returnItems): void
    {
        foreach ($returnItems as $item) {
            $orderItem = $order->items->firstWhere('id', $item['order_item_id']);

            if (!$orderItem) {
                throw new \Exception("صنف الطلب غير موجود");
            }

            // Calculate already returned quantity
            $alreadyReturned = $order->returns->flatMap->items
                ->where('order_item_id', $orderItem->id)
                ->sum('quantity');

            $availableForReturn = $orderItem->quantity - $alreadyReturned;

            if ($item['quantity'] > $availableForReturn) {
                throw new \Exception(
                    "الكمية المطلوب إرجاعها للصنف {$orderItem->product->name} أكبر من المتاح. " .
                    "المتاح: {$availableForReturn}, المطلوب: {$item['quantity']}"
                );
            }

            if ($item['quantity'] <= 0) {
                throw new \Exception("الكمية يجب أن تكون أكبر من صفر");
            }

            if ($item['refund_amount'] < 0) {
                throw new \Exception("مبلغ الاسترجاع يجب أن يكون موجباً");
            }
        }
    }

    /**
     * Validate that refund distribution sum equals total refund
     */
    private function validateRefundDistribution(array $refundDistribution, float $totalRefund): void
    {
        $distributionSum = collect($refundDistribution)->sum('amount');

        if (abs($distributionSum - $totalRefund) > 0.01) {
            throw new \Exception(
                "مجموع توزيع الاسترجاع ({$distributionSum}) لا يساوي إجمالي الاسترجاع ({$totalRefund})"
            );
        }

        foreach ($refundDistribution as $refund) {
            if ($refund['amount'] <= 0) {
                throw new \Exception("مبلغ الاسترجاع يجب أن يكون أكبر من صفر");
            }
        }
    }

    /**
     * Reverse stock for returned items
     */
    private function reverseStockForReturnedItems(Order $order, array $returnItems): void
    {
        // Create a temporary order with only returned items for stock calculation
        $tempOrder = new Order();
        $tempOrder->id = $order->id;
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

        if (!empty($stockItems)) {
            $this->stockConversionService->addStockForCancelledOrder($tempOrder);
        }
    }

    /**
     * Update order return status based on returned quantities
     */
    private function updateOrderReturnStatus(Order $order): void
    {
        $order->load(['items', 'returns.items']);

        $totalOriginalQuantity = $order->items->sum('quantity');
        $totalReturnedQuantity = $order->returns->flatMap->items->sum('quantity');

        if ($totalReturnedQuantity <= 0) {
            $returnStatus = ReturnStatus::NONE;
        } elseif ($totalReturnedQuantity >= $totalOriginalQuantity) {
            $returnStatus = ReturnStatus::FULL_RETURN;
        } else {
            $returnStatus = ReturnStatus::PARTIAL_RETURN;
        }

        $order->update(['return_status' => $returnStatus]);
    }

    /**
     * Get available quantity for return for a specific order item
     */
    public function getAvailableQuantityForReturn(Order $order, int $orderItemId): float
    {
        $order->load(['items', 'returns.items']);

        $orderItem = $order->items->firstWhere('id', $orderItemId);

        if (!$orderItem) {
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
