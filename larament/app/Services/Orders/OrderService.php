<?php

namespace App\Services\Orders;

use App\Core\Events\OrderCancelled;
use App\Core\Events\OrderCompleted;
use App\Core\Events\OrderItemsChanged;
use App\Core\Events\OrderTypeChanged;
use App\Core\OrderExtensions;
use App\DTOs\Orders\CreateOrderDTO;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\OrderException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shift;
use App\Orders\OrderOpening;
use App\Orders\OrderTypeCatalog;
use App\Orders\Tenders;
use App\Repositories\OrderItemRepository;
use App\Repositories\OrderRepository;
use App\Services\OrderItemChangeService;
use App\Services\ShiftLoggingService;
use App\Services\ShiftService;
use App\Sync\DocumentType;
use App\Sync\Outbox;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly OrderOpeningService $orderOpeningService,
        private readonly OrderCalculationService $orderCalculationService,
        private readonly OrderCompletionService $orderCompletionService,
        private readonly OrderPaymentService $orderPaymentService,
        private readonly ShiftService $shiftService,
        private readonly OrderStockConversionService $orderStockConversionService,
        private readonly OrderItemRepository $orderItemRepository,
        private readonly OrderItemPricingService $itemPricer,
        private readonly ShiftLoggingService $shiftLoggingService,
        private readonly OrderItemChangeService $orderItemChangeService,
        private readonly OrderTypeCatalog $catalog,
        private readonly OrderExtensions $extensions,
        private readonly OrderCategoryBlockService $categoryBlock,
    ) {}

    public function createOrder(CreateOrderDTO $createOrderDTO): Order
    {
        return DB::transaction(function () use ($createOrderDTO) {
            return $this->orderOpeningService->open($createOrderDTO);
        });
    }

    public function getShiftOrders(int $shiftId): Collection
    {
        return $this->orderRepository->getShiftOrders($shiftId);
    }

    public function getOrderDetails(int $orderId): Order
    {
        $order = $this->orderRepository->findByIdOrFail($orderId);
        $order->load(['items.product', 'user', 'customer', 'driver', 'table', 'payments', 'kitchenTicket.items', 'fees']);

        return $order;
    }

    public function updateOrderItems(int $orderId, array $itemsData): Order
    {
        return $this->locked($orderId, 'لا يمكن تعديل الطلب في هذه المرحلة', function (Order $order) use ($itemsData) {
            $this->assertNewItemsAllowed($order, $itemsData);
            $oldItems = $this->itemLines($order);

            $this->orderCalculationService->clearOrderDiscountForItemDiscounts($order, $itemsData);

            $priced = $this->itemPricer->price($itemsData);

            $this->orderItemRepository->updateOrderItems($order, $priced);
            $order->refresh();
            $newItems = $this->itemLines($order);
            $order->load('items');
            $order = $this->orderCalculationService->calculateOrderTotals($order);

            $differences = $this->shiftLoggingService->calculateItemDifferences($oldItems, $newItems);
            $this->shiftLoggingService->logOrderSave($order->id, $differences);
            $this->orderItemChangeService->recordChanges($order->id, $differences);

            OrderItemsChanged::dispatch($order, $differences);

            return $order;
        });
    }

    public function linkCustomer(int $orderId, ?int $customerId): Order
    {
        return $this->locked($orderId, 'لا يمكن ربط العميل في هذه المرحلة', function (Order $order) use ($customerId) {
            $this->orderRepository->update($order, ['customer_id' => $customerId]);
            $order->refresh();

            // Recalculate totals as delivery cost might change
            return $this->orderCalculationService->calculateOrderTotals($order);
        });
    }

    public function linkDriver(int $orderId, int $driverId): Order
    {
        return $this->locked($orderId, 'لا يمكن ربط السائق في هذه المرحلة', function (Order $order) use ($driverId) {
            $this->orderRepository->update($order, ['driver_id' => $driverId]);
            $order->refresh();

            return $order;
        });
    }

    public function updateNotes(int $orderId, ?string $kitchenNotes = null, ?string $orderNotes = null): Order
    {
        return $this->locked($orderId, null, function (Order $order) use ($kitchenNotes, $orderNotes) {
            $updateData = [];
            if ($kitchenNotes !== null) {
                $updateData['kitchen_notes'] = $kitchenNotes;
            }
            if ($orderNotes !== null) {
                $updateData['order_notes'] = $orderNotes;
            }

            if (! empty($updateData)) {
                $this->orderRepository->update($order, $updateData);
                $order->refresh();
            }

            return $order;
        });
    }

    /**
     * Set (or clear, with null) the number of guests on an open order.
     */
    public function updateGuestsCount(int $orderId, ?int $guestsCount): Order
    {
        return $this->locked($orderId, 'لا يمكن تعديل الطلب في هذه المرحلة', function (Order $order) use ($guestsCount) {
            if (! $order->typeRow()->asksForGuests()) {
                throw new OrderException('هذا النوع من الطلبات لا يسجل عدد الأفراد');
            }

            if ($guestsCount === null && $order->typeRow()->requiresGuests()) {
                throw new OrderException('عدد الأفراد إجباري لهذا النوع من الطلبات');
            }

            $this->orderRepository->update($order, ['guests_count' => $guestsCount]);
            $order->refresh();

            return $order;
        });
    }

    public function applyDiscount(int $orderId, float $discount, string $discountType): Order
    {
        return $this->locked($orderId, 'لا يمكن تطبيق الخصم في هذه المرحلة', function (Order $order) use ($discount, $discountType) {
            return $this->orderCalculationService->applyDiscount($order, $discount, $discountType);
        });
    }

    public function completeOrder(int $orderId, array $paymentsData, bool $shouldPrint = false): Order
    {
        return DB::transaction(function () use ($orderId, $paymentsData, $shouldPrint) {
            $order = Order::where('id', $orderId)->lockForUpdate()->firstOrFail();

            if (! in_array($order->status, [OrderStatus::PROCESSING, OrderStatus::OUT_FOR_DELIVERY])) {
                throw new OrderException('الطلب غير متاح للإكمال');
            }

            return $this->finishCompletion($order, $paymentsData, $shouldPrint);
        });
    }

    /**
     * The completion path shared by the order screen and instant sale: payments,
     * OrderCompleting, status, stock removal with real costs, profit and
     * OrderCompleted. Callers hold a transaction and the order's row lock.
     *
     * @param  array<string, mixed>  $paymentsData
     */
    public function finishCompletion(Order $order, array $paymentsData, bool $shouldPrint = false): Order
    {
        $completedOrder = $this->orderCompletionService->complete($order, $paymentsData, $shouldPrint);

        // Remove stock items after successful completion
        $this->orderStockConversionService->removeStockWithRealCostsForCompletedOrder($completedOrder);
        $completedOrder->load('items');
        $completedOrder->update([
            'profit' => $this->orderCalculationService->calculateProfit($completedOrder, (float) $completedOrder->total),
        ]);
        OrderCompleted::dispatch($completedOrder, $shouldPrint);

        return $completedOrder;
    }

    public function collectRemaining(int $orderId, Tenders $tenders): Order
    {
        return DB::transaction(function () use ($orderId, $tenders) {
            $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();

            if ($order->status !== OrderStatus::COMPLETED || ! in_array($order->payment_status, [PaymentStatus::PARTIAL_PAID, PaymentStatus::PENDING], true)) {
                throw new OrderException('لا يمكن تحصيل متبقي إلا لطلب مكتمل وغير مدفوع بالكامل');
            }

            if (! $order->typeRow()->allowsCredit()) {
                throw new OrderException('هذا النوع من الطلبات لا يسمح بتحصيل المتبقي');
            }

            $shift = $this->shiftService->getCurrentShift();

            if ($shift === null) {
                throw new OrderException('لا يوجد شيفت مفتوح لتحصيل المتبقي');
            }

            return $this->orderPaymentService->pay($order, $tenders, $shift->id);
        });
    }

    public function cancelOrder(int $orderId, string $reason = ''): Order
    {
        return DB::transaction(function () use ($orderId, $reason) {
            $order = Order::where('id', $orderId)->lockForUpdate()->firstOrFail();

            if (! $order->status->canBeCancelled()) {
                throw new OrderException('لا يمكن إلغاء الطلب في هذه المرحلة');
            }

            // Cancelling deletes the payments but would keep the refunds, and it
            // can't tell damaged returned units from unreturned ones.
            if ($order->returns()->exists()) {
                throw new OrderException('لا يمكن إلغاء طلب عليه مرتجعات');
            }

            $order->load('payments.shift');

            $paidInClosedShift = $order->payments->contains(function (mixed $payment): bool {
                if (! $payment instanceof Payment) {
                    return false;
                }

                $shift = $payment->shift;

                return $shift instanceof Shift && $shift->closed;
            });

            // Cancelling deletes the order's payments; a payment taken in a closed shift would silently
            // change that shift's cash after it was counted and closed. Block it for every order type.
            if ($paidInClosedShift) {
                throw new OrderException('لا يمكن إلغاء الطلب لأن إحدى دفعاته تخص شيفتًا مغلقًا');
            }

            if ($order->status === OrderStatus::COMPLETED) {
                shouldDayBeOpen();
            }

            // Store the original status to check if we need to restore stock
            $wasCompleted = $order->status === OrderStatus::COMPLETED;

            $order->behavior()->onClosed($order);

            // Update order status
            $this->orderRepository->update($order, [
                'status' => OrderStatus::CANCELLED,
                'profit' => 0,
            ]);

            // Delete any payments
            $order->payments()->delete();
            Outbox::record(DocumentType::Order, (string) $order->getAttribute('uuid'));

            $order->refresh();

            // If order was completed, add stock back
            if ($wasCompleted) {
                $this->orderStockConversionService->addStockForCancelledOrder($order);
            }

            // Kitchen ticket cancel and external seller reversal run as
            // in-transaction listeners: a throw rolls back the whole cancel.
            OrderCancelled::dispatch($order, $wasCompleted, $reason);

            return $order;
        });
    }

    public function changeOrderType(int $orderId, string $newType, ?string $tableNumber = null): Order
    {
        return $this->locked($orderId, 'لا يمكن تغيير نوع الطلب في هذه المرحلة', function (Order $order) use ($newType, $tableNumber) {
            $newTypeRow = $this->catalog->find($newType);
            foreach ($this->extensions->typeChangeGuards() as $guard) {
                $guard->assertCanChange($order, $newTypeRow);
            }
            $oldType = (string) $order->type;
            $newBehavior = $this->catalog->behaviorFor($newType);
            $assignsTable = $newBehavior->display($newTypeRow)->showTable;

            if ($assignsTable && ! $tableNumber) {
                throw new OrderException('رقم الطاولة مطلوب للطلبات الداخلية');
            }

            $order->behavior()->onClosed($order);

            $this->orderRepository->update($order, [
                'type' => $newType,
                'dine_table_number' => $assignsTable ? $tableNumber : null,
                // A type that does not ask for guests keeps no count, so reports stay clean.
                'guests_count' => $newTypeRow->asksForGuests() ? $order->guests_count : null,
            ]);

            $order->refresh();

            $newBehavior->onOpened($order, new OrderOpening($tableNumber));

            // Recalculate totals as service charges might change
            $this->orderCalculationService->calculateOrderTotals($order);
            OrderTypeChanged::dispatch($order, $oldType, $newType);

            return $order;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $itemsData
     */
    private function assertNewItemsAllowed(Order $order, array $itemsData): void
    {
        $existingIds = $order->items()->pluck('product_id')->map(fn (mixed $id): int => (int) $id)->all();
        $newIds = collect($itemsData)
            ->pluck('product_id')
            ->filter(fn (mixed $id): bool => $id !== null && $id !== '')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->reject(fn (int $id): bool => in_array($id, $existingIds, true))
            ->values()
            ->all();

        $this->categoryBlock->assertCanAdd((string) $order->type, $newIds);
    }

    /**
     * @param  Closure(Order): Order  $fn
     */
    private function locked(int $orderId, ?string $notModifiableMessage, Closure $fn): Order
    {
        return DB::transaction(function () use ($orderId, $notModifiableMessage, $fn) {
            $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();

            if ($notModifiableMessage !== null && ! $order->status->canBeModified()) {
                throw new OrderException($notModifiableMessage);
            }

            return $fn($order);
        });
    }

    /**
     * @return list<array{product_id: mixed, product_name: string, quantity: mixed, notes: mixed}>
     */
    private function itemLines(Order $order): array
    {
        return $order->items()->with('product')->get()->map(function ($item): array {
            $product = $item->getRelation('product');

            return [
                'product_id' => $item->getAttribute('product_id'),
                'product_name' => $product instanceof Product ? $product->name : '',
                'quantity' => $item->getAttribute('quantity'),
                'notes' => $item->getAttribute('notes'),
                'price' => (float) $item->getAttribute('price'),
            ];
        })->all();
    }
}
