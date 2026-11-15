<?php

namespace App\Modules;

use App\Services\FeatureService;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Base provider for a business-type module. A module's listeners, routes,
 * behaviours and Filament screens are wired in bootModule(), which only runs
 * when the vendor has the module switched on for this install.
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    abstract public function module(): Module;

    abstract protected function bootModule(): void;

    public function boot(): void
    {
        $this->bootAlways();

        if (! $this->moduleIsEnabled()) {
            return;
        }

        $this->bootModule();
    }

    /**
     * Wiring that follows the data rather than the switch: it must keep
     * working for rows the module wrote before it was switched off (money
     * owed to an external seller, for example). Keep it to checks that are
     * no-ops when the module has no rows.
     */
    protected function bootAlways(): void
    {
        //
    }

    /**
     * Whether the module is on right now. Listeners call this at handle time
     * so a vendor switch takes effect without a restart (queue workers, tests).
     */
    public static function enabledNow(Module $module): bool
    {
        try {
            return app(FeatureService::class)->moduleEnabled($module);
        } catch (Throwable) {
            return in_array($module, BusinessType::RESTAURANT->defaultModules(), true);
        }
    }

    protected function moduleIsEnabled(): bool
    {
        try {
            return $this->app->make(FeatureService::class)->moduleEnabled($this->module());
        } catch (Throwable) {
            // No database yet (fresh install, `migrate`, package discovery):
            // fall back to the default module list.
            return in_array($this->module(), BusinessType::RESTAURANT->defaultModules(), true);
        }
    }
}
