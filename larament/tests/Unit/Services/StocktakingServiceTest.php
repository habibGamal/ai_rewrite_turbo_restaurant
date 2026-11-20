<?php

use App\Filament\Actions\CloseStocktakingAction;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Stocktaking;
use App\Models\StocktakingItem;
use App\Models\User;
use App\Services\StocktakingService;
use Illuminate\Support\HtmlString;

uses(Tests\Unit\TestCase::class);

describe('StocktakingService - getChangedItemsSinceCount', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->category = Category::firstOrCreate(['name' => 'General'], ['status' => true]);
        $this->service = app(StocktakingService::class);
    });

    it('returns empty array when stock has not changed since counting', function () {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'سكر',
            'type' => \App\Enums\ProductType::Consumable,
            'price' => 10,
            'cost' => 5,
            'min_stock' => 1,
            'unit' => 'kg',
        ]);

        InventoryItem::updateOrCreate(
            ['product_id' => $product->id],
            ['quantity' => 50.000]
        );

        $stocktaking = Stocktaking::create([
            'user_id' => $this->user->id,
            'total' => 0,
        ]);

        StocktakingItem::create([
            'stocktaking_id' => $stocktaking->id,
            'product_id' => $product->id,
            'stock_quantity' => 50.000,
            'real_quantity' => 48.000,
            'price' => 10,
            'total' => 480,
        ]);

        $changed = $this->service->getChangedItemsSinceCount($stocktaking);

        expect($changed)->toBeEmpty();
    });

    it('returns changed items when stock has changed since counting', function () {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'أرز',
            'type' => \App\Enums\ProductType::Consumable,
            'price' => 20,
            'cost' => 15,
            'min_stock' => 2,
            'unit' => 'kg',
        ]);

        $stocktaking = Stocktaking::create([
            'user_id' => $this->user->id,
            'total' => 0,
        ]);

        StocktakingItem::create([
            'stocktaking_id' => $stocktaking->id,
            'product_id' => $product->id,
            'stock_quantity' => 30.000,
            'real_quantity' => 28.000,
            'price' => 20,
            'total' => 560,
        ]);

        InventoryItem::updateOrCreate(
            ['product_id' => $product->id],
            ['quantity' => 25.000]
        );

        $changed = $this->service->getChangedItemsSinceCount($stocktaking);

        expect($changed)->toHaveCount(1)
            ->and($changed[0]['name'])->toBe('أرز')
            ->and($changed[0]['snapshot'])->toBe(30.0)
            ->and($changed[0]['current'])->toBe(25.0);
    });

    it('distinguishes between changed and unchanged items in the same stocktaking', function () {
        $product1 = Product::create([
            'category_id' => $this->category->id,
            'name' => 'شاي',
            'type' => \App\Enums\ProductType::Consumable,
            'price' => 15,
            'cost' => 10,
            'min_stock' => 1,
            'unit' => 'box',
        ]);

        $product2 = Product::create([
            'category_id' => $this->category->id,
            'name' => 'قهوة',
            'type' => \App\Enums\ProductType::Consumable,
            'price' => 25,
            'cost' => 18,
            'min_stock' => 1,
            'unit' => 'kg',
        ]);

        InventoryItem::updateOrCreate(['product_id' => $product1->id], ['quantity' => 10.000]);
        InventoryItem::updateOrCreate(['product_id' => $product2->id], ['quantity' => 8.000]);

        $stocktaking = Stocktaking::create([
            'user_id' => $this->user->id,
            'total' => 0,
        ]);

        StocktakingItem::create([
            'stocktaking_id' => $stocktaking->id,
            'product_id' => $product1->id,
            'stock_quantity' => 10.000,
            'real_quantity' => 10.000,
            'price' => 15,
            'total' => 150,
        ]);

        StocktakingItem::create([
            'stocktaking_id' => $stocktaking->id,
            'product_id' => $product2->id,
            'stock_quantity' => 12.000,
            'real_quantity' => 11.000,
            'price' => 25,
            'total' => 275,
        ]);

        $changed = $this->service->getChangedItemsSinceCount($stocktaking);

        expect($changed)->toHaveCount(1)
            ->and($changed[0]['name'])->toBe('قهوة')
            ->and($changed[0]['snapshot'])->toBe(12.0)
            ->and($changed[0]['current'])->toBe(8.0);
    });

    it('respects 3-decimal precision comparison', function () {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'بهارات',
            'type' => \App\Enums\ProductType::Consumable,
            'price' => 5,
            'cost' => 3,
            'min_stock' => 1,
            'unit' => 'kg',
        ]);

        // Difference within float precision (< 0.0005) is considered unchanged
        InventoryItem::updateOrCreate(['product_id' => $product->id], ['quantity' => 1.0002]);

        $stocktaking = Stocktaking::create([
            'user_id' => $this->user->id,
            'total' => 0,
        ]);

        StocktakingItem::create([
            'stocktaking_id' => $stocktaking->id,
            'product_id' => $product->id,
            'stock_quantity' => 1.000,
            'real_quantity' => 1.000,
            'price' => 5,
            'total' => 5,
        ]);

        expect($this->service->getChangedItemsSinceCount($stocktaking))->toBeEmpty();

        // Difference >= 0.001 is detected
        InventoryItem::updateOrCreate(['product_id' => $product->id], ['quantity' => 1.001]);
        $changed = $this->service->getChangedItemsSinceCount($stocktaking);
        expect($changed)->toHaveCount(1)
            ->and($changed[0]['current'])->toBe(1.001);
    });
});

