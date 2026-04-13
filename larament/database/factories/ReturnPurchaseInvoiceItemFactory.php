<?php

namespace Database\Factories;

use App\Models\ReturnPurchaseInvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReturnPurchaseInvoiceItemFactory extends Factory
{
    protected $model = ReturnPurchaseInvoiceItem::class;

    public function definition(): array
    {
        return [
            'return_purchase_invoice_id' => 1,
            'product_id' => 1,
            'quantity' => $this->faker->numberBetween(1, 100),
            'price' => $this->faker->randomFloat(2, 1, 100),
            'total' => $this->faker->randomFloat(2, 1, 1000),
        ];
    }
}
