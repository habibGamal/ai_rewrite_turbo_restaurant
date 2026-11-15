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
     * Permissions owned by this module. While the module is off they are
     * denied by Gate::before (admin and vendor included), left out of the
     * Roles screen and kept on roles untouched so switching the module back
     * on restores them.
     *
     * @return array<int, string>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::RESTAURANT => [
                ...self::crud('table_types'),
                ...self::crud('kitchen_stations'),
                'kitchen.access',
                'view_kitchen_performance_report',
            ],
            self::DELIVERY => [
                ...self::crud('drivers'),
                ...self::crud('regions'),
                'driver.manage',
                'view_driver_performance_report',
            ],
            self::WEB_ORDERS => [
                'web_order.manage',
                'view_web_orders_report',
            ],
            self::MANUFACTURING => [
                ...self::crud('manufacturing_recipes'),
                ...self::crud('manufacturing_orders'),
                'view_manufacturing_yield_report',
            ],
            // Still gated by OptionalFeature::OUTSOURCED_ORDERS, the switch
            // installs already use; Phase 4 folds that flag into this module.
            self::EXTERNAL_SELLERS => [],
            self::RETAIL => [],
        };
    }

    /**
     * @return list<string>
     */
    private static function crud(string $resource): array
    {
        return ["view_{$resource}", "create_{$resource}", "update_{$resource}", "delete_{$resource}"];
    }

    /**
     * The module that owns a permission, if any.
     */
    public static function forPermission(string $permission): ?self
    {
        foreach (self::cases() as $module) {
            if (in_array($permission, $module->permissions(), true)) {
                return $module;
            }
        }

        return null;
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
