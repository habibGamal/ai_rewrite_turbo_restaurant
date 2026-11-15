<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Keeps the vendor ("god mode") account out of every user list: the Users
 * screen, user pickers, reports, role member counts and the staff sync.
 *
 * Lookups by primary key are left alone, so a record the vendor created
 * still resolves its `user` relation (shown as "الدعم الفني") instead of
 * breaking with a null user. Lists filtered by an explicit `whereIn('id')`
 * still hide the vendor; only `find()`, `where('id', x)` and eager loads
 * pass through.
 */
class HidesVendorScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if ($this->isPrimaryKeyLookup($builder, $model)) {
            return;
        }

        $builder->where($model->qualifyColumn('is_vendor'), false);
    }

    public function extend(Builder $builder): void
    {
        $builder->macro('withVendor', fn (Builder $builder): Builder => $builder->withoutGlobalScope(self::class));
        $builder->macro('onlyVendor', fn (Builder $builder): Builder => $builder
            ->withoutGlobalScope(self::class)
            ->where($builder->getModel()->qualifyColumn('is_vendor'), true));
    }

    private function isPrimaryKeyLookup(Builder $builder, Model $model): bool
    {
        $keys = [$model->getKeyName(), $model->getQualifiedKeyName()];

        foreach ($builder->getQuery()->wheres as $where) {
            if (! in_array($where['column'] ?? null, $keys, true)) {
                continue;
            }

            // find(), where('id', x) and lazy belongsTo use a basic "=";
            // eager-loaded belongsTo uses whereIntegerInRaw ("InRaw").
            if (($where['type'] === 'Basic' && ($where['operator'] ?? null) === '=') || $where['type'] === 'InRaw') {
                return true;
            }
        }

        return false;
    }
}
