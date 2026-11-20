<?php

namespace App\Policies;

use App\Models\User;
use App\Services\FeatureService;

class ExternalSellerPolicy extends BasePolicy
{
    protected string $resource = 'external_sellers';

    public function viewAny(User $user): bool
    {
        return app(FeatureService::class)->outsourcedOrdersEnabled() && parent::viewAny($user);
    }

    public function view(User $user, mixed $model): bool
    {
        return app(FeatureService::class)->outsourcedOrdersEnabled() && parent::view($user, $model);
    }

    public function create(User $user): bool
    {
        return app(FeatureService::class)->outsourcedOrdersEnabled() && parent::create($user);
    }

    public function update(User $user, mixed $model): bool
    {
        return app(FeatureService::class)->outsourcedOrdersEnabled() && parent::update($user, $model);
    }

    public function delete(User $user, mixed $model): bool
    {
        return app(FeatureService::class)->outsourcedOrdersEnabled() && parent::delete($user, $model);
    }

    public function deleteAny(User $user): bool
    {
        return app(FeatureService::class)->outsourcedOrdersEnabled() && parent::deleteAny($user);
    }

    public function restore(User $user, mixed $model): bool
    {
        return app(FeatureService::class)->outsourcedOrdersEnabled() && parent::restore($user, $model);
    }

    public function forceDelete(User $user, mixed $model): bool
    {
        return app(FeatureService::class)->outsourcedOrdersEnabled() && parent::forceDelete($user, $model);
    }
}
