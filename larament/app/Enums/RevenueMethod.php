<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * How a misc shift revenue was paid. Cash goes into the drawer (expected
 * cash); card is reported next to card payments.
 */
enum RevenueMethod: string implements HasColor, HasLabel
{
    case CASH = 'cash';
    case CARD = 'card';

    public function getLabel(): string
    {
        return match ($this) {
            self::CASH => 'نقدي',
            self::CARD => 'فيزا',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::CASH => 'success',
            self::CARD => 'info',
        };
    }
}
