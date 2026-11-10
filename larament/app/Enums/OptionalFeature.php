<?php

namespace App\Enums;

enum OptionalFeature: string
{
    case REVENUES = 'feature_revenues_enabled';
    case CUSTOM_FEES = 'feature_custom_fees_enabled';
    case OUTSOURCED_ORDERS = 'feature_outsourced_orders_enabled';
    case EQUIPMENT = 'feature_equipment_enabled';

    public function settingKey(): SettingKey
    {
        return match ($this) {
            self::REVENUES => SettingKey::FEATURE_REVENUES_ENABLED,
            self::CUSTOM_FEES => SettingKey::FEATURE_CUSTOM_FEES_ENABLED,
            self::OUTSOURCED_ORDERS => SettingKey::FEATURE_OUTSOURCED_ORDERS_ENABLED,
            self::EQUIPMENT => SettingKey::FEATURE_EQUIPMENT_ENABLED,
        };
    }

    public function shortKey(): string
    {
        return match ($this) {
            self::REVENUES => 'revenues',
            self::CUSTOM_FEES => 'custom_fees',
            self::OUTSOURCED_ORDERS => 'outsourced_orders',
            self::EQUIPMENT => 'equipment',
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
            self::REVENUES,
            self::CUSTOM_FEES,
            self::EQUIPMENT => [],
        };
    }

    public function disabledErrorMessage(): string
    {
        return match ($this) {
            self::REVENUES => 'ميزة الإيرادات غير مفعلة حالياً.',
            self::CUSTOM_FEES => 'ميزة الرسوم الإضافية غير مفعلة حالياً.',
            self::OUTSOURCED_ORDERS => 'ميزة الطلبات الخارجية غير مفعلة حالياً.',
            self::EQUIPMENT => 'ميزة المعدات غير مفعلة حالياً.',
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
            default => null,
        };
    }
}
