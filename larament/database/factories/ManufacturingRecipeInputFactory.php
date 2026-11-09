<?php

namespace Database\Factories;

use App\Enums\ProductType;
use App\Models\ManufacturingRecipe;
use App\Models\ManufacturingRecipeInput;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ManufacturingRecipeInput>
 */
class ManufacturingRecipeInputFactory extends Factory
{
    protected $model = ManufacturingRecipeInput::class;

    public function definition(): array
    {
        return [
            'recipe_id' => ManufacturingRecipe::factory(),
            'product_id' => Product::factory()->state(['type' => ProductType::RawMaterial]),
            'quantity' => $this->faker->randomFloat(3, 0.5, 20),
        ];
    }
}
