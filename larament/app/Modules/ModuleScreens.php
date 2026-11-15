<?php

namespace App\Modules;

use App\Enums\OptionalFeature;
use App\Filament\Pages\ExternalSellerAccount;
use App\Filament\Pages\ExternalSellerAccounts;
use App\Filament\Pages\Reports\ManufacturingYieldReport;
use App\Filament\Pages\Reports\WebOrdersReport;
use App\Filament\Resources\ExternalSellers\ExternalSellerResource;
use App\Filament\Resources\ManufacturedProducts\ManufacturedProductResource;
use App\Filament\Resources\ManufacturingOrders\ManufacturingOrderResource;
use App\Filament\Resources\ManufacturingRecipes\ManufacturingRecipeResource;
use App\Filament\Resources\ProductGroups\ProductGroupResource;
use App\Filament\Resources\RawMaterialProducts\RawMaterialProductResource;
use App\Services\FeatureService;
use Filament\Resources\Pages\Page as ResourcePage;

/**
 * Which module owns each admin screen. A screen whose module is off is left
 * out of the navigation and answers 404 on a direct URL (see
 * EnsureModuleScreenEnabled), never a "feature disabled" message.
 *
 * A screen under app/Modules/<Module>/ belongs to that module by its
 * namespace. map() lists the screens still outside the modules; Phase 4
 * empties it as each module's screens move in.
 */
final class ModuleScreens
{
    /**
     * @return array<class-string, Module>
     */
    public static function map(): array
    {
        return [
            WebOrdersReport::class => Module::WEB_ORDERS,

            ManufacturedProductResource::class => Module::MANUFACTURING,
            RawMaterialProductResource::class => Module::MANUFACTURING,
            ManufacturingRecipeResource::class => Module::MANUFACTURING,
            ManufacturingOrderResource::class => Module::MANUFACTURING,
            ManufacturingYieldReport::class => Module::MANUFACTURING,
        ];
    }

    /**
     * Screens switched by a vendor-owned optional feature rather than (or
     * on top of) a module; they also answer 404 while the feature is off.
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
            ProductGroupResource::class => OptionalFeature::VARIANTS,
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
            $class = $class::getResource();

            if (isset($map[$class])) {
                return $map[$class];
            }
        }

        return self::moduleOfNamespace($class);
    }

    /**
     * Screens that live in a module (app/Modules/<Module>/...) belong to it
     * without being listed in map().
     */
    private static function moduleOfNamespace(string $class): ?Module
    {
        if (! str_starts_with($class, 'App\\Modules\\')) {
            return null;
        }

        $directory = explode('\\', $class)[2] ?? '';

        foreach (Module::cases() as $module) {
            if (ModuleFilament::directory($module) === $directory) {
                return $module;
            }
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
