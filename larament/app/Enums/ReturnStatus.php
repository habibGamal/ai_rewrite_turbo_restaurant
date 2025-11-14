<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum ReturnStatus: string implements HasColor, HasIcon, HasLabel
{
    case NONE = 'none';
    case PARTIAL_RETURN = 'partial_return';
    case FULL_RETURN = 'full_return';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::NONE => 'لا يوجد إرجاع',
            self::PARTIAL_RETURN => 'إرجاع جزئي',
            self::FULL_RETURN => 'إرجاع كامل',
        };
    }

    public function getColor(): ?string
    {
        return match ($this) {
            self::NONE => 'gray',
            self::PARTIAL_RETURN => 'warning',
            self::FULL_RETURN => 'danger',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::NONE => 'heroicon-o-check-circle',
            self::PARTIAL_RETURN => 'heroicon-o-arrow-uturn-left',
            self::FULL_RETURN => 'heroicon-o-arrow-path',
        };
    }

    public function label(): string
    {
        return $this->getLabel();
    }
}
