<?php

namespace Database\Factories;

use App\Enums\RevenueMethod;
use App\Models\Revenue;
use App\Models\RevenueType;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Revenue>
 */
class RevenueFactory extends Factory
{
    protected $model = Revenue::class;

    public function definition(): array
    {
        return [
            'shift_id' => Shift::factory(),
            'revenue_type_id' => RevenueType::factory(),
            'amount' => $this->faker->randomFloat(2, 1, 1000),
            'method' => RevenueMethod::CASH,
            'notes' => null,
        ];
    }

    public function card(): static
    {
        return $this->state(['method' => RevenueMethod::CARD]);
    }
}
