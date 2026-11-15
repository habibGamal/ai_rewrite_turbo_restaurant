<?php

namespace App\Modules;

/**
 * The business profile of an install. Set by the vendor before the first
 * order and fixed afterwards; it picks the default modules (and, from
 * Phase 5, the wording and seed data). Customers never see it.
 */
enum BusinessType: string
{
    case RESTAURANT = 'restaurant';
    case CAFE = 'cafe';
    case BAKERY = 'bakery';
    case FASHION = 'fashion';
    case GROCERY = 'grocery';
    case GENERAL_RETAIL = 'general_retail';

    public function label(): string
    {
        return match ($this) {
            self::RESTAURANT => 'مطعم',
            self::CAFE => 'كافيه',
            self::BAKERY => 'مخبز / حلواني',
            self::FASHION => 'ملابس وأحذية',
            self::GROCERY => 'سوبر ماركت / بقالة',
            self::GENERAL_RETAIL => 'تجزئة عامة',
        };
    }

    /**
     * Web orders and External sellers are off by default everywhere and
     * switched on by the vendor when needed.
     *
     * @return list<Module>
     */
    public function defaultModules(): array
    {
        return match ($this) {
            self::RESTAURANT => [Module::RESTAURANT, Module::DELIVERY, Module::MANUFACTURING],
            self::CAFE => [Module::RESTAURANT],
            self::BAKERY => [Module::RESTAURANT, Module::MANUFACTURING, Module::RETAIL],
            self::FASHION => [Module::RETAIL],
            self::GROCERY => [Module::RETAIL, Module::DELIVERY],
            self::GENERAL_RETAIL => [Module::RETAIL],
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $type) {
            $options[$type->value] = $type->label();
        }

        return $options;
    }
}
