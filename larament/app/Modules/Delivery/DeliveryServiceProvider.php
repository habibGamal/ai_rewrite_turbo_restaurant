<?php

namespace App\Modules\Delivery;

use App\Modules\Delivery\Behaviors\DeliveryBehavior;
use App\Modules\Module;
use App\Modules\ModuleServiceProvider;
use App\Orders\OrderBehaviorRegistry;

/**
 * Delivery module: delivery orders, drivers, regions and delivery fees.
 *
 * Its admin screens under Filament/ are registered by ModuleFilament and
 * hidden by ModuleScreens while the module is off. The Driver and Region
 * models stay in core: the order, customer and shift screens read them.
 */
class DeliveryServiceProvider extends ModuleServiceProvider
{
    public function module(): Module
    {
        return Module::DELIVERY;
    }

    public function register(): void
    {
        // Registered whether the module is on or off: existing delivery order
        // types must keep resolving. The registry hides the behaviour from
        // the order-type form while the module is off.
        $this->app->extend(OrderBehaviorRegistry::class, function (OrderBehaviorRegistry $registry): OrderBehaviorRegistry {
            $registry->register('delivery', DeliveryBehavior::class, 'دليفري', module: Module::DELIVERY, sort: 20);

            return $registry;
        });
    }

    /**
     * Routes are always registered (route:cache holds them whatever the
     * switch says) and answer 404 while the module is off.
     */
    protected function bootAlways(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }

    protected function bootModule(): void
    {
        //
    }
}
