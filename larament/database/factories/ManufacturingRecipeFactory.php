<?php

namespace Database\Factories;

use App\Models\ManufacturingRecipe;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ManufacturingRecipe>
 */
class ManufacturingRecipeFactory extends Factory
{
    protected $model = ManufacturingRecipe::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->words(2, true),
            'notes' => $this->faker->optional()->sentence(),
            'is_active' => true,
        ];
    }
}
