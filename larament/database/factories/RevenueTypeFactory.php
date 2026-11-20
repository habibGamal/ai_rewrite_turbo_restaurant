<?php

namespace Database\Factories;

use App\Models\RevenueType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RevenueType>
 */
class RevenueTypeFactory extends Factory
{
    protected $model = RevenueType::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->words(2, true),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
