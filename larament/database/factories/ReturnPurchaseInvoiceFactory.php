<?php

namespace Database\Factories;

use App\Models\ReturnPurchaseInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReturnPurchaseInvoiceFactory extends Factory
{
    protected $model = ReturnPurchaseInvoice::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'supplier_id' => Supplier::factory(),
            'warehouse_id' => fn () => Warehouse::default()->id,
            'total' => $this->faker->randomFloat(2, 10, 500),
        ];
    }
}
