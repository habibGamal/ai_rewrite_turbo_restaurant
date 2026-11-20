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
     * The built-in order types a new install of this type starts with
     * active; the other built-in types start inactive. Web order types are
     * left to the Web orders module, and custom types are never touched.
     *
     * @return list<string>
     */
    public function defaultOrderTypes(): array
    {
        return match ($this) {
            self::RESTAURANT => ['dine_in', 'takeaway', 'delivery', 'companies', 'talabat'],
            self::CAFE => ['dine_in', 'takeaway', 'companies', 'instant_sale'],
            self::BAKERY => ['takeaway', 'companies', 'instant_sale'],
            self::FASHION, self::GENERAL_RETAIL => ['companies', 'instant_sale'],
            self::GROCERY => ['delivery', 'companies', 'instant_sale'],
        };
    }

    /**
     * Short description shown when choosing the type in the setup wizard.
     */
    public function description(): string
    {
        return match ($this) {
            self::RESTAURANT => 'صالة وطاولات، شاشة مطبخ، توصيل وتصنيع.',
            self::CAFE => 'صالة وطاولات وبيع سريع على الكاونتر.',
            self::BAKERY => 'بيع سريع على الكاونتر، تصنيع، ومقاسات وأوزان.',
            self::FASHION => 'بيع سريع بالباركود، مقاسات وألوان، ومرتجعات واستبدال.',
            self::GROCERY => 'بيع سريع بالباركود وأصناف بالوزن وتوصيل للمنازل.',
            self::GENERAL_RETAIL => 'بيع سريع بالباركود لأي متجر تجزئة.',
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
