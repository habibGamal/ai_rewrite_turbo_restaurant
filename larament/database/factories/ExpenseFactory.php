<?php

namespace Database\Factories;

use App\Models\Expense;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Factories\Factory;

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
