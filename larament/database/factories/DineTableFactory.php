<?php

namespace Database\Factories;

use App\Models\DineTable;
use Illuminate\Database\Eloquent\Factories\Factory;

class DineTableFactory extends Factory
{
    protected $model = DineTable::class;

    public function definition(): array
    {
        return [
            'table_number' => $this->faker->unique()->numberBetween(1, 100),
            'order_id' => null,
        ];
    }
}
