<?php

namespace App\Modules\Restaurant;

use App\Core\Events\OrderCancelled;
use App\Core\Events\OrderCompleted;
use App\Core\Events\OrderItemsChanged;
use App\Core\Events\OrderTypeChanged;
use App\Modules\Module;
use App\Modules\ModuleServiceProvider;
use App\Modules\Restaurant\Behaviors\DineInBehavior;
use App\Modules\Restaurant\Console\KitchenBackfillOpenOrdersCommand;
use App\Modules\Restaurant\Listeners\AutoFinishKitchenTicket;
use App\Modules\Restaurant\Listeners\CancelKitchenTicket;
use App\Modules\Restaurant\Listeners\RebroadcastKitchenTicket;
use App\Modules\Restaurant\Listeners\SyncKitchenTicket;
use App\Orders\OrderBehaviorRegistry;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;

/**
 * Restaurant module: tables and floor, kitchen and KDS, service charge.
 *
 * Its admin screens under Filament/ are registered by ModuleFilament and
 * hidden by ModuleScreens while the module is off. The Eloquent models,
 * enums and migrations of its tables stay in core, like every other
 * table: core screens (order details, categories) read their columns.
 */
class RestaurantServiceProvider extends ModuleServiceProvider
{
    public function module(): Module
    {
        return Module::RESTAURANT;
    }

    public function register(): void
    {
        // Registered whether the module is on or off: existing dine-in order
        // types must keep resolving. The registry hides the behaviour from
        // the order-type form while the module is off.
        $this->app->extend(OrderBehaviorRegistry::class, function (OrderBehaviorRegistry $registry): OrderBehaviorRegistry {
            $registry->register('dine_in', DineInBehavior::class, 'صالة', module: Module::RESTAURANT, sort: 10);

            return $registry;
        });
    }

    /**
     * Routes and the kitchen channel are always registered (route:cache
     * holds them whatever the switch says); the routes answer 404 and the
     * channel refuses while the module is off.
     */
    protected function bootAlways(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');

        Broadcast::channel('kitchen', function ($user) {
            return $user !== null && $user->can('kitchen.access');
        });

        if ($this->app->runningInConsole()) {
            $this->commands([KitchenBackfillOpenOrdersCommand::class]);
        }
    }

    protected function bootModule(): void
    {
        Event::listen(OrderItemsChanged::class, SyncKitchenTicket::class);
        Event::listen(OrderCompleted::class, AutoFinishKitchenTicket::class);
        Event::listen(OrderCancelled::class, CancelKitchenTicket::class);
        Event::listen(OrderTypeChanged::class, RebroadcastKitchenTicket::class);
    }
}
