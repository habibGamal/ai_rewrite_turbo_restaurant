<?php

namespace App\Modules;

use App\Enums\OptionalFeature;
use App\Filament\Pages\Reports\CustomFeesReport;
use App\Filament\Pages\Reports\EquipmentReport;
use App\Filament\Resources\Equipment\EquipmentResource;
use App\Filament\Resources\LabelTemplates\LabelTemplateResource;
use App\Filament\Resources\ProductGroups\ProductGroupResource;
use App\Filament\Resources\RevenueTypes\RevenueTypeResource;
use App\Filament\Resources\Terminals\TerminalResource;
use App\Services\FeatureService;
use Filament\Resources\Pages\Page as ResourcePage;

/**
 * Which module owns each admin screen. A screen whose module is off is left
 * out of the navigation and answers 404 on a direct URL (see
 * EnsureModuleScreenEnabled), never a "feature disabled" message.
 *
 * A screen belongs to a module by its namespace: everything under
 * app/Modules/<Module>/ is that module's. Screens of core stay visible.
 */
final class ModuleScreens
{
    /**
     * Screens switched by a vendor-owned optional feature rather than (or
     * on top of) a module; they also answer 404 while the feature is off.
     *
     * @return array<class-string, OptionalFeature>
     */
    public static function featureMap(): array
    {
        return [
            ProductGroupResource::class => OptionalFeature::VARIANTS,
            RevenueTypeResource::class => OptionalFeature::REVENUES,
            EquipmentResource::class => OptionalFeature::EQUIPMENT,
            EquipmentReport::class => OptionalFeature::EQUIPMENT,
            CustomFeesReport::class => OptionalFeature::CUSTOM_FEES,
            TerminalResource::class => OptionalFeature::MULTI_TERMINAL,
            LabelTemplateResource::class => OptionalFeature::LABEL_PRINTING,
        ];
    }

    /**
     * The module owning a Filament page, resource page or resource class.
     */
    public static function moduleFor(string $class): ?Module
    {
        if (is_subclass_of($class, ResourcePage::class)) {
            $class = $class::getResource();
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
