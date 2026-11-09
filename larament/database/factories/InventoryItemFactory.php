<?php

namespace Database\Factories;

use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

class InventoryItemFactory extends Factory
{
    protected $model = InventoryItem::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'warehouse_id' => fn () => Warehouse::default()->id,
            'quantity' => $this->faker->numberBetween(0, 100),
        ];
    }
}
