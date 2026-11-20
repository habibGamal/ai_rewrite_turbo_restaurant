<?php

use App\Enums\ProductType;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Warehouse;
use App\Modules\BusinessType;
use App\Services\FeatureService;
use Database\Seeders\ClothesBusinessSeeder;

beforeEach(function () {
    // Ensure default warehouse exists for the test run
    Warehouse::firstOrCreate(
        ['is_default' => true],
        ['name' => 'المخزن الرئيسي', 'is_active' => true]
    );
});

it('seeds clothes business type categories and products with single variant group', function () {
    $seeder = new ClothesBusinessSeeder;
    $seeder->run();

    // 1. Business type should be fashion
    expect(app(FeatureService::class)->businessType())->toBe(BusinessType::FASHION);

    // 2. Categories in Egyptian dialect
    $mainCategories = Category::whereNull('parent_id')->pluck('name')->all();
    expect($mainCategories)->toContain(
        'هدوم رجالي',
        'هدوم حريمي',
        'لبس أطفال',
        'شوزات وكوتشيات',
        'إكسسوارات وشياكة'
    );

    $subCategories = Category::whereNotNull('parent_id')->pluck('name')->all();
    expect($subCategories)->toContain(
        'تيشرتات وبولو',
        'قمصان كاجوال وخروج',
        'بناطيل وجينزات',
        'سويت شيرتات وهوديز',
        'جواكت وسديري',
        'دريسات وفساتين خروج',
        'بلوزات وشيميزات',
        'بناطيل وجيبات',
        'عبايات وكارديجان',
        'أولادي شبابي',
        'بناتي كيوت',
        'سالوبتات ومواليد',
        'كوتشيات سنيكرز وكاجوال',
        'جزم كلاسيك وصنادل',
        'أحزمة ومحافظ جلد',
        'كابات وطواقي',
        'شرابات قطن'
    );

    // 3. Exactly one ProductGroup with variants
    expect(ProductGroup::count())->toBe(1);

    $group = ProductGroup::first();
    expect($group->name)->toBe('تيشيرت بيزيك أوفر سايز قطن مصري')
        ->and($group->brand)->toBe('كايرو وير')
        ->and((float) $group->base_price)->toBe(350.0)
        ->and((float) $group->base_cost)->toBe(180.0)
        ->and($group->options()->count())->toBe(2);

    expect($group->options()->pluck('name')->all())->toEqualCanonicalizing(['المقاس', 'اللون']);

    // 4 options for sizes * 4 options for colors = 16 variants
    expect($group->variants()->count())->toBe(16);

    foreach ($group->variants as $variant) {
        expect($variant->type)->toBe(ProductType::Consumable)
            ->and($variant->product_group_id)->toBe($group->id)
            ->and($variant->variant_label)->not->toBeEmpty()
            ->and($variant->sku)->toStartWith('TSH-OVR-')
            ->and($variant->barcode)->not->toBeEmpty()
            ->and($variant->unit)->toBe('قطعة');

        // Check inventory stock row
        $stock = InventoryItem::where('product_id', $variant->id)->value('quantity');
        expect((float) $stock)->toBe(20.0);
    }

    // 4. The rest of products must be normal products (product_group_id is null)
    $normalProducts = Product::whereNull('product_group_id')->get();
    expect($normalProducts->count())->toBeGreaterThan(30);

    foreach ($normalProducts as $product) {
        expect($product->product_group_id)->toBeNull()
            ->and($product->type)->toBe(ProductType::Consumable)
            ->and($product->price)->toBeGreaterThan(0)
            ->and($product->cost)->toBeGreaterThan(0)
            ->and($product->barcode)->not->toBeEmpty();

        $stock = InventoryItem::where('product_id', $product->id)->value('quantity');
        expect((float) $stock)->toBeGreaterThan(0);
    }

    // 5. Check specific Egyptian colloquial product names
    $normalNames = $normalProducts->pluck('name')->all();
    expect($normalNames)->toContain(
        'قميص كتان بيج كاجوال نص كم',
        'بنطلون كارجو زيتي 6 جيوب جبردين',
        'سويت شيرت هودي ميلتون مبطن رمادي',
        'دريس صيفي مشجر شيفون طويل بيج في وردي',
        'شيميز ستان حرير حر أوف وايت للمحجبات',
        'سالوبت أطفال بيبي قطن تركي مقفول كباسيل',
        'كوتشي سنيكرز أبيض فلات نعل طبي خفيف',
        'حزام رجالي جلد طبيعي بقري توكة معدن أسود',
        'طقم شرابات رجالي كعب قطن مصري (علبة 3 أزواج)'
    );
});

it('is idempotent when re-run multiple times', function () {
    $seeder = new ClothesBusinessSeeder;
    $seeder->run();

    $initialCategoriesCount = Category::count();
    $initialProductGroupsCount = ProductGroup::count();
    $initialVariantsCount = Product::whereNotNull('product_group_id')->count();
    $initialNormalProductsCount = Product::whereNull('product_group_id')->count();

    // Re-run
    $seeder->run();

    expect(Category::count())->toBe($initialCategoriesCount)
        ->and(ProductGroup::count())->toBe($initialProductGroupsCount)
        ->and(Product::whereNotNull('product_group_id')->count())->toBe($initialVariantsCount)
        ->and(Product::whereNull('product_group_id')->count())->toBe($initialNormalProductsCount);
});
