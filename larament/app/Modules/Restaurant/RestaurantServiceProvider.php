<?php

namespace App\Modules\Restaurant;

use App\Core\Events\OrderCancelled;
use App\Core\Events\OrderCompleted;
use App\Core\Events\OrderItemsChanged;
use App\Core\Events\OrderTypeChanged;
use App\Modules\Module;
use App\Modules\ModuleServiceProvider;
use App\Modules\Restaurant\Listeners\AutoFinishKitchenTicket;
use App\Modules\Restaurant\Listeners\CancelKitchenTicket;
use App\Modules\Restaurant\Listeners\RebroadcastKitchenTicket;
use App\Modules\Restaurant\Listeners\SyncKitchenTicket;
use Illuminate\Support\Facades\Event;

/**
 * Restaurant module: tables and floor, kitchen and KDS, service charge.
 * Phase 3 moved the kitchen ticket calls here as listeners; Phase 4 moves
 * the rest of the restaurant code.
 */
class RestaurantServiceProvider extends ModuleServiceProvider
{
    public function module(): Module
    {
        return Module::RESTAURANT;
    }

    protected function bootModule(): void
    {
        Event::listen(OrderItemsChanged::class, SyncKitchenTicket::class);
        Event::listen(OrderCompleted::class, AutoFinishKitchenTicket::class);
        Event::listen(OrderCancelled::class, CancelKitchenTicket::class);
        Event::listen(OrderTypeChanged::class, RebroadcastKitchenTicket::class);
    }
}
