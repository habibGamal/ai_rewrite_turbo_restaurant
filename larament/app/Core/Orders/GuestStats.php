<?php

namespace App\Core\Orders;

use Illuminate\Contracts\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Guests and average spend per guest over a set of orders.
 *
 * Only orders that carry a guest count take part, on both sides of the
 * average, so an order type where the count is optional does not drag the
 * average down with orders nobody counted.
 */
final class GuestStats
{
    public function __construct(
        public readonly int $guests = 0,
        public readonly float $sales = 0.0,
        public readonly int $orders = 0,
    ) {}

    /**
     * @param  EloquentBuilder|QueryBuilder  $orders  already filtered (status, shift, dates)
     */
    public static function fromQuery($orders): self
    {
        $base = (clone $orders)->toBase();
        // Drop any select/order the caller left on the query; we aggregate.
        $base->columns = null;
        $base->orders = null;

        $row = $base
            ->whereNotNull('orders.guests_count')
            ->where('orders.guests_count', '>', 0)
            ->selectRaw(
                'COALESCE(SUM(orders.guests_count), 0) AS guests,'
                .' COALESCE(SUM(orders.total), 0) AS sales,'
                .' COUNT(*) AS orders_count'
            )->first();

        return new self(
            guests: (int) ($row->guests ?? 0),
            sales: (float) ($row->sales ?? 0),
            orders: (int) ($row->orders_count ?? 0),
        );
    }

    public function hasGuests(): bool
    {
        return $this->guests > 0;
    }

    public function averagePerGuest(): float
    {
        return $this->guests > 0 ? round($this->sales / $this->guests, 2) : 0.0;
    }

    public function plus(self $other): self
    {
        return new self($this->guests + $other->guests, $this->sales + $other->sales, $this->orders + $other->orders);
    }

    /**
     * «N فرد - متوسط الفرد X جنيه», or null when no order carried a count.
     */
    public function summary(): ?string
    {
        if (! $this->hasGuests()) {
            return null;
        }

        return number_format($this->guests).' فرد - متوسط الفرد '.number_format($this->averagePerGuest(), 2).' جنيه';
    }

    /**
     * @return array{guests: int, guest_sales: float, guest_orders: int, avg_per_guest: float}
     */
    public function toArray(): array
    {
        return [
            'guests' => $this->guests,
            'guest_sales' => $this->sales,
            'guest_orders' => $this->orders,
            'avg_per_guest' => $this->averagePerGuest(),
        ];
    }
}
