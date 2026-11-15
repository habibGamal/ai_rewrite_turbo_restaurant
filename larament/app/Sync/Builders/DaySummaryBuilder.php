<?php

namespace App\Sync\Builders;

use App\Enums\OrderStatus;
use App\Models\Expense;
use App\Models\InventoryItemMovementDaily;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Revenue;
use App\Models\Shift;
use App\Models\Waste;
use App\Services\Reports\ReturnsAggregates;
use App\Sync\DocumentType;
use App\Sync\Payload;
use Illuminate\Database\Eloquent\Collection;

class DaySummaryBuilder implements DocumentBuilder
{
    protected ?string $date = null;

    public function type(): DocumentType
    {
        return DocumentType::DaySummary;
    }

    /**
     * Set the business date key for this document.
     */
    public function withKey(?string $key): static
    {
        $this->date = $key;

        return $this;
    }

    /**
     * Build the day_summary payload snapshot for the business date stored via withKey().
     *
     * @return array<string, mixed>|null
     */
    public function build(string $uuid): ?array
    {
        if ($this->date === null) {
            return null;
        }

        $date = Payload::date($this->date);
        if ($date === null) {
            return null;
        }

        $hasShifts = Shift::query()->whereDate('business_date', $date)->exists();
        $hasWastes = Waste::query()->whereDate('business_date', $date)->exists();
        $hasInventory = InventoryItemMovementDaily::query()->whereDate('date', $date)->exists();

        if (! $hasShifts && ! $hasWastes && ! $hasInventory) {
            return null;
        }

        /** @var Collection<int, Shift> $shifts */
        $shifts = Shift::query()
            ->whereDate('business_date', $date)
            ->get();

        $shiftIds = $shifts->pluck('id');

        $ordersCount = Order::query()
            ->whereIn('shift_id', $shiftIds)
            ->count();

        /** @var Collection<int, Order> $completedOrders */
        $completedOrders = Order::query()
            ->with('items')
            ->whereIn('shift_id', $shiftIds)
            ->where('status', OrderStatus::COMPLETED)
            ->get();

        $completedCount = $completedOrders->count();
        $guestsTotal = (int) $completedOrders->sum(fn (Order $order): int => max(0, (int) $order->guests_count));

        $netTotal = 0.0;
        $discountTotal = 0.0;
        $cogsTotal = 0.0;

        foreach ($completedOrders as $order) {
            $netTotal += (float) $order->total - (float) ($order->tax ?? 0);
            $discountTotal += (float) ($order->discount ?? 0);

            /** @var OrderItem $item */
            foreach ($order->items as $item) {
                $discountTotal += (float) ($item->item_discount ?? 0);
                $cogsTotal += (float) $item->quantity * (float) $item->cost;
            }
        }

        $expensesTotal = (float) Expense::query()
            ->whereIn('shift_id', $shiftIds)
            ->sum('amount');

        // Refunds paid by the day's shifts, whatever day sold the order.
        $returns = app(ReturnsAggregates::class)->forShifts($shiftIds->all());

        $revenuesTotal = (float) Revenue::query()
            ->whereIn('shift_id', $shiftIds)
            ->sum('amount');

        $wasteTotal = (float) Waste::query()
            ->whereDate('business_date', $date)
            ->whereNotNull('closed_at')
            ->sum('total');

        return [
            'date' => $date,
            'orders_count' => $ordersCount,
            'completed_count' => $completedCount,
            'net_total' => Payload::money($netTotal),
            'discount_total' => Payload::money($discountTotal),
            'cogs_total' => Payload::money($cogsTotal),
            'expenses_total' => Payload::money($expensesTotal),
            'revenues_total' => Payload::money($revenuesTotal),
            'waste_total' => Payload::money($wasteTotal),
            'refunds_total' => Payload::money($returns['returnsAmount']),
            'refunds_cash' => Payload::money($returns['cashRefunds']),
            'guests_total' => $guestsTotal,
        ];
    }
}
