<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderHistory;
use App\Models\OrderItemChange;
use App\Models\OrderReturn;
use App\Models\Payment;
use Carbon\Carbon;

class OrderHistoryService
{
    /**
     * Record an action in the order history.
     */
    public function record(
        int $orderId,
        string $action,
        string $actionName,
        ?string $description = null,
        ?array $details = null,
        ?int $userId = null,
        ?Carbon $createdAt = null
    ): OrderHistory {
        if ($action === 'created') {
            $existing = OrderHistory::where('order_id', $orderId)->where('action', 'created')->first();
            if ($existing) {
                return $existing;
            }
        }

        $userId = $userId ?? auth()->id() ?? Order::where('id', $orderId)->value('user_id');

        return OrderHistory::create([
            'order_id' => $orderId,
            'user_id' => $userId,
            'action' => $action,
            'action_name' => $actionName,
            'description' => $description,
            'details' => $details,
            'created_at' => $createdAt ?? now(),
            'updated_at' => $createdAt ?? now(),
        ]);
    }

    /**
     * Ensure historical records exist for an order from its relations.
     */
    public function syncOrderHistory(Order $order): void
    {
        $hasHistory = OrderHistory::where('order_id', $order->id)->exists();
        if ($hasHistory) {
            return;
        }

        // 1. Creation
        $this->record(
            orderId: $order->id,
            action: 'created',
            actionName: 'إنشاء الطلب',
            description: 'تم إنشاء الطلب برقم #'.($order->order_number ?? $order->id),
            userId: $order->user_id,
            createdAt: $order->created_at
        );

        // 2. Item changes
        /** @var OrderItemChange $change */
        foreach (OrderItemChange::where('order_id', $order->id)->oldest()->get() as $change) {
            $actionType = match ($change->change_type) {
                'added' => 'items_added',
                'removed' => 'items_removed',
                default => 'items_modified',
            };

            $actionName = match ($change->change_type) {
                'added' => 'إضافة صنف',
                'removed' => 'حذف صنف',
                default => 'تعديل كمية صنف',
            };

            $desc = match ($change->change_type) {
                'added' => "تم إضافة {$change->new_quantity} من {$change->product_name}",
                'removed' => "تم حذف {$change->old_quantity} من {$change->product_name}",
                default => "تم تغيير كمية {$change->product_name} من {$change->old_quantity} إلى {$change->new_quantity}",
            };

            $this->record(
                orderId: $order->id,
                action: $actionType,
                actionName: $actionName,
                description: $desc,
                details: [
                    'product_id' => $change->product_id,
                    'product_name' => $change->product_name,
                    'delta' => $change->delta,
                    'unit_price' => $change->unit_price,
                ],
                userId: $change->user_id ?? $order->user_id,
                createdAt: $change->created_at
            );
        }

        // 3. Payments
        /** @var Payment $payment */
        foreach (Payment::where('order_id', $order->id)->oldest()->get() as $payment) {
            $methodVal = $payment->method->value;
            $this->record(
                orderId: $order->id,
                action: 'payment_added',
                actionName: 'سداد دفعة',
                description: "تم سداد مبلغ {$payment->amount} ج.م ({$methodVal})",
                details: ['amount' => $payment->amount, 'method' => $methodVal],
                userId: $order->user_id,
                createdAt: $payment->created_at
            );
        }

        // 4. Returns
        /** @var OrderReturn $return */
        foreach (OrderReturn::where('order_id', $order->id)->oldest()->get() as $return) {
            $this->record(
                orderId: $order->id,
                action: 'return_created',
                actionName: 'إرجاع طلب',
                description: "تم إنشاء مرتجع للطلب بقيمة {$return->total_refund} ج.م",
                details: ['return_id' => $return->id, 'total' => $return->total_refund],
                userId: $return->user_id ?? $order->user_id,
                createdAt: $return->created_at
            );
        }

        // 5. Completion or Cancellation
        $statusStr = $order->status->value;
        if ($statusStr === 'completed') {
            $this->record(
                orderId: $order->id,
                action: 'completed',
                actionName: 'إتمام الطلب',
                description: 'تم إتمام الطلب بنجاح',
                userId: $order->user_id,
                createdAt: $order->updated_at
            );
        } elseif ($statusStr === 'cancelled') {
            $this->record(
                orderId: $order->id,
                action: 'cancelled',
                actionName: 'إلغاء الطلب',
                description: 'تم إلغاء الطلب',
                userId: $order->user_id,
                createdAt: $order->updated_at
            );
        }
    }
}
