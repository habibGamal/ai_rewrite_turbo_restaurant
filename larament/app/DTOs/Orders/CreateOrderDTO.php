<?php

namespace App\DTOs\Orders;

use App\Orders\OrderOpening;

class CreateOrderDTO
{
    public function __construct(
        public readonly string $typeCode,
        public readonly int $shiftId,
        public readonly int $userId,
        public readonly OrderOpening $opening = new OrderOpening,
        public readonly ?int $customerId = null,
        public readonly ?int $driverId = null,
        public readonly ?string $kitchenNotes = null,
        public readonly ?string $orderNotes = null,
    ) {}
}
