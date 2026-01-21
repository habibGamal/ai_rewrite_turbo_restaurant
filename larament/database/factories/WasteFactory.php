<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Waste;

class WasteFactory extends Factory
{
    protected $model = Waste::class;

    public function definition(): array
    {
        return [
            'user_id' => 1,
            'total' => $this->faker->randomFloat(2, 0, 10000),
            'notes' => $this->faker->optional()->sentence(),
        ];
    }
}
