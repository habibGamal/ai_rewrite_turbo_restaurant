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
        if (! $this->moduleIsEnabled()) {
            return;
        }

        $this->bootModule();
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
