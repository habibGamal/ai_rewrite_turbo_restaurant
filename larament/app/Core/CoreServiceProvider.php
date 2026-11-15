<?php

namespace App\Core;

use App\Core\Equipment\EquipmentDisableBlocker;
use App\Core\Equipment\EquipmentTypeChangeGuard;
use App\Core\Events\OrderCancelled;
use App\Core\Events\OrderChargesChanged;
use App\Core\Events\OrderCompleted;
use App\Core\Events\OrderOpened;
use App\Core\Events\OrderTypeChanged;
use App\Core\Events\PaymentRecorded;
use App\Core\Listeners\BroadcastOrderCancelled;
use App\Core\Listeners\BroadcastOrderChargesChanged;
use App\Core\Listeners\BroadcastOrderCompleted;
use App\Core\Listeners\BroadcastOrderCreated;
use App\Core\Listeners\BroadcastOrderTypeChanged;
use App\Core\Listeners\BroadcastPaymentProcessed;
use App\Core\Orders\CustomFeesDisableBlocker;
use App\Enums\OptionalFeature;
use App\Services\FeatureService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Core order flow wiring: the extension registry and core's own listeners on
 * the domain events. Listener timing is set on each listener class (see the
 * modular core design doc, "Listener timing").
 */
class CoreServiceProvider extends ServiceProvider
{
    /** @var array<class-string, list<class-string>> */
    private const LISTENERS = [
        OrderOpened::class => [BroadcastOrderCreated::class],
        OrderCompleted::class => [BroadcastOrderCompleted::class],
        OrderCancelled::class => [BroadcastOrderCancelled::class],
        PaymentRecorded::class => [BroadcastPaymentProcessed::class],
        OrderTypeChanged::class => [BroadcastOrderTypeChanged::class],
        OrderChargesChanged::class => [BroadcastOrderChargesChanged::class],
    ];

    public function register(): void
    {
        $this->app->singleton(OrderExtensions::class);

        // Equipment cannot be switched off while an open order carries it.
        $this->callAfterResolving(FeatureService::class, function (FeatureService $features): void {
            $features->registerDisableBlocker(
                OptionalFeature::EQUIPMENT,
                fn (): array => $this->app->make(EquipmentDisableBlocker::class)(),
            );
        });

        // Custom fees cannot be switched off while an open order carries fee lines.
        $this->callAfterResolving(FeatureService::class, function (FeatureService $features): void {
            $features->registerDisableBlocker(OptionalFeature::CUSTOM_FEES, new CustomFeesDisableBlocker);
        });
    }

    public function boot(): void
    {
        // Equipment lines follow the rows, not the switch: an order that
        // already holds equipment keeps the type guard after the flag is off.
        $this->app->make(OrderExtensions::class)->addTypeChangeGuard(EquipmentTypeChangeGuard::class);

        foreach (self::LISTENERS as $event => $listeners) {
            foreach ($listeners as $listener) {
                Event::listen($event, $listener);
            }
        }
    }
}
