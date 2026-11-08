<?php

namespace Database\Factories;

use App\Models\KitchenStation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KitchenStation>
 */
class KitchenStationFactory extends Factory
{
    protected $model = KitchenStation::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->words(2, true),
            'is_active' => true,
            'sort_order' => $this->faker->numberBetween(0, 20),
        ];
    }
}
