<?php

namespace App\Filament\Traits;

use App\Modules\ModuleScreens;

/**
 * For resources whose policy permissions are shared with core screens (for
 * example products): hides the resource while its module (from
 * ModuleScreens) is off. Direct URLs answer 404 through
 * EnsureModuleScreenEnabled.
 */
trait BelongsToModule
{
    public static function canAccess(): bool
    {
        return ModuleScreens::allows(static::class) && parent::canAccess();
    }
}
