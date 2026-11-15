<?php

namespace App\Modules\WebOrders;

use App\Modules\Module;
use App\Modules\ModuleServiceProvider;
use App\Modules\WebOrders\Behaviors\WebDeliveryBehavior;
use App\Modules\WebOrders\Behaviors\WebTakeawayBehavior;
use App\Orders\OrderBehaviorRegistry;
use Illuminate\Support\Facades\Broadcast;

/**
 * Web orders module: orders placed on the linked website, accepted and
 * handled from the cashier screen.
 *
 * Its admin screens under Filament/ are registered by ModuleFilament and
 * hidden by ModuleScreens while the module is off.
 */
class WebOrdersServiceProvider extends ModuleServiceProvider
{
    public function module(): Module
    {
        return Module::WEB_ORDERS;
    }

    public function register(): void
    {
        // Registered whether the module is on or off: existing web order
        // types must keep resolving. Neither behaviour is offered for a new
        // order type; the website creates its own rows.
        $this->app->extend(OrderBehaviorRegistry::class, function (OrderBehaviorRegistry $registry): OrderBehaviorRegistry {
            $registry->register('web_delivery', WebDeliveryBehavior::class, 'اونلاين دليفري', selectable: false, module: Module::WEB_ORDERS, sort: 50);
            $registry->register('web_takeaway', WebTakeawayBehavior::class, 'اونلاين تيك أواي', selectable: false, module: Module::WEB_ORDERS, sort: 60);

            return $registry;
        });
    }

    /**
     * Routes and the web-orders channel are always registered (route:cache
     * holds them whatever the switch says); the cashier routes answer 404
     * while the module is off.
     */
    protected function bootAlways(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');

        Broadcast::channel('web-orders', function ($user) {
            return $user !== null;
        });
    }

    protected function bootModule(): void
    {
        //
    }
}
