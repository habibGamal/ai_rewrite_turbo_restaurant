<?php

namespace App\Modules\Restaurant\Listeners;

use App\Modules\Module;
use App\Modules\ModuleServiceProvider;

/**
 * Base for Restaurant listeners: they are registered when the module boots
 * and also check the switch at handle time, so turning Restaurant off stops
 * kitchen work at once (web orders then skip the kitchen).
 */
abstract class RestaurantListener
{
    protected function enabled(): bool
    {
        return ModuleServiceProvider::enabledNow(Module::RESTAURANT);
    }
}
