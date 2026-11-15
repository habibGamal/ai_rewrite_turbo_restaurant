<?php

namespace App\Enums;

use App\Modules\Module;

enum OptionalFeature: string
{
    case REVENUES = 'feature_revenues_enabled';
    case CUSTOM_FEES = 'feature_custom_fees_enabled';
    case OUTSOURCED_ORDERS = 'feature_outsourced_orders_enabled';
    case EQUIPMENT = 'feature_equipment_enabled';
    case VARIANTS = 'feature_variants_enabled';

    public function settingKey(): SettingKey
    {
        return match ($this) {
            self::REVENUES => SettingKey::FEATURE_REVENUES_ENABLED,
            self::CUSTOM_FEES => SettingKey::FEATURE_CUSTOM_FEES_ENABLED,
            self::OUTSOURCED_ORDERS => SettingKey::FEATURE_OUTSOURCED_ORDERS_ENABLED,
            self::EQUIPMENT => SettingKey::FEATURE_EQUIPMENT_ENABLED,
            self::VARIANTS => SettingKey::FEATURE_VARIANTS_ENABLED,
        };
    }

    /**
     * The module this flag switches, when it is a module's on/off switch
     * rather than a feature of its own. Outsourced orders is how the
     * External sellers module is stored; the vendor console shows it as a
     * module, not as a feature toggle.
     */
    public function module(): ?Module
    {
        return match ($this) {
            self::OUTSOURCED_ORDERS => Module::EXTERNAL_SELLERS,
            default => null,
        };
    }

    /**
     * Features switched on their own, not through a module.
     *
     * @return list<self>
     */
    public static function standalone(): array
    {
        return array_values(array_filter(self::cases(), fn (self $feature): bool => $feature->module() === null));
    }

    public function shortKey(): string
    {
        return match ($this) {
            self::REVENUES => 'revenues',
            self::CUSTOM_FEES => 'custom_fees',
            self::OUTSOURCED_ORDERS => 'outsourced_orders',
            self::EQUIPMENT => 'equipment',
            self::VARIANTS => 'variants',
        };
    }

    public function label(): string
    {
        return $this->settingKey()->label();
    }

    public function helperText(): string
    {
        return $this->settingKey()->helperText();
    }

    /**
     * Default permissions gated by this feature.
     *
     * @return array<int, string>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::OUTSOURCED_ORDERS => [
                'view_external_sellers',
                'create_external_sellers',
                'update_external_sellers',
                'delete_external_sellers',
            ],
            self::REVENUES => [
                'view_revenue_types',
                'create_revenue_types',
                'update_revenue_types',
                'delete_revenue_types',
            ],
            self::CUSTOM_FEES,
            self::EQUIPMENT,
            self::VARIANTS => [],
        };
    }

    public function disabledErrorMessage(): string
    {
        return match ($this) {
            self::REVENUES => 'ميزة الإيرادات غير مفعلة حالياً.',
            self::CUSTOM_FEES => 'ميزة الرسوم الإضافية غير مفعلة حالياً.',
            self::OUTSOURCED_ORDERS => 'ميزة الطلبات الخارجية غير مفعلة حالياً.',
            self::EQUIPMENT => 'ميزة المعدات غير مفعلة حالياً.',
            self::VARIANTS => 'ميزة المقاسات والألوان (متغيرات المنتجات) غير مفعلة حالياً.',
        };
    }

    public static function fromKey(self|SettingKey|string $key): ?self
    {
        if ($key instanceof self) {
            return $key;
        }

        if ($key instanceof SettingKey) {
            return self::tryFrom($key->value);
        }

        return self::tryFrom($key) ?? match ($key) {
            'revenues' => self::REVENUES,
            'custom_fees' => self::CUSTOM_FEES,
            'outsourced_orders' => self::OUTSOURCED_ORDERS,
            'equipment' => self::EQUIPMENT,
            'variants' => self::VARIANTS,
            default => null,
        };
    }
}
