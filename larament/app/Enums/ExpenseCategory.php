<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ExpenseCategory: string implements HasLabel
{
    case Labor = 'labor';
    case Rent = 'rent';
    case Utilities = 'utilities';
    case Marketing = 'marketing';
    case Maintenance = 'maintenance';
    case RawMaterials = 'raw_materials';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Labor => 'عمالة ورواتب',
            self::Rent => 'إيجار',
            self::Utilities => 'مرافق',
            self::Marketing => 'تسويق',
            self::Maintenance => 'صيانة',
            self::RawMaterials => 'خامات',
            self::Other => 'أخرى',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
