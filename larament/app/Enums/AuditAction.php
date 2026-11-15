<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AuditAction: string implements HasColor, HasLabel
{
    case CREATED = 'created';
    case UPDATED = 'updated';
    case DELETED = 'deleted';
    case RESTORED = 'restored';

    public function getLabel(): string
    {
        return match ($this) {
            self::CREATED => 'إضافة',
            self::UPDATED => 'تعديل',
            self::DELETED => 'حذف',
            self::RESTORED => 'استرجاع',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::CREATED => 'success',
            self::UPDATED => 'warning',
            self::DELETED => 'danger',
            self::RESTORED => 'info',
        };
    }
}
