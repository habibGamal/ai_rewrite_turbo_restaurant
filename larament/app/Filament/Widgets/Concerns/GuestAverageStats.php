<?php

namespace App\Filament\Widgets\Concerns;

use App\Core\Orders\GuestStats;
use App\Enums\OrderStatus;
use App\Models\Order;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * «متوسط الفرد» next to the average order value, shown once any completed
 * order in the shifts carries a guest count.
 */
trait GuestAverageStats
{
    /**
     * @param  QueryBuilder|list<int>  $shiftIds
     * @return list<Stat>
     */
    protected function guestAverageStats(QueryBuilder|array $shiftIds): array
    {
        $guests = GuestStats::fromQuery(
            Order::query()
                ->where('orders.status', OrderStatus::COMPLETED)
                ->whereIn('orders.shift_id', $shiftIds)
        );

        if (! $guests->hasGuests()) {
            return [];
        }

        return [
            Stat::make('متوسط الفرد', number_format($guests->averagePerGuest(), 2).' جنيه')
                ->description(number_format($guests->guests).' فرد في '.number_format($guests->orders).' طلب')
                ->descriptionIcon('heroicon-m-user-group')
                ->color('primary'),
        ];
    }
}
