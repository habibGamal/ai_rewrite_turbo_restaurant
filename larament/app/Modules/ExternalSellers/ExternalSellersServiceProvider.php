<?php

namespace App\Modules\ExternalSellers;

use App\Core\Events\OrderCancelled;
use App\Core\OrderExtensions;
use App\Modules\ExternalSellers\Listeners\ReverseSellerSettlements;
use App\Modules\Module;
use App\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;

/**
 * External sellers module: partner sellers whose items ride on a restaurant
 * order, the guest-bill split and seller settlements.
 */
class ExternalSellersServiceProvider extends ModuleServiceProvider
{
    public function module(): Module
    {
        return Module::EXTERNAL_SELLERS;
    }

    /**
     * Money owed to a seller follows the rows, not the switch: an order that
     * already holds external orders must still split, guard and reverse them
     * after the vendor turns the module off. Each piece is a no-op for orders
     * without external orders.
     */
    protected function bootAlways(): void
    {
        $extensions = $this->app->make(OrderExtensions::class);
        $extensions->addTenderSplitter(GuestBillTenderSplitter::class);
        $extensions->addTypeChangeGuard(CreditTypeChangeGuard::class);

        Event::listen(OrderCancelled::class, ReverseSellerSettlements::class);
    }

    protected function bootModule(): void
    {
        // Screens and routes move here in Phase 4/5.
    }
}
