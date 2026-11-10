<?php

namespace Database\Factories;

use App\Models\StockTransfer;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockTransfer>
 */
class StockTransferFactory extends Factory
{
    protected $model = StockTransfer::class;

    public function definition(): array
    {
        return [
            'from_warehouse_id' => fn () => Warehouse::default()->id,
            'to_warehouse_id' => Warehouse::factory(),
            'notes' => $this->faker->optional()->sentence(),
            'user_id' => null,
            'closed_at' => null,
            'closed_by' => null,
            'business_date' => null,
            'total_cost' => 0,
        ];
    }
}
