<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum KitchenItemStatus: string implements HasColor, HasIcon, HasLabel
{
    case PENDING = 'pending';
    case DONE = 'done';
    case VOIDED = 'voided';

    public function getLabel(): string
    {
        return match ($this) {
            self::PENDING => 'في الانتظار',
            self::DONE => 'تم',
            self::VOIDED => 'ملغي',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::PENDING => 'gray',
            self::DONE => 'success',
            self::VOIDED => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::PENDING => 'heroicon-o-clock',
            self::DONE => 'heroicon-o-check-circle',
            self::VOIDED => 'heroicon-o-x-circle',
        };
    }

    public function label(): string
    {
        return $this->getLabel();
    }

    public function isOpen(): bool
    {
        return $this === self::PENDING;
    }
}
