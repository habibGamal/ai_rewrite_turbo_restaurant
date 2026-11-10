<?php

namespace App\Policies;

use App\Models\User;

class ManufacturingOrderPolicy extends BasePolicy
{
    protected string $resource = 'manufacturing_orders';

    public function update(User $user, mixed $model): bool
    {
        return $model->closed_at === null && parent::update($user, $model);
    }

    public function delete(User $user, mixed $model): bool
    {
        return $model->closed_at === null && parent::delete($user, $model);
    }
}
