<?php

namespace App\Modules\ExternalSellers;

use App\Core\Events\OrderCancelled;
use App\Core\OrderExtensions;
use App\Enums\OptionalFeature;
use App\Modules\ExternalSellers\Listeners\ReverseSellerSettlements;
use App\Modules\Module;
use App\Modules\ModuleServiceProvider;
use App\Services\FeatureService;
use Illuminate\Support\Facades\Event;

/**
 * External sellers module: partner sellers whose items ride on a restaurant
 * order, the guest-bill split and seller settlements.
 *
 * The module's switch is the outsourced-orders flag installs already had
 * (see FeatureService::enabledModules()). Its admin screens under Filament/
 * are registered by ModuleFilament and hidden by ModuleScreens while it is
 * off.
 */
class ExternalSellersServiceProvider extends ModuleServiceProvider
{
    public function module(): Module
    {
        return Module::EXTERNAL_SELLERS;
    }

    public function register(): void
    {
        // Switching the module off is refused while sellers are still owed
        // money or an open order still carries an external order.
        $this->callAfterResolving(FeatureService::class, function (FeatureService $features): void {
            $features->registerDisableBlocker(
                OptionalFeature::OUTSOURCED_ORDERS,
                fn (): array => $this->app->make(SellersDisableBlocker::class)(),
            );
        });
    }

    /**
     * Money owed to a seller follows the rows, not the switch: an order that
     * already holds external orders must still split, guard and reverse them
     * after the vendor turns the module off. Each piece is a no-op for orders
     * without external orders. Routes are always registered (route:cache
     * holds them); the controller refuses while the module is off.
     */
    protected function bootAlways(): void
    {
        $extensions = $this->app->make(OrderExtensions::class);
        $extensions->addTenderSplitter(GuestBillTenderSplitter::class);
        $extensions->addTypeChangeGuard(CreditTypeChangeGuard::class);

        Event::listen(OrderCancelled::class, ReverseSellerSettlements::class);

        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }

    protected function bootModule(): void
    {
        //
    }
}
