<?php

use App\Core\CoreServiceProvider;
use App\Modules\Delivery\DeliveryServiceProvider;
use App\Modules\ExternalSellers\ExternalSellersServiceProvider;
use App\Modules\Restaurant\RestaurantServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use Illuminate\Broadcasting\BroadcastServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    CoreServiceProvider::class,
    RestaurantServiceProvider::class,
    DeliveryServiceProvider::class,
    ExternalSellersServiceProvider::class,
    BroadcastServiceProvider::class,
];
