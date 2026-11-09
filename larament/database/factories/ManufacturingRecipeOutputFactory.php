<?php

namespace Database\Factories;

use App\Enums\ProductType;
use App\Models\ManufacturingRecipe;
use App\Models\ManufacturingRecipeOutput;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ManufacturingRecipeOutput>
 */
class ManufacturingRecipeOutputFactory extends Factory
{
    protected $model = ManufacturingRecipeOutput::class;

    public function definition(): array
    {
        return [
            'recipe_id' => ManufacturingRecipe::factory(),
            'product_id' => Product::factory()->state(['type' => ProductType::RawMaterial]),
            'is_main' => false,
            'expected_quantity' => $this->faker->randomFloat(3, 0.5, 20),
            'cost_method' => 'percent',
            'cost_value' => 10,
        ];
    }

    public function main(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_main' => true,
            'cost_method' => 'percent',
            'cost_value' => 0,
        ]);
    }
}
