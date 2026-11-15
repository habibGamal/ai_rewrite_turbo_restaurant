<?php

namespace App\Policies;

use App\Models\User;

/**
 * Audit rows are read-only for everyone; the resource also refuses writes,
 * because admin passes every policy check through Gate::before.
 */
class AuditLogPolicy extends BasePolicy
{
    protected string $resource = 'audit_logs';

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, mixed $model): bool
    {
        return false;
    }

    public function delete(User $user, mixed $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
