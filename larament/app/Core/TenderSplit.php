<?php

namespace App\Core;

use App\Models\Order;
use App\Orders\Tenders;
use Closure;

final class TenderSplit
{
    /**
     * @param  (Closure(Order): void)|null  $afterPayment  runs after core recorded the order's payments
     */
    public function __construct(
        public readonly Tenders $remaining,
        public readonly ?Closure $afterPayment = null,
    ) {}

    public static function untouched(Tenders $tenders): self
    {
        return new self($tenders);
    }
}
