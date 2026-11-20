<?php

namespace Database\Factories;

use App\Models\Stocktaking;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

class StocktakingFactory extends Factory
{
    protected $model = Stocktaking::class;

    public function definition(): array
    {
        return [
            'user_id' => 1,
            'warehouse_id' => fn () => Warehouse::default()->id,
            'notes' => $this->faker->optional()->sentence(),
            'total' => 0,
        ];
    }
}
