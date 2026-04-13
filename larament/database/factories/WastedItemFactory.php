<?php

namespace Database\Factories;

use App\Models\WastedItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class WastedItemFactory extends Factory
{
    protected $model = WastedItem::class;

    public function definition(): array
    {
        return [
            'waste_id' => 1,
            'product_id' => 1,
            'quantity' => $this->faker->numberBetween(1, 10),
        ];
    }
}
