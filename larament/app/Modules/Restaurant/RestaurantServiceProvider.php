<?php

namespace App\Modules\Restaurant;

use App\Modules\Module;
use App\Modules\ModuleServiceProvider;

/**
 * Restaurant module: tables and floor, kitchen and KDS, service charge.
 * Empty until Phase 4 moves the restaurant code here.
 */
class RestaurantServiceProvider extends ModuleServiceProvider
{
    public function module(): Module
    {
        return Module::RESTAURANT;
    }

    protected function bootModule(): void
    {
        //
    }
}
