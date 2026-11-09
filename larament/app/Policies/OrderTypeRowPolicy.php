<?php

namespace App\Policies;

use App\Models\OrderTypeRow;
use App\Models\User;

class OrderTypeRowPolicy extends BasePolicy
{
    protected string $resource = 'order_types';

    public function delete(User $user, mixed $model): bool
    {
        if ($model instanceof OrderTypeRow && $model->is_system) {
            return false;
        }

        return parent::delete($user, $model);
    }
}
