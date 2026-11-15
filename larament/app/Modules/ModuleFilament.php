<?php

namespace App\Modules;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Illuminate\Support\Str;

/**
 * Registers each module's admin screens (resources, pages, widgets found
 * under app/Modules/<Module>/Filament) with the admin panel.
 *
 * Every module's screens are registered whether the module is on or off:
 * the panel is built once per process, while the vendor can switch a module
 * at any time. Visibility is decided per request by ModuleScreens, so a
 * screen of a switched-off module is left out of the navigation and answers
 * 404 on a direct URL.
 */
final class ModuleFilament implements Plugin
{
    private function __construct(
        private readonly Module $module,
    ) {}

    /**
     * @return list<self>
     */
    public static function plugins(): array
    {
        return array_values(array_filter(
            array_map(fn (Module $module): self => new self($module), Module::cases()),
            fn (self $plugin): bool => is_dir($plugin->path()),
        ));
    }

    public static function directory(Module $module): string
    {
        return Str::studly($module->value);
    }

    public function getId(): string
    {
        return 'module-'.$this->module->value;
    }

    public function register(Panel $panel): void
    {
        $path = $this->path();
        $namespace = 'App\\Modules\\'.self::directory($this->module).'\\Filament';

        if (is_dir($path.'/Resources')) {
            $panel->discoverResources(in: $path.'/Resources', for: $namespace.'\\Resources');
        }

        if (is_dir($path.'/Pages')) {
            $panel->discoverPages(in: $path.'/Pages', for: $namespace.'\\Pages');
        }

        if (is_dir($path.'/Widgets')) {
            $panel->discoverWidgets(in: $path.'/Widgets', for: $namespace.'\\Widgets');
        }
    }

    public function boot(Panel $panel): void
    {
        //
    }

    private function path(): string
    {
        return app_path('Modules/'.self::directory($this->module).'/Filament');
    }
}
