<?php

namespace Database\Factories;

use App\Models\Stocktaking;
use Illuminate\Database\Eloquent\Factories\Factory;

class StocktakingFactory extends Factory
{
    protected $model = Stocktaking::class;

    public function definition(): array
    {
        return [
            'user_id' => 1,
            'notes' => $this->faker->optional()->sentence(),
            'total' => 0,
        ];
    }
}
