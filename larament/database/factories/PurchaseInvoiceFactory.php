<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\PurchaseInvoice;
use App\Models\User;
use App\Models\Supplier;

class PurchaseInvoiceFactory extends Factory
{
    protected $model = PurchaseInvoice::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'supplier_id' => Supplier::factory(),
            'total' => $this->faker->randomFloat(2, 10, 500),
        ];
    }
}
