<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PROCESSING => 'تحت التشغيل',
            self::COMPLETED => 'مكتمل',
            self::CANCELLED => 'ملغي',
        };
    }

    public function canBeModified(): bool
    {
        return $this === self::PROCESSING;
    }

    public function canBeCancelled(): bool
    {
        return $this === self::PROCESSING;
    }
}