describe('CloseStocktakingAction - getModalDescription', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->category = Category::firstOrCreate(['name' => 'General'], ['status' => true]);
        Shift::query()->delete();
    });

    it('returns standard description when stock has not changed and no shift is open', function () {
        $stocktaking = Stocktaking::create([
            'user_id' => $this->user->id,
            'total' => 0,
        ]);

        $description = CloseStocktakingAction::getModalDescription($stocktaking);

        expect($description)->toBeString()
            ->and($description)->toBe('هل أنت متأكد من إغلاق هذا الجرد؟ سيتم تحديث كميات المخزون بناءً على الكميات الفعلية ولن يمكن تعديل الجرد بعد ذلك.');
    });

    it('appends changed stock warning when stock changed since count', function () {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'سكر',
            'type' => \App\Enums\ProductType::Consumable,
            'price' => 10,
            'cost' => 5,
            'min_stock' => 1,
            'unit' => 'kg',
        ]);

        InventoryItem::updateOrCreate(['product_id' => $product->id], ['quantity' => 12.5]);

        $stocktaking = Stocktaking::create([
            'user_id' => $this->user->id,
            'total' => 0,
        ]);

        StocktakingItem::create([
            'stocktaking_id' => $stocktaking->id,
            'product_id' => $product->id,
            'stock_quantity' => 10.0,
            'real_quantity' => 9.5,
            'price' => 10,
            'total' => 95,
        ]);

        $description = CloseStocktakingAction::getModalDescription($stocktaking);

        expect($description)->toBeInstanceOf(HtmlString::class);
        $html = (string) $description;
        expect($html)->toContain('هل أنت متأكد من إغلاق هذا الجرد؟')
            ->and($html)->toContain('⚠️ تنبيه: تغيّر رصيد 1 صنف منذ إدخال الجرد')
            ->and($html)->toContain('سكر: عند الجرد 10 ← الآن 12.5')
            ->and($html)->toContain('سيتم حساب الفرق مقابل الرصيد الحالي وقت الإغلاق، والمستخدم مسؤول عن صحة الكميات.')
            ->and($html)->not->toContain('يوجد شيفت مفتوح حالياً');
    });

    it('appends open shift warning when an active shift exists', function () {
        $stocktaking = Stocktaking::create([
            'user_id' => $this->user->id,
            'total' => 0,
        ]);

        Shift::create([
            'user_id' => $this->user->id,
            'start_cash' => 100,
            'start_at' => now(),
            'closed' => false,
            'end_at' => null,
        ]);

        $description = CloseStocktakingAction::getModalDescription($stocktaking);

        expect($description)->toBeInstanceOf(HtmlString::class);
        $html = (string) $description;
        expect($html)->toContain('هل أنت متأكد من إغلاق هذا الجرد؟')
            ->and($html)->toContain('⚠️ تنبيه: يوجد شيفت مفتوح حالياً، وقد تتغير الكميات أثناء الإغلاق.')
            ->and($html)->not->toContain('تغيّر رصيد');
    });

    it('appends both warnings when stock changed and a shift is open', function () {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'دقيق',
            'type' => \App\Enums\ProductType::Consumable,
            'price' => 12,
            'cost' => 8,
            'min_stock' => 1,
            'unit' => 'kg',
        ]);

        InventoryItem::updateOrCreate(['product_id' => $product->id], ['quantity' => 20]);

        $stocktaking = Stocktaking::create([
            'user_id' => $this->user->id,
            'total' => 0,
        ]);

        StocktakingItem::create([
            'stocktaking_id' => $stocktaking->id,
            'product_id' => $product->id,
            'stock_quantity' => 15.0,
            'real_quantity' => 14.0,
            'price' => 12,
            'total' => 168,
        ]);

        Shift::create([
            'user_id' => $this->user->id,
            'start_cash' => 100,
            'start_at' => now(),
            'closed' => false,
            'end_at' => null,
        ]);

        $description = CloseStocktakingAction::getModalDescription($stocktaking);

        expect($description)->toBeInstanceOf(HtmlString::class);
        $html = (string) $description;
        expect($html)->toContain('⚠️ تنبيه: تغيّر رصيد 1 صنف منذ إدخال الجرد')
            ->and($html)->toContain('دقيق: عند الجرد 15 ← الآن 20')
            ->and($html)->toContain('يوجد شيفت مفتوح حالياً، وقد تتغير الكميات أثناء الإغلاق.');
    });

    it('limits changed items examples to at most 5 with ellipsis when more exist', function () {
        $stocktaking = Stocktaking::create([
            'user_id' => $this->user->id,
            'total' => 0,
        ]);

        for ($i = 1; $i <= 6; $i++) {
            $product = Product::create([
                'category_id' => $this->category->id,
                'name' => "صنف {$i}",
                'type' => \App\Enums\ProductType::Consumable,
                'price' => 10,
                'cost' => 5,
                'min_stock' => 1,
                'unit' => 'piece',
            ]);

            InventoryItem::updateOrCreate(['product_id' => $product->id], ['quantity' => 20]);

            StocktakingItem::create([
                'stocktaking_id' => $stocktaking->id,
                'product_id' => $product->id,
                'stock_quantity' => 10,
                'real_quantity' => 10,
                'price' => 10,
                'total' => 100,
            ]);
        }

        $description = CloseStocktakingAction::getModalDescription($stocktaking);

        expect($description)->toBeInstanceOf(HtmlString::class);
        $html = (string) $description;
        expect($html)->toContain('⚠️ تنبيه: تغيّر رصيد 6 صنف منذ إدخال الجرد')
            ->and($html)->toContain('صنف 1')
            ->and($html)->toContain('صنف 5')
            ->and($html)->not->toContain('صنف 6')
            ->and($html)->toContain('...');
    });
});
