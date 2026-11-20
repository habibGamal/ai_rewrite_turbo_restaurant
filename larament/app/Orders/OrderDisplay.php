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

    /**
     * @return array{
     *     code: string,
     *     label: string,
     *     color: string,
     *     icon: string,
     *     showTable: bool,
     *     showCustomer: bool,
     *     showAddress: bool,
     *     showDriver: bool,
     *     chargesService: bool,
     *     chargesDeliveryFee: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'label' => $this->label,
            'color' => $this->color,
            'icon' => $this->icon,
            'showTable' => $this->showTable,
            'showCustomer' => $this->showCustomer,
            'showAddress' => $this->showAddress,
            'showDriver' => $this->showDriver,
            'chargesService' => $this->chargesService,
            'chargesDeliveryFee' => $this->chargesDeliveryFee,
        ];
    }
}
