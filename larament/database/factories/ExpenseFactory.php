<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Expense;
use App\Models\Shift;

class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    public function definition(): array
    {
        return [
            'shift_id' => Shift::factory(),
            'expence_type_id' => 1,
            'amount' => $this->faker->randomFloat(2, 1, 1000),
            'notes' => $this->faker->optional()->sentence(),
        ];
    }
}
