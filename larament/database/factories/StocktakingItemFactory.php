<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\StocktakingItem;

class StocktakingItemFactory extends Factory
{
    protected $model = StocktakingItem::class;

    public function definition(): array
    {
        return [
            'stocktaking_id' => 1,
            'product_id' => 1,
            'stock_quantity' => $this->faker->numberBetween(10, 100),
            'real_quantity' => $this->faker->numberBetween(10, 100),
            'price' => $this->faker->randomFloat(2, 10, 500),
            'total' => 0,
        ];
    }
}
