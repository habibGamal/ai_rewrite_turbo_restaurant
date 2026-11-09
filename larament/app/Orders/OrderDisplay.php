<?php

namespace App\Orders;

use App\Models\OrderTypeRow;

final readonly class OrderDisplay
{
    public function __construct(
        public string $code,
        public string $label,
        public string $color,
        public string $icon,
        public bool $showTable,
        public bool $showCustomer,
        public bool $showAddress,
        public bool $showDriver,
        public bool $chargesService,
        public bool $chargesDeliveryFee,
    ) {}

    public static function forRow(
        OrderTypeRow $type,
        bool $showTable = false,
        bool $showCustomer = false,
        bool $showAddress = false,
        bool $showDriver = false,
        bool $chargesService = false,
        bool $chargesDeliveryFee = false,
    ): self {
        return new self(
            code: $type->code,
            label: $type->label,
            color: $type->color,
            icon: $type->icon,
            showTable: $showTable,
            showCustomer: $showCustomer,
            showAddress: $showAddress,
            showDriver: $showDriver,
            chargesService: $chargesService,
            chargesDeliveryFee: $chargesDeliveryFee,
        );
    }
}
