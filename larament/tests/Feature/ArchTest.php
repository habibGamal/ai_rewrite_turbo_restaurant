<?php

arch('All files in the casts directory extend `CastsAttributes`')
    ->expect('App\Casts')
    ->toExtend('Illuminate\Contracts\Database\Eloquent\CastsAttributes');

arch('All files in the casts directory have suffix `Cast`')
    ->expect('App\Casts')
    ->toHaveSuffix('Cast');

arch('All files in the observers directory have suffix `Observer`')
    ->expect('App\Observers')
    ->toHaveSuffix('Observer');

arch('All files in the policies directory have suffix `Policy`')
    ->expect('App\Policies')
    ->toHaveSuffix('Policy');

arch('All files in the services directory have suffix `Service`')
    ->expect('App\Services')
    ->toHaveSuffix('Service')
    ->ignoring('App\Services\PrintStrategies');

arch('ensures `env()` is only used in config files')
    ->expect('env')
    ->not->toBeUsed()
    ->ignoring('config');

arch('No file in the app directory uses `die`, `dd`, or `dump`.')
    ->expect('App')
    ->not->toUse(['die', 'dd', 'dump']);

arch('module internals are only used inside their module')
    ->expect('App\Modules\Restaurant')
    ->toOnlyBeUsedIn('App\Modules\Restaurant');

arch('delivery module internals are only used inside their module')
    ->expect('App\Modules\Delivery')
    ->toOnlyBeUsedIn('App\Modules\Delivery');

arch('web orders module internals are only used inside their module')
    ->expect('App\Modules\WebOrders')
    ->toOnlyBeUsedIn('App\Modules\WebOrders');

arch('manufacturing module internals are only used inside their module')
    ->expect('App\Modules\Manufacturing')
    ->toOnlyBeUsedIn('App\Modules\Manufacturing');

arch('external sellers module internals are only used inside their module')
    ->expect('App\Modules\ExternalSellers')
    ->toOnlyBeUsedIn('App\Modules\ExternalSellers');
