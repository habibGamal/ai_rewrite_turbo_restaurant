<?php

namespace Database\Factories;

use App\Models\ExpenceType;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExpenceTypeFactory extends Factory
{
    protected $model = ExpenceType::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->word,
        ];
    }
}
