<?php

namespace App\Models;

use App\Services\Manufacturing\ManufacturingRecipeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ManufacturingRecipe extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** @return HasMany<ManufacturingRecipeInput, $this> */
    public function inputs(): HasMany
    {
        return $this->hasMany(ManufacturingRecipeInput::class, 'recipe_id');
    }

    /** @return HasMany<ManufacturingRecipeOutput, $this> */
    public function outputs(): HasMany
    {
        return $this->hasMany(ManufacturingRecipeOutput::class, 'recipe_id');
    }

    /** @return HasOne<ManufacturingRecipeOutput, $this> */
    public function mainOutput(): HasOne
    {
        $relation = $this->hasOne(ManufacturingRecipeOutput::class, 'recipe_id');
        $relation->where('is_main', true);

        return $relation;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function validate(): void
    {
        app(ManufacturingRecipeService::class)->validate($this);
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $inputs
     * @param  array<int|string, array<string, mixed>>  $outputs
     */
    public static function validateData(array $inputs, array $outputs): void
    {
        app(ManufacturingRecipeService::class)->validateData($inputs, $outputs);
    }
}
