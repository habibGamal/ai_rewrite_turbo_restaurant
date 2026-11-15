<?php

namespace App\Modules;

/**
 * A business-type module that can be switched on or off per install.
 *
 * Only the vendor account switches modules (vendor console or `pos:profile`).
 * Phase 2 adds the switches with every module of the install's profile on, so
 * nothing visible changes yet; later phases gate screens, routes and
 * permissions on these values.
 */
enum Module: string
{
    case RESTAURANT = 'restaurant';
    case DELIVERY = 'delivery';
    case WEB_ORDERS = 'web_orders';
    case MANUFACTURING = 'manufacturing';
    case EXTERNAL_SELLERS = 'external_sellers';
    case RETAIL = 'retail';

    public function label(): string
    {
        return match ($this) {
            self::RESTAURANT => 'المطعم (الصالة والمطبخ)',
            self::DELIVERY => 'التوصيل',
            self::WEB_ORDERS => 'طلبات الموقع',
            self::MANUFACTURING => 'التصنيع',
            self::EXTERNAL_SELLERS => 'المتاجر المتعاونة',
            self::RETAIL => 'التجزئة',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::RESTAURANT => 'الطاولات وتصميم الصالة، شاشة المطبخ وتذاكر المطبخ، ورسوم الخدمة.',
            self::DELIVERY => 'طلبات التوصيل، السائقين، والمناطق ورسوم التوصيل.',
            self::WEB_ORDERS => 'استقبال الطلبات من الموقع الإلكتروني المرتبط.',
            self::MANUFACTURING => 'وصفات التصنيع وأوامر التصنيع والمنتجات المصنعة.',
            self::EXTERNAL_SELLERS => 'المتاجر المتعاونة وطلباتها وتسوية حساباتها.',
            self::RETAIL => 'البيع السريع بالباركود ومتغيرات المنتجات.',
        };
    }

    /**
     * Permissions owned by this module. Filled in Phase 5 when module
     * screens move behind the module switch; empty until then so that
     * no permission changes meaning in this phase.
     *
     * @return array<int, string>
     */
    public function permissions(): array
    {
        return [];
    }

    public function auditKey(): string
    {
        return 'module.'.$this->value;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $module) {
            $options[$module->value] = $module->label();
        }

        return $options;
    }
}
