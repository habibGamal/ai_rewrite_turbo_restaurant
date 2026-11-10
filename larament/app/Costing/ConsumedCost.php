<?php

namespace App\Costing;

final class ConsumedCost
{
    public function __construct(public float $quantity, public float $totalCost) {}

    public function unitCost(): float
    {
        return $this->quantity == 0 ? 0 : $this->totalCost / $this->quantity;
    }
}
