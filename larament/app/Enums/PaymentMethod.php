<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case CASH = 'cash';
    case CARD = 'card';
    case TALABAT_CARD = 'talabat_card';

    public function label(): string
    {
        return match ($this) {
            self::CASH => 'نقدي',
            self::CARD => 'بطاقة',
            self::TALABAT_CARD => 'بطاقة طلبات',
        };
    }

    public function affectsCashBalance(): bool
    {
        return $this === self::CASH;
    }
}
