<?php

namespace App\Orders;

final class OrderOpening
{
    public function __construct(
        public readonly ?string $tableNumber = null,
        public readonly ?int $guestsCount = null,
    ) {}
}
