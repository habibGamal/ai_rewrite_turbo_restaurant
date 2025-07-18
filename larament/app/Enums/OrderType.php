<?php

namespace App\Enums;

enum OrderType: string
{
    case DINE_IN = 'dine_in';
    case TAKEAWAY = 'takeaway';
    case DELIVERY = 'delivery';
    case COMPANIES = 'companies';
    case TALABAT = 'talabat';

    public function label(): string
    {
        return match ($this) {
            self::DINE_IN => 'صالة',
            self::TAKEAWAY => 'تيك أواي',
            self::DELIVERY => 'دليفري',
            self::COMPANIES => 'شركات',
            self::TALABAT => 'طلبات',
        };
    }

    public function requiresTable(): bool
    {
        return $this === self::DINE_IN;
    }

    public function hasDeliveryFee(): bool
    {
        return $this === self::DELIVERY;
    }
}
