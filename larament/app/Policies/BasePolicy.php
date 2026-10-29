<?php

namespace App\Policies;

use App\Models\User;

abstract class BasePolicy
{
    /**
     * Permission resource name (e.g. 'categories', 'products', 'orders').
     */
    protected string $resource = '';

    public function viewAny(User $user): bool
    {
        return $user->can("view_{$this->resource}") || $user->can("view_any_{$this->resource}");
    }

    public function view(User $user, mixed $model): bool
    {
        return $user->can("view_{$this->resource}");
    }

    public function create(User $user): bool
    {
        return $user->can("create_{$this->resource}");
    }

    public function update(User $user, mixed $model): bool
    {
        return $user->can("update_{$this->resource}");
    }

    public function delete(User $user, mixed $model): bool
    {
        return $user->can("delete_{$this->resource}");
    }

    public function deleteAny(User $user): bool
    {
        return $user->can("delete_{$this->resource}") || $user->can("delete_any_{$this->resource}");
    }

    public function restore(User $user, mixed $model): bool
    {
        return $user->can("update_{$this->resource}");
    }

    public function restoreAny(User $user): bool
    {
        return $user->can("update_{$this->resource}");
    }

    public function forceDelete(User $user, mixed $model): bool
    {
        return $user->can("delete_{$this->resource}");
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->can("delete_{$this->resource}");
    }
}
