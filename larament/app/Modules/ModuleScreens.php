<?php

namespace App\Modules;

use App\Enums\OptionalFeature;
use App\Filament\Pages\ExternalSellerAccount;
use App\Filament\Pages\ExternalSellerAccounts;
use App\Filament\Pages\Reports\DriverPerformanceReport;
use App\Filament\Pages\Reports\KitchenPerformanceReport;
use App\Filament\Pages\Reports\ManufacturingYieldReport;
use App\Filament\Pages\Reports\WebOrdersReport;
use App\Filament\Resources\Drivers\DriverResource;
use App\Filament\Resources\ExternalSellers\ExternalSellerResource;
use App\Filament\Resources\KitchenStations\KitchenStationResource;
use App\Filament\Resources\ManufacturedProducts\ManufacturedProductResource;
use App\Filament\Resources\ManufacturingOrders\ManufacturingOrderResource;
use App\Filament\Resources\ManufacturingRecipes\ManufacturingRecipeResource;
use App\Filament\Resources\RawMaterialProducts\RawMaterialProductResource;
use App\Filament\Resources\Regions\RegionResource;
use App\Filament\Resources\TableTypes\TableTypeResource;
use App\Services\FeatureService;
use Filament\Resources\Pages\Page as ResourcePage;

/**
 * Which module owns each admin screen. A screen whose module is off is left
 * out of the navigation and answers 404 on a direct URL (see
 * EnsureModuleScreenEnabled), never a "feature disabled" message.
 *
 * Phase 4 moves these screens into their module's Filament plugin; until
 * then this map is the single place that ties a screen to its module.
 */
final class ModuleScreens
{
    /**
     * @return array<class-string, Module>
     */
    public static function map(): array
    {
        return [
            KitchenStationResource::class => Module::RESTAURANT,
            TableTypeResource::class => Module::RESTAURANT,
            KitchenPerformanceReport::class => Module::RESTAURANT,

            DriverResource::class => Module::DELIVERY,
            RegionResource::class => Module::DELIVERY,
            DriverPerformanceReport::class => Module::DELIVERY,

            WebOrdersReport::class => Module::WEB_ORDERS,

            ManufacturedProductResource::class => Module::MANUFACTURING,
            RawMaterialProductResource::class => Module::MANUFACTURING,
            ManufacturingRecipeResource::class => Module::MANUFACTURING,
            ManufacturingOrderResource::class => Module::MANUFACTURING,
            ManufacturingYieldReport::class => Module::MANUFACTURING,
        ];
    }

    /**
     * Screens still switched by an optional feature rather than a module.
     * External sellers keep OUTSOURCED_ORDERS (vendor-owned) until Phase 4
     * folds that flag into Module::EXTERNAL_SELLERS.
     *
     * @return array<class-string, OptionalFeature>
     */
    public static function featureMap(): array
    {
        return [
            ExternalSellerResource::class => OptionalFeature::OUTSOURCED_ORDERS,
            ExternalSellerAccounts::class => OptionalFeature::OUTSOURCED_ORDERS,
            ExternalSellerAccount::class => OptionalFeature::OUTSOURCED_ORDERS,
        ];
    }

    /**
     * The module owning a Filament page, resource page or resource class.
     */
    public static function moduleFor(string $class): ?Module
    {
        $map = self::map();

        if (isset($map[$class])) {
            return $map[$class];
        }

        if (is_subclass_of($class, ResourcePage::class)) {
            return $map[$class::getResource()] ?? null;
        }

        return null;
    }

    public static function allows(string $class): bool
    {
        $features = app(FeatureService::class);

        $module = self::moduleFor($class);
        if ($module !== null && ! $features->moduleEnabled($module)) {
            return false;
        }

        $feature = self::featureFor($class);

        return $feature === null || $features->isEnabled($feature);
    }

    private static function featureFor(string $class): ?OptionalFeature
    {
        if (is_subclass_of($class, ResourcePage::class)) {
            $class = $class::getResource();
        }

        return self::featureMap()[$class] ?? null;
    }
}
