<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum KitchenTicketStatus: string implements HasColor, HasIcon, HasLabel
{
    case ACTIVE = 'active';
    case DONE = 'done';
    case CANCELLED = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::ACTIVE => 'نشط',
            self::DONE => 'منتهي',
            self::CANCELLED => 'ملغي',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::ACTIVE => 'warning',
            self::DONE => 'success',
            self::CANCELLED => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::ACTIVE => 'heroicon-o-fire',
            self::DONE => 'heroicon-o-check-circle',
            self::CANCELLED => 'heroicon-o-x-circle',
        };
    }

    public function label(): string
    {
        return $this->getLabel();
    }
}
