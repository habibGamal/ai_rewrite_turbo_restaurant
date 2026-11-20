<?php

namespace Database\Factories;

use App\Models\StockIssue;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockIssue>
 */
class StockIssueFactory extends Factory
{
    protected $model = StockIssue::class;

    public function definition(): array
    {
        return [
            'warehouse_id' => fn () => Warehouse::default()->id,
            'destination' => 'وجبات الموظفين',
            'notes' => $this->faker->optional()->sentence(),
            'user_id' => null,
            'closed_at' => null,
            'closed_by' => null,
            'business_date' => null,
            'total_cost' => 0,
        ];
    }
}
