<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name,
            'phone' => $this->faker->phoneNumber,
            'has_whatsapp' => $this->faker->boolean,
            'address' => $this->faker->address,
            'region' => $this->faker->city,
            'delivery_cost' => $this->faker->randomFloat(2, 0, 20),
        ];
    }
}
