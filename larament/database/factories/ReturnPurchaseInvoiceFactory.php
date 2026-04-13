<?php

namespace Database\Factories;

use App\Models\ReturnPurchaseInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReturnPurchaseInvoiceFactory extends Factory
{
    protected $model = ReturnPurchaseInvoice::class;

    public function definition(): array
    {
        return [
            'user_id' => 1,
            'supplier_id' => 1,
            'total' => $this->faker->randomFloat(2, 10, 500),
        ];
    }
}
