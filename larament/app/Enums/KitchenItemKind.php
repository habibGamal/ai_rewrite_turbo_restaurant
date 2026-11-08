<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum KitchenItemKind: string implements HasColor, HasIcon, HasLabel
{
    case ORIGINAL = 'original';
    case ADDED = 'added';
    case INCREASED = 'increased';

    public function getLabel(): string
    {
        return match ($this) {
            self::ORIGINAL => 'أصلي',
            self::ADDED => 'إضافة',
            self::INCREASED => 'زيادة',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::ORIGINAL => 'gray',
            self::ADDED => 'info',
            self::INCREASED => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::ORIGINAL => 'heroicon-o-document-text',
            self::ADDED => 'heroicon-o-plus',
            self::INCREASED => 'heroicon-o-arrow-trending-up',
        };
    }

    public function label(): string
    {
        return $this->getLabel();
    }
}
