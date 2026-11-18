<?php

namespace App\Models\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HasMany relation scoped by both the parent's local key (e.g. product_id)
 * and warehouse_id so multi-warehouse ledger rows stay isolated per warehouse.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends HasMany<TRelatedModel, TDeclaringModel>
 */
class WarehouseHasMany extends HasMany
{
    public function addConstraints(): void
    {
        parent::addConstraints();

        if (static::$constraints) {
            $this->getRelationQuery()->where(
                $this->related->qualifyColumn('warehouse_id'),
                '=',
                $this->parent->getAttribute('warehouse_id'),
            );
        }
    }

    /**
     * @param  array<int, TDeclaringModel>  $models
     */
    public function addEagerConstraints(array $models): void
    {
        parent::addEagerConstraints($models);

        $warehouseIds = collect($models)
            ->map(fn (Model $model) => $model->getAttribute('warehouse_id'))
            ->filter(fn ($id) => $id !== null)
            ->unique()
            ->values()
            ->all();

        if ($warehouseIds !== []) {
            $this->getRelationQuery()->whereIn(
                $this->related->qualifyColumn('warehouse_id'),
                $warehouseIds,
            );
        }
    }

    /**
     * @param  array<int, TDeclaringModel>  $models
     * @param  EloquentCollection<int, TRelatedModel>  $results
     * @param  string  $relation
     * @param  string  $type
     * @return array<int, TDeclaringModel>
     */
    protected function matchOneOrMany(array $models, EloquentCollection $results, $relation, $type): array
    {
        $dictionary = [];
        $foreign = $this->getForeignKeyName();

        foreach ($results as $result) {
            $key = $this->compositeKey($result->getAttribute($foreign), $result->getAttribute('warehouse_id'));
            $dictionary[$key][] = $result;
        }

        foreach ($models as $model) {
            $key = $this->compositeKey($model->getAttribute($this->localKey), $model->getAttribute('warehouse_id'));

            if (isset($dictionary[$key])) {
                $related = $this->getRelationValue($dictionary, $key, $type);
                $model->setRelation($relation, $related);

                $type === 'one'
                    ? $this->applyInverseRelationToModel($related, $model)
                    : $this->applyInverseRelationToCollection($related, $model);
            }
        }

        return $models;
    }

    /**
     * @param  Builder<TRelatedModel>  $query
     * @param  Builder<TDeclaringModel>  $parentQuery
     * @param  array<int, string>|mixed  $columns
     * @return Builder<TRelatedModel>
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*']): Builder
    {
        return parent::getRelationExistenceQuery($query, $parentQuery, $columns)
            ->whereColumn(
                $this->parent->qualifyColumn('warehouse_id'),
                '=',
                $query->qualifyColumn('warehouse_id'),
            );
    }

    /**
     * @param  TRelatedModel  $model
     */
    protected function setForeignAttributesForCreate(Model $model): void
    {
        if ($model->getAttribute('warehouse_id') === null && $this->parent->getAttribute('warehouse_id') !== null) {
            $model->setAttribute('warehouse_id', $this->parent->getAttribute('warehouse_id'));
        }

        parent::setForeignAttributesForCreate($model);
    }

    private function compositeKey(mixed $primaryKey, mixed $warehouseId): string
    {
        return $this->getDictionaryKey($primaryKey).':'.$this->getDictionaryKey($warehouseId);
    }
}
