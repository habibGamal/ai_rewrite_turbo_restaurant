<?php

namespace Database\Factories;

use App\Models\TableType;
use Illuminate\Database\Eloquent\Factories\Factory;

class TableTypeFactory extends Factory
{
    protected $model = TableType::class;

    public function definition(): array
    {
        $arabicTypes = [
            'VIP',
            'كلاسيك',
            'بدوي',
            'حديقة',
            'شرفة',
            'عائلي',
            'فردي',
            'داخلي',
            'خارجي',
            'خاص',
            'عام',
            'مميز',
        ];

        return [
            'name' => $this->faker->unique()->randomElement($arabicTypes) . ' ' . $this->faker->unique()->numberBetween(1, 10000),
        ];
    }
}
