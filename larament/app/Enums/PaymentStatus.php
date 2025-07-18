<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case PENDING = 'pending';
    case PARTIAL_PAID = 'partial_paid';
    case FULL_PAID = 'full_paid';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'في الانتظار',
            self::PARTIAL_PAID => 'مدفوع جزئياً',
            self::FULL_PAID => 'مدفوع بالكامل',
        };
    }

    public function isFullyPaid(): bool
    {
        return $this === self::FULL_PAID;
    }

    public function requiresPayment(): bool
    {
        return $this !== self::FULL_PAID;
    }
}
