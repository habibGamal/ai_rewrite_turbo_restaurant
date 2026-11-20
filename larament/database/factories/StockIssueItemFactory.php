<?php

namespace Database\Factories;

use App\Enums\ProductType;
use App\Models\Product;
use App\Models\StockIssue;
use App\Models\StockIssueItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockIssueItem>
 */
class StockIssueItemFactory extends Factory
{
    protected $model = StockIssueItem::class;

    public function definition(): array
    {
        return [
            'stock_issue_id' => StockIssue::factory(),
            'product_id' => Product::factory()->state(['type' => ProductType::RawMaterial]),
            'quantity' => 1,
            'unit_cost' => 0,
            'total_cost' => 0,
        ];
    }
}
