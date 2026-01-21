<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\PurchaseInvoiceItem;
use App\Models\PurchaseInvoice;
use App\Models\Product;

class PurchaseInvoiceItemFactory extends Factory
{
    protected $model = PurchaseInvoiceItem::class;

    public function definition(): array
    {
        $quantity = $this->faker->numberBetween(1, 100);
        $price = $this->faker->randomFloat(2, 1, 100);

        return [
            'purchase_invoice_id' => PurchaseInvoice::factory(),
            'product_id' => Product::factory(),
            'quantity' => $quantity,
            'price' => $price,
            'total' => $quantity * $price,
        ];
    }
}
