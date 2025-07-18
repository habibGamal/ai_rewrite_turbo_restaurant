<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Shift;

class ShiftFactory extends Factory
{
    protected $model = Shift::class;

    public function definition(): array
    {
        return [
            'user_id' => 1,
            'start_time' => $this->faker->dateTime,
            'end_time' => $this->faker->optional()->dateTime,
        ];
    }
}
