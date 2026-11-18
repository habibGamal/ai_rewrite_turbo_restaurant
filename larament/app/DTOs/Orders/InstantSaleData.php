<?php

namespace App\DTOs\Orders;

final readonly class InstantSaleData
{
    /**
     * @param  list<array<string, mixed>>  $items  same line shape as SaveOrderRequest items
     * @param  array<string, float>  $tenders  payment method => amount
     * @param  array{value: float, type: string}|null  $discount
     * @param  string|null  $orderNotes  order-level note, printed on the receipt like other order types
     * @param  float  $credit  money already owed to the customer (an exchange refund) that pays the sale first
     */
    public function __construct(
        public string $clientUuid,
        public string $typeCode,
        public int $shiftId,
        public int $userId,
        public array $items,
        public array $tenders,
        public ?array $discount = null,
        public ?int $customerId = null,
        public ?int $heldSaleId = null,
        public bool $print = false,
        public float $credit = 0.0,
        public string $creditMethod = 'cash',
        public ?string $orderNotes = null,
    ) {}
}
