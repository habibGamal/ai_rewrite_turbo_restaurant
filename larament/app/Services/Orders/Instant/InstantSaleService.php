<?php

namespace App\Services\Orders\Instant;

use App\DTOs\Orders\CreateOrderDTO;
use App\DTOs\Orders\InstantSaleData;
use App\Exceptions\OrderException;
use App\Models\HeldSale;
use App\Models\Order;
use App\Orders\Behaviors\InstantSaleBehavior;
use App\Orders\OrderTypeCatalog;
use App\Repositories\OrderItemRepository;
use App\Services\Orders\OrderCalculationService;
use App\Services\Orders\OrderCategoryBlockService;
use App\Services\Orders\OrderItemPricingService;
use App\Services\Orders\OrderOpeningService;
use App\Services\Orders\OrderService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * One request creates, prices, pays and completes a counter sale. The order
 * number is taken here, so an abandoned cart uses none. The lines are written
 * once and OrderItemsChanged is never dispatched, so no kitchen ticket is made.
 */
class InstantSaleService
{
    public function __construct(
        private readonly OrderTypeCatalog $catalog,
        private readonly OrderOpeningService $opening,
        private readonly OrderItemPricingService $pricer,
        private readonly OrderItemRepository $items,
        private readonly OrderCalculationService $calculator,
        private readonly OrderCategoryBlockService $categoryBlock,
        private readonly OrderService $orders,
    ) {}

    public function sell(InstantSaleData $data): InstantSaleResult
    {
        $existing = $this->existing($data->clientUuid);
        if ($existing !== null) {
            return $existing;
        }

        $this->assertInstantType($data->typeCode);

        if ($data->items === []) {
            throw new OrderException('السلة فارغة');
        }

        try {
            return DB::transaction(fn (): InstantSaleResult => $this->sellInTransaction($data));
        } catch (QueryException $e) {
            // A concurrent retry with the same key won the unique index.
            $existing = $this->existing($data->clientUuid);
            if ($existing !== null) {
                return $existing;
            }

            throw $e;
        }
    }

    /**
     * Prices, writes and completes the sale. Callers hold a transaction; the
     * return/exchange flow calls this inside its own.
     */
    public function sellInTransaction(InstantSaleData $data): InstantSaleResult
    {
        $this->assertInstantType($data->typeCode);

        $productIds = array_values(array_unique(array_map(
            fn (array $line): int => (int) $line['product_id'],
            $data->items,
        )));
        $this->categoryBlock->assertCanAdd($data->typeCode, $productIds);

        $order = $this->opening->open(new CreateOrderDTO(
            typeCode: $data->typeCode,
            shiftId: $data->shiftId,
            userId: $data->userId,
            customerId: $data->customerId,
        ));
        $order->forceFill(['client_uuid' => $data->clientUuid])->save();

        $this->items->updateOrderItems($order, $this->pricer->price($data->items));

        $hasItemDiscounts = collect($data->items)->some(fn (array $line): bool => (float) ($line['item_discount'] ?? 0) > 0
            || (float) ($line['item_discount_percent'] ?? 0) > 0);

        if ($data->discount !== null && (float) $data->discount['value'] > 0 && ! $hasItemDiscounts) {
            $order->forceFill($data->discount['type'] === 'percent'
                ? ['temp_discount_percent' => (float) $data->discount['value'], 'discount' => 0]
                : ['discount' => (float) $data->discount['value'], 'temp_discount_percent' => 0])->save();
        }

        $order = $this->calculator->calculateOrderTotals($order);

        $tenders = $data->tenders;
        $credit = min(max(0.0, $data->credit), (float) $order->total);
        if ($credit > 0) {
            $tenders[$data->creditMethod] = ($tenders[$data->creditMethod] ?? 0) + $credit;
        }

        $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
        $completed = $this->orders->finishCompletion($locked, $tenders, $data->print);

        if ($data->heldSaleId !== null) {
            HeldSale::query()
                ->whereKey($data->heldSaleId)
                ->where('shift_id', $data->shiftId)
                ->delete();
        }

        $tendered = round(array_sum($tenders), 2);
        $paid = round((float) $completed->payments()->sum('amount'), 2);

        return new InstantSaleResult(
            order: $completed,
            paid: $paid,
            change: max(0.0, round($tendered - (float) $completed->total, 2)),
            duplicate: false,
        );
    }

    private function existing(string $clientUuid): ?InstantSaleResult
    {
        $order = Order::query()->where('client_uuid', $clientUuid)->first();

        if (! $order instanceof Order) {
            return null;
        }

        $paid = round((float) $order->payments()->sum('amount'), 2);

        return new InstantSaleResult($order, $paid, 0.0, duplicate: true);
    }

    private function assertInstantType(string $code): void
    {
        try {
            $row = $this->catalog->find($code);
        } catch (\InvalidArgumentException) {
            throw new OrderException('نوع الطلب غير موجود');
        }

        if (! $row->is_active || ! $this->catalog->behaviorFor($code) instanceof InstantSaleBehavior) {
            throw new OrderException('هذا النوع لا يدعم البيع المباشر');
        }
    }
}
