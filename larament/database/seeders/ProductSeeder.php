<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Product;
use App\Models\Category;
use App\Models\Printer;
use App\Enums\ProductType;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $categoryIds = Category::pluck('id')->toArray();
        $printerIds = Printer::pluck('id')->toArray();

        $products = [
            // المشروبات
            [
                'category_id' => $categoryIds[0] ?? 1,
                'name' => 'شاي أحمر',
                'price' => 15.00,
                'cost' => 5.00,
                'type' => ProductType::Manufactured,
                'unit' => 'كوب',
                'printer_id' => $printerIds[1] ?? 1,
                'legacy' => false,
            ],
            [
                'category_id' => $categoryIds[0] ?? 1,
                'name' => 'قهوة تركي',
                'price' => 20.00,
                'cost' => 8.00,
                'type' => ProductType::Manufactured,
                'unit' => 'فنجان',
                'printer_id' => $printerIds[1] ?? 1,
                'legacy' => false,
            ],
            [
                'category_id' => $categoryIds[0] ?? 1,
                'name' => 'عصير برتقال طازج',
                'price' => 25.00,
                'cost' => 12.00,
                'type' => ProductType::Manufactured,
                'unit' => 'كوب',
                'printer_id' => $printerIds[1] ?? 1,
                'legacy' => false,
            ],

            // الطعام الرئيسي
            [
                'category_id' => $categoryIds[1] ?? 1,
                'name' => 'فراخ مشوية',
                'price' => 120.00,
                'cost' => 80.00,
                'type' => ProductType::Manufactured,
                'unit' => 'وجبة',
                'printer_id' => $printerIds[0] ?? 1,
                'legacy' => false,
            ],
            [
                'category_id' => $categoryIds[1] ?? 1,
                'name' => 'كباب لحمة',
                'price' => 150.00,
                'cost' => 100.00,
                'type' => ProductType::Manufactured,
                'unit' => 'وجبة',
                'printer_id' => $printerIds[2] ?? 1,
                'legacy' => false,
            ],
            [
                'category_id' => $categoryIds[1] ?? 1,
                'name' => 'سمك مشوي',
                'price' => 180.00,
                'cost' => 120.00,
                'type' => ProductType::Manufactured,
                'unit' => 'وجبة',
                'printer_id' => $printerIds[2] ?? 1,
                'legacy' => false,
            ],

            // المقبلات
            [
                'category_id' => $categoryIds[2] ?? 1,
                'name' => 'حمص شامي',
                'price' => 30.00,
                'cost' => 15.00,
                'type' => ProductType::Manufactured,
                'unit' => 'طبق',
                'printer_id' => $printerIds[0] ?? 1,
                'legacy' => false,
            ],
            [
                'category_id' => $categoryIds[2] ?? 1,
                'name' => 'بابا غنوج',
                'price' => 35.00,
                'cost' => 18.00,
                'type' => ProductType::Manufactured,
                'unit' => 'طبق',
                'printer_id' => $printerIds[0] ?? 1,
                'legacy' => false,
            ],

            // الحلويات
            [
                'category_id' => $categoryIds[3] ?? 1,
                'name' => 'مهلبية',
                'price' => 40.00,
                'cost' => 20.00,
                'type' => ProductType::Manufactured,
                'unit' => 'قطعة',
                'printer_id' => $printerIds[3] ?? 1,
                'legacy' => false,
            ],
            [
                'category_id' => $categoryIds[3] ?? 1,
                'name' => 'كنافة بالجبنة',
                'price' => 60.00,
                'cost' => 35.00,
                'type' => ProductType::Manufactured,
                'unit' => 'قطعة',
                'printer_id' => $printerIds[3] ?? 1,
                'legacy' => false,
            ],

            // السلطات
            [
                'category_id' => $categoryIds[4] ?? 1,
                'name' => 'سلطة خضراء',
                'price' => 25.00,
                'cost' => 12.00,
                'type' => ProductType::Manufactured,
                'unit' => 'طبق',
                'printer_id' => $printerIds[0] ?? 1,
                'legacy' => false,
            ],
            [
                'category_id' => $categoryIds[4] ?? 1,
                'name' => 'تبولة',
                'price' => 30.00,
                'cost' => 15.00,
                'type' => ProductType::Manufactured,
                'unit' => 'طبق',
                'printer_id' => $printerIds[0] ?? 1,
                'legacy' => false,
            ],

            // Raw Materials
            [
                'category_id' => $categoryIds[0] ?? 1,
                'name' => 'أرز مصري',
                'price' => 35.00,
                'cost' => 25.00,
                'type' => ProductType::RawMaterial,
                'unit' => 'كيلو',
                'printer_id' => $printerIds[0] ?? 1,
                'legacy' => false,
            ],
            [
                'category_id' => $categoryIds[0] ?? 1,
                'name' => 'دقيق أبيض',
                'price' => 15.00,
                'cost' => 12.00,
                'type' => ProductType::RawMaterial,
                'unit' => 'كيلو',
                'printer_id' => $printerIds[0] ?? 1,
                'legacy' => false,
            ],
            [
                'category_id' => $categoryIds[0] ?? 1,
                'name' => 'زيت طبخ',
                'price' => 45.00,
                'cost' => 35.00,
                'type' => ProductType::RawMaterial,
                'unit' => 'لتر',
                'printer_id' => $printerIds[0] ?? 1,
                'legacy' => false,
            ],

            // Consumables
            [
                'category_id' => $categoryIds[0] ?? 1,
                'name' => 'أطباق ورقية',
                'price' => 2.00,
                'cost' => 1.50,
                'type' => ProductType::Consumable,
                'unit' => 'قطعة',
                'printer_id' => $printerIds[4] ?? 1,
                'legacy' => false,
            ],
            [
                'category_id' => $categoryIds[0] ?? 1,
                'name' => 'أكواب بلاستيك',
                'price' => 1.50,
                'cost' => 1.00,
                'type' => ProductType::Consumable,
                'unit' => 'قطعة',
                'printer_id' => $printerIds[4] ?? 1,
                'legacy' => false,
            ],
        ];

        foreach ($products as $product) {
            Product::create($product);
        }
    }
}
