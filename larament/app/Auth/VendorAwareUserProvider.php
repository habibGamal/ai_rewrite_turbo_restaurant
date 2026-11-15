<?php

namespace App\Auth;

use App\Models\Scopes\HidesVendorScope;
use Illuminate\Auth\EloquentUserProvider;

/**
 * The Eloquent user provider without the HidesVendor scope, so the vendor
 * account can log in, keep its session and reset its password while
 * staying hidden from every user list.
 */
class VendorAwareUserProvider extends EloquentUserProvider
{
    protected function newModelQuery($model = null)
    {
        return parent::newModelQuery($model)->withoutGlobalScope(HidesVendorScope::class);
    }
}
