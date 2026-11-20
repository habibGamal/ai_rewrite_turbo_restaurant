<?php

namespace Database\Factories;

use App\Enums\InventoryMovementOperation;
use App\Enums\MovementReason;
use App\Models\InventoryItemMovement;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InventoryItemMovement> */
class InventoryItemMovementFactory extends Factory
{
    protected $model = InventoryItemMovement::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'warehouse_id' => fn () => Warehouse::default()->id,
            'operation' => InventoryMovementOperation::IN,
            'reason' => MovementReason::PURCHASE,
            'quantity' => fake()->randomFloat(3, 1, 100),
            'unit_cost' => fn (array $attributes) => Product::findOrFail($attributes['product_id'])->cost,
            'total_cost' => fn (array $attributes) => round($attributes['quantity'] * $attributes['unit_cost'], 2),
            'user_id' => null,
        ];
    }
}
