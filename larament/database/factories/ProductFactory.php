<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Product;
use App\Models\Category;
use App\Models\Printer;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'name' => $this->faker->word,
            'price' => $this->faker->randomFloat(2, 1, 100),
            'cost' => $this->faker->randomFloat(2, 1, 50),
            'type' => 'manufactured',
            'unit' => 'piece',
            'printer_id' => Printer::factory(),
            'legacy' => $this->faker->boolean,
        ];
    }
}
