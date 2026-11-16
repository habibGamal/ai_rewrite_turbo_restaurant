<?php

namespace Database\Seeders;

use App\Enums\ProductType;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\Warehouse;
use App\Modules\BusinessType;
use App\Services\FeatureService;
use App\Services\Products\ProductVariantService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ClothesBusinessSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            // 1. Ensure business type is set to fashion (clothes) if not locked
            $features = app(FeatureService::class);
            if (! $features->businessTypeLocked()) {
                $features->setBusinessType(BusinessType::FASHION);
            }

            // 2. Ensure default warehouse exists
            $warehouse = Warehouse::query()->where('is_default', true)->first();
            if (! $warehouse) {
                $warehouse = Warehouse::firstOrCreate(
                    ['name' => 'المخزن الرئيسي'],
                    ['is_default' => true, 'is_active' => true]
                );
            }

            $variantService = app(ProductVariantService::class);

            // 3. Categories structure in Egyptian Arabic (Main categories and subcategories)
            $categoryTree = [
                'هدوم رجالي' => [
                    'تيشرتات وبولو',
                    'قمصان كاجوال وخروج',
                    'بناطيل وجينزات',
                    'سويت شيرتات وهوديز',
                    'جواكت وسديري',
                ],
                'هدوم حريمي' => [
                    'دريسات وفساتين خروج',
                    'بلوزات وشيميزات',
                    'بناطيل وجيبات',
                    'عبايات وكارديجان',
                ],
                'لبس أطفال' => [
                    'أولادي شبابي',
                    'بناتي كيوت',
                    'سالوبتات ومواليد',
                ],
                'شوزات وكوتشيات' => [
                    'كوتشيات سنيكرز وكاجوال',
                    'جزم كلاسيك وصنادل',
                ],
                'إكسسوارات وشياكة' => [
                    'أحزمة ومحافظ جلد',
                    'كابات وطواقي',
                    'شرابات قطن',
                ],
            ];

            $createdSubCategories = [];

            foreach ($categoryTree as $mainName => $subNames) {
                $mainCategory = Category::firstOrCreate([
                    'name' => $mainName,
                    'parent_id' => null,
                ]);

                foreach ($subNames as $subName) {
                    $subCategory = Category::firstOrCreate([
                        'name' => $subName,
                        'parent_id' => $mainCategory->id,
                    ]);
                    $createdSubCategories[$subName] = $subCategory;
                }
            }

            // 4. The ONLY Product Group with variants (تيشيرت بيزيك أوفر سايز قطن مصري)
            $tshirtCategory = $createdSubCategories['تيشرتات وبولو'];

            $productGroup = ProductGroup::firstOrCreate(
                ['name' => 'تيشيرت بيزيك أوفر سايز قطن مصري'],
                [
                    'category_id' => $tshirtCategory->id,
                    'brand' => 'كايرو وير',
                    'sku_prefix' => 'TSH-OVR',
                    'base_price' => 350.00,
                    'base_cost' => 180.00,
                    'unit' => 'قطعة',
                    'is_active' => true,
                ]
            );

            // Size axis
            $sizeOption = ProductOption::firstOrCreate(
                ['product_group_id' => $productGroup->id, 'name' => 'المقاس'],
                ['position' => 0]
            );

            $sizes = [
                ['value' => 'M', 'code' => 'M', 'position' => 0],
                ['value' => 'L', 'code' => 'L', 'position' => 1],
                ['value' => 'XL', 'code' => 'XL', 'position' => 2],
                ['value' => 'XXL', 'code' => '2XL', 'position' => 3],
            ];

            foreach ($sizes as $s) {
                ProductOptionValue::firstOrCreate(
                    ['product_option_id' => $sizeOption->id, 'value' => $s['value']],
                    ['code' => $s['code'], 'position' => $s['position']]
                );
            }

            // Color axis
            $colorOption = ProductOption::firstOrCreate(
                ['product_group_id' => $productGroup->id, 'name' => 'اللون'],
                ['position' => 1]
            );

            $colors = [
                ['value' => 'أسود ملكي', 'code' => 'BLK', 'color_hex' => '#000000', 'position' => 0],
                ['value' => 'أبيض ناصع', 'code' => 'WHT', 'color_hex' => '#FFFFFF', 'position' => 1],
                ['value' => 'كحلي غامق', 'code' => 'NVY', 'color_hex' => '#000080', 'position' => 2],
                ['value' => 'زيتي جيشي', 'code' => 'OLV', 'color_hex' => '#556B2F', 'position' => 3],
            ];

            foreach ($colors as $c) {
                ProductOptionValue::firstOrCreate(
                    ['product_option_id' => $colorOption->id, 'value' => $c['value']],
                    ['code' => $c['code'], 'color_hex' => $c['color_hex'], 'position' => $c['position']]
                );
            }

            // Generate variants SKUs for the product group
            $variantService->generate($productGroup->fresh());

            // Assign unique EAN-13 barcodes to missing variants
            $variantService->assignMissingBarcodes($productGroup->fresh());

            // Set stock quantities for all variants of this group
            foreach ($productGroup->variants()->get() as $variant) {
                InventoryItem::where('product_id', $variant->id)
                    ->where('warehouse_id', $warehouse->id)
                    ->update(['quantity' => 20]);
            }

            // 5. Normal Products (Standalone products, no variants)
            /** @var list<array{sub_category: string, name: string, price: float, cost: float, sku: string, unit: string, initial_stock: int}> $normalProducts */
            $normalProducts = [
                // قمصان كاجوال وخروج
                [
                    'sub_category' => 'قمصان كاجوال وخروج',
                    'name' => 'قميص كتان بيج كاجوال نص كم',
                    'price' => 420.00,
                    'cost' => 220.00,
                    'sku' => 'CLT-SHR-001',
                    'unit' => 'قطعة',
                    'initial_stock' => 25,
                ],
                [
                    'sub_category' => 'قمصان كاجوال وخروج',
                    'name' => 'قميص كاروهات شبابي قطن صيفي',
                    'price' => 380.00,
                    'cost' => 190.00,
                    'sku' => 'CLT-SHR-002',
                    'unit' => 'قطعة',
                    'initial_stock' => 30,
                ],
                [
                    'sub_category' => 'قمصان كاجوال وخروج',
                    'name' => 'قميص أكسفورد أبيض كلاسيك سليم',
                    'price' => 450.00,
                    'cost' => 240.00,
                    'sku' => 'CLT-SHR-003',
                    'unit' => 'قطعة',
                    'initial_stock' => 20,
                ],

                // بناطيل وجينزات
                [
                    'sub_category' => 'بناطيل وجينزات',
                    'name' => 'بنطلون جينز باجي مريح كحلي',
                    'price' => 550.00,
                    'cost' => 290.00,
                    'sku' => 'CLT-JNS-001',
                    'unit' => 'قطعة',
                    'initial_stock' => 35,
                ],
                [
                    'sub_category' => 'بناطيل وجينزات',
                    'name' => 'بنطلون كارجو زيتي 6 جيوب جبردين',
                    'price' => 520.00,
                    'cost' => 270.00,
                    'sku' => 'CLT-JNS-002',
                    'unit' => 'قطعة',
                    'initial_stock' => 40,
                ],
                [
                    'sub_category' => 'بناطيل وجينزات',
                    'name' => 'بنطلون جينز سليم فيت أزرق ثلجي',
                    'price' => 490.00,
                    'cost' => 260.00,
                    'sku' => 'CLT-JNS-003',
                    'unit' => 'قطعة',
                    'initial_stock' => 25,
                ],

                // سويت شيرتات وهوديز
                [
                    'sub_category' => 'سويت شيرتات وهوديز',
                    'name' => 'سويت شيرت هودي ميلتون مبطن رمادي',
                    'price' => 650.00,
                    'cost' => 340.00,
                    'sku' => 'CLT-SWT-001',
                    'unit' => 'قطعة',
                    'initial_stock' => 20,
                ],
                [
                    'sub_category' => 'سويت شيرتات وهوديز',
                    'name' => 'سويت شيرت راوند كحلي بدون كابيشو',
                    'price' => 480.00,
                    'cost' => 250.00,
                    'sku' => 'CLT-SWT-002',
                    'unit' => 'قطعة',
                    'initial_stock' => 30,
                ],

                // جواكت وسديري
                [
                    'sub_category' => 'جواكت وسديري',
                    'name' => 'جاكيت جينز شبابي أزرق غامق',
                    'price' => 750.00,
                    'cost' => 400.00,
                    'sku' => 'CLT-JKT-001',
                    'unit' => 'قطعة',
                    'initial_stock' => 15,
                ],
                [
                    'sub_category' => 'جواكت وسديري',
                    'name' => 'جاكيت بامب وتربروف منفوخ أسود',
                    'price' => 950.00,
                    'cost' => 520.00,
                    'sku' => 'CLT-JKT-002',
                    'unit' => 'قطعة',
                    'initial_stock' => 12,
                ],
                [
                    'sub_category' => 'جواكت وسديري',
                    'name' => 'سديري كت ووتر بروف زيتي كاجوال',
                    'price' => 580.00,
                    'cost' => 300.00,
                    'sku' => 'CLT-JKT-003',
                    'unit' => 'قطعة',
                    'initial_stock' => 18,
                ],

                // دريسات وفساتين خروج
                [
                    'sub_category' => 'دريسات وفساتين خروج',
                    'name' => 'دريس صيفي مشجر شيفون طويل بيج في وردي',
                    'price' => 680.00,
                    'cost' => 350.00,
                    'sku' => 'CLT-DRS-001',
                    'unit' => 'قطعة',
                    'initial_stock' => 20,
                ],
                [
                    'sub_category' => 'دريسات وفساتين خروج',
                    'name' => 'فستان سهرة أسود سواريه بليسيه شيك',
                    'price' => 1250.00,
                    'cost' => 680.00,
                    'sku' => 'CLT-DRS-002',
                    'unit' => 'قطعة',
                    'initial_stock' => 10,
                ],

                // بلوزات وشيميزات
                [
                    'sub_category' => 'بلوزات وشيميزات',
                    'name' => 'شيميز ستان حرير حر أوف وايت للمحجبات',
                    'price' => 420.00,
                    'cost' => 210.00,
                    'sku' => 'CLT-BLZ-001',
                    'unit' => 'قطعة',
                    'initial_stock' => 25,
                ],
                [
                    'sub_category' => 'بلوزات وشيميزات',
                    'name' => 'بلوزة قطن واسعة صيفي بكم منفوخ',
                    'price' => 380.00,
                    'cost' => 190.00,
                    'sku' => 'CLT-BLZ-002',
                    'unit' => 'قطعة',
                    'initial_stock' => 30,
                ],

                // بناطيل وجيبات
                [
                    'sub_category' => 'بناطيل وجيبات',
                    'name' => 'بنطلون وايد ليج قماش هاي ويست بيج',
                    'price' => 460.00,
                    'cost' => 230.00,
                    'sku' => 'CLT-SKT-001',
                    'unit' => 'قطعة',
                    'initial_stock' => 25,
                ],
                [
                    'sub_category' => 'بناطيل وجيبات',
                    'name' => 'جيبة بليسيه شيفون بطانة ناعمة أسود',
                    'price' => 400.00,
                    'cost' => 200.00,
                    'sku' => 'CLT-SKT-002',
                    'unit' => 'قطعة',
                    'initial_stock' => 20,
                ],

                // عبايات وكارديجان
                [
                    'sub_category' => 'عبايات وكارديجان',
                    'name' => 'كارديجان صوف تريكو طويل خروج كشمير',
                    'price' => 580.00,
                    'cost' => 300.00,
                    'sku' => 'CLT-ABY-001',
                    'unit' => 'قطعة',
                    'initial_stock' => 15,
                ],
                [
                    'sub_category' => 'عبايات وكارديجان',
                    'name' => 'عباية بيتي استقبال قطن ليكرا ناعمة مطرزة',
                    'price' => 480.00,
                    'cost' => 240.00,
                    'sku' => 'CLT-ABY-002',
                    'unit' => 'قطعة',
                    'initial_stock' => 25,
                ],

                // أولادي شبابي
                [
                    'sub_category' => 'أولادي شبابي',
                    'name' => 'طقم أولادي شورت جينز وتيشيرت مطبوع',
                    'price' => 380.00,
                    'cost' => 190.00,
                    'sku' => 'CLT-KDB-001',
                    'unit' => 'طقم',
                    'initial_stock' => 30,
                ],
                [
                    'sub_category' => 'أولادي شبابي',
                    'name' => 'ترينج أولادي خروج ميلتون شتوي أحمر في كحلي',
                    'price' => 520.00,
                    'cost' => 260.00,
                    'sku' => 'CLT-KDB-002',
                    'unit' => 'طقم',
                    'initial_stock' => 20,
                ],

                // بناتي كيوت
                [
                    'sub_category' => 'بناتي كيوت',
                    'name' => 'فستان أطفال بناتي تل منفوش للعيد وردي',
                    'price' => 450.00,
                    'cost' => 220.00,
                    'sku' => 'CLT-KDG-001',
                    'unit' => 'قطعة',
                    'initial_stock' => 25,
                ],
                [
                    'sub_category' => 'بناتي كيوت',
                    'name' => 'طقم بناتي بنطلون جينز وتوب كت كاجوال',
                    'price' => 390.00,
                    'cost' => 195.00,
                    'sku' => 'CLT-KDG-002',
                    'unit' => 'طقم',
                    'initial_stock' => 25,
                ],

                // سالوبتات ومواليد
                [
                    'sub_category' => 'سالوبتات ومواليد',
                    'name' => 'سالوبت أطفال بيبي قطن تركي مقفول كباسيل',
                    'price' => 220.00,
                    'cost' => 110.00,
                    'sku' => 'CLT-KDBB-001',
                    'unit' => 'قطعة',
                    'initial_stock' => 40,
                ],
                [
                    'sub_category' => 'سالوبتات ومواليد',
                    'name' => 'طقم سبوع مواليد قطن 5 قطع بيج',
                    'price' => 350.00,
                    'cost' => 170.00,
                    'sku' => 'CLT-KDBB-002',
                    'unit' => 'طقم',
                    'initial_stock' => 20,
                ],

                // كوتشيات سنيكرز وكاجوال
                [
                    'sub_category' => 'كوتشيات سنيكرز وكاجوال',
                    'name' => 'كوتشي سنيكرز أبيض فلات نعل طبي خفيف',
                    'price' => 550.00,
                    'cost' => 280.00,
                    'sku' => 'CLT-SNK-001',
                    'unit' => 'زوج',
                    'initial_stock' => 30,
                ],
                [
                    'sub_category' => 'كوتشيات سنيكرز وكاجوال',
                    'name' => 'كوتشي جري خفيف أسود شبك مريح',
                    'price' => 480.00,
                    'cost' => 240.00,
                    'sku' => 'CLT-SNK-002',
                    'unit' => 'زوج',
                    'initial_stock' => 35,
                ],

                // جزم كلاسيك وصنادل
                [
                    'sub_category' => 'جزم كلاسيك وصنادل',
                    'name' => 'جزمة كلاسيك جلد طبيعي برباط هافان',
                    'price' => 750.00,
                    'cost' => 420.00,
                    'sku' => 'CLT-SHO-001',
                    'unit' => 'زوج',
                    'initial_stock' => 15,
                ],
                [
                    'sub_category' => 'جزم كلاسيك وصنادل',
                    'name' => 'صندل صيفي كاجوال جلد بني مريح',
                    'price' => 360.00,
                    'cost' => 180.00,
                    'sku' => 'CLT-SHO-002',
                    'unit' => 'زوج',
                    'initial_stock' => 25,
                ],

                // أحزمة ومحافظ جلد
                [
                    'sub_category' => 'أحزمة ومحافظ جلد',
                    'name' => 'حزام رجالي جلد طبيعي بقري توكة معدن أسود',
                    'price' => 220.00,
                    'cost' => 110.00,
                    'sku' => 'CLT-ACC-001',
                    'unit' => 'قطعة',
                    'initial_stock' => 50,
                ],
                [
                    'sub_category' => 'أحزمة ومحافظ جلد',
                    'name' => 'محفظة رجالي جلد طبيعي ضد السرقة هافان',
                    'price' => 260.00,
                    'cost' => 130.00,
                    'sku' => 'CLT-ACC-002',
                    'unit' => 'قطعة',
                    'initial_stock' => 40,
                ],

                // كابات وطواقي
                [
                    'sub_category' => 'كابات وطواقي',
                    'name' => 'كاب شبابي بيسبول قطن مطرز كحلي',
                    'price' => 150.00,
                    'cost' => 70.00,
                    'sku' => 'CLT-CAP-001',
                    'unit' => 'قطعة',
                    'initial_stock' => 60,
                ],
                [
                    'sub_category' => 'كابات وطواقي',
                    'name' => 'طاقية آيس كاب صوف شتوي مضلعة سوداء',
                    'price' => 120.00,
                    'cost' => 55.00,
                    'sku' => 'CLT-CAP-002',
                    'unit' => 'قطعة',
                    'initial_stock' => 50,
                ],

                // شرابات قطن
                [
                    'sub_category' => 'شرابات قطن',
                    'name' => 'طقم شرابات رجالي كعب قطن مصري (علبة 3 أزواج)',
                    'price' => 95.00,
                    'cost' => 45.00,
                    'sku' => 'CLT-SCK-001',
                    'unit' => 'علبة',
                    'initial_stock' => 80,
                ],
                [
                    'sub_category' => 'شرابات قطن',
                    'name' => 'طقم شرابات شوز مخفية سيليكون حريمي (علبة 3 أزواج)',
                    'price' => 85.00,
                    'cost' => 40.00,
                    'sku' => 'CLT-SCK-002',
                    'unit' => 'علبة',
                    'initial_stock' => 90,
                ],
                [
                    'sub_category' => 'شرابات قطن',
                    'name' => 'شراب صوف شتوي طويل تقيل منقوش',
                    'price' => 60.00,
                    'cost' => 25.00,
                    'sku' => 'CLT-SCK-003',
                    'unit' => 'زوج',
                    'initial_stock' => 70,
                ],
            ];

            foreach ($normalProducts as $data) {
                $subCat = $createdSubCategories[$data['sub_category']] ?? null;
                if (! $subCat) {
                    continue;
                }

                $initialStock = $data['initial_stock'];

                $product = Product::firstOrCreate(
                    [
                        'name' => $data['name'],
                        'product_group_id' => null,
                    ],
                    [
                        'category_id' => $subCat->id,
                        'price' => $data['price'],
                        'cost' => $data['cost'],
                        'min_stock' => 5.00,
                        'type' => ProductType::Consumable,
                        'unit' => $data['unit'],
                        'sku' => $data['sku'],
                        'barcode' => $variantService->generateBarcode(),
                        'legacy' => false,
                        'is_active' => true,
                    ]
                );

                InventoryItem::where('product_id', $product->id)
                    ->where('warehouse_id', $warehouse->id)
                    ->update(['quantity' => $initialStock]);
            }
        });
    }
}
