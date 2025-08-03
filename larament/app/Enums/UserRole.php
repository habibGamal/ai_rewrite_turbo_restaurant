<?php

namespace App\Enums;

enum UserRole: string
{
    case ADMIN = 'admin';
    case VIEWER = 'viewer';
    case CASHIER = 'cashier';
    case WATCHER = 'watcher';

    public function label(): string
    {
        return match ($this) {
            self::ADMIN => 'مدير',
            self::VIEWER => 'مشاهد',
            self::CASHIER => 'كاشير',
            self::WATCHER => 'مراقب',
        };
    }

    public function canManageOrders(): bool
    {
        return in_array($this, [self::ADMIN, self::CASHIER]);
    }

    public function canCancelOrders(): bool
    {
        return $this === self::ADMIN;
    }

    public function canApplyDiscounts(): bool
    {
        return $this === self::ADMIN;
    }

    public function canAccessReports(): bool
    {
        return in_array($this, [self::ADMIN, self::VIEWER, self::WATCHER]);
    }
}
