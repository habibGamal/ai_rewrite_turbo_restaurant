<?php

use App\Enums\OptionalFeature;
use App\Enums\ProductType;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductGroup;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Services\FeatureService;
use App\Services\Products\ProductLookupService;
use App\Services\Products\ProductVariantService;
use Illuminate\Validation\ValidationException;

/**
 * @param  array<string, array<int, string>>  $axes  option name => values
 */
function variantGroup(array $axes = ['المقاس' => ['S', 'M'], 'اللون' => ['أسود', 'أبيض']], array $attributes = []): ProductGroup
{
    $group = ProductGroup::create(array_merge([
        'name' => 'تيشيرت',
        'category_id' => Category::factory()->create()->id,
        'sku_prefix' => 'tsh',
        'base_price' => 150,
        'base_cost' => 80,
    ], $attributes));

    $position = 0;
    foreach ($axes as $name => $values) {
        $option = ProductOption::create(['product_group_id' => $group->id, 'name' => $name, 'position' => $position++]);
        foreach ($values as $i => $value) {
            ProductOptionValue::create(['product_option_id' => $option->id, 'value' => $value, 'position' => $i]);
        }
    }

    return $group->fresh();
}

function variants(): ProductVariantService
{
    return app(ProductVariantService::class);
}

it('generates one consumable SKU per combination with stock rows', function () {
    $group = variantGroup();

    $created = variants()->generate($group);

    expect($created)->toHaveCount(4);
    expect($group->variants()->pluck('variant_label')->all())
        ->toEqualCanonicalizing(['S / أسود', 'S / أبيض', 'M / أسود', 'M / أبيض']);

    $sku = $group->variants()->where('variant_label', 'M / أسود')->first();
    expect($sku->type)->toBe(ProductType::Consumable)
        ->and($sku->name)->toBe('تيشيرت - M / أسود')
        ->and($sku->sku)->toBe('TSH-M-أسود')
        ->and((float) $sku->price)->toBe(150.0)
        ->and((float) $sku->cost)->toBe(80.0)
        ->and($sku->category_id)->toBe($group->category_id)
        ->and($sku->optionValues)->toHaveCount(2)
        ->and(InventoryItem::where('product_id', $sku->id)->exists())->toBeTrue();
});

it('only creates the missing SKUs when re-run after adding a value', function () {
    $group = variantGroup();
    variants()->generate($group);

    $size = $group->options()->where('name', 'المقاس')->first();
    ProductOptionValue::create(['product_option_id' => $size->id, 'value' => 'XL', 'position' => 5]);

    $created = variants()->generate($group->fresh());

    expect($created)->toHaveCount(2)
        ->and($created->pluck('variant_label')->sort()->values()->all())->toBe(['XL / أبيض', 'XL / أسود'])
        ->and($group->variants()->count())->toBe(6);
});

it('supports any number of option axes', function () {
    $group = variantGroup([
        'المقاس' => ['S', 'M'],
        'اللون' => ['أسود', 'أبيض'],
        'القصة' => ['Slim', 'Regular'],
        'الخامة' => ['قطن', 'كتان'],
    ]);

    expect(variants()->generate($group))->toHaveCount(16);
});

it('refuses to explode past the combination cap', function () {
    $values = array_map(fn ($i) => "v$i", range(1, 30));
    $group = variantGroup(['a' => $values, 'b' => $values]);

    variants()->generate($group);
})->throws(ValidationException::class);

it('refuses weighed groups', function () {
    $group = variantGroup(attributes: ['unit' => 'kg']);

    variants()->generate($group);
})->throws(ValidationException::class);

it('pushes the group price to SKUs without an override only', function () {
    $group = variantGroup();
    variants()->generate($group);
    $custom = $group->variants()->first();
    variants()->setVariantPrice($custom, 199);

    $group->update(['base_price' => 175, 'name' => 'تيشيرت بيسك']);

    $prices = $group->variants()->get()->mapWithKeys(fn ($p) => [$p->id => (float) $p->price]);
    expect($prices[$custom->id])->toBe(199.0);
    expect($prices->except($custom->id)->unique()->values()->all())->toBe([175.0]);
    expect($custom->fresh()->name)->toStartWith('تيشيرت بيسك - ');

    variants()->setVariantPrice($custom->fresh(), null);
    expect((float) $custom->fresh()->price)->toBe(175.0)
        ->and($custom->fresh()->has_custom_price)->toBeFalse();
});

it('relabels SKUs when a value is renamed', function () {
    $group = variantGroup();
    variants()->generate($group);

    ProductOptionValue::where('value', 'أسود')->first()->update(['value' => 'كحلي']);

    expect($group->variants()->pluck('variant_label')->filter(fn ($l) => str_contains($l, 'كحلي')))->toHaveCount(2)
        ->and($group->variants()->where('name', 'like', '%أسود%')->count())->toBe(0);
});

it('blocks deleting a value that SKUs use', function () {
    $group = variantGroup();
    variants()->generate($group);

    ProductOptionValue::where('value', 'S')->first()->delete();
})->throws(ValidationException::class);

it('allows deleting an unused value', function () {
    $group = variantGroup();
    $value = ProductOptionValue::where('value', 'S')->first();

    $value->delete();

    expect(ProductOptionValue::find($value->id))->toBeNull();
});

it('reports variants left incomplete after an axis is added', function () {
    $group = variantGroup(['المقاس' => ['S', 'M']]);
    variants()->generate($group);

    $color = ProductOption::create(['product_group_id' => $group->id, 'name' => 'اللون', 'position' => 1]);
    ProductOptionValue::create(['product_option_id' => $color->id, 'value' => 'أحمر']);

    expect(variants()->incompleteVariants($group->fresh()))->toHaveCount(2)
        ->and(variants()->generate($group->fresh()))->toHaveCount(2);
});

it('assigns valid unique EAN-13 barcodes that scan to the SKU', function () {
    $group = variantGroup();
    variants()->generate($group);

    expect(variants()->assignMissingBarcodes($group))->toBe(4);

    $sku = $group->variants()->first();
    expect($sku->barcode)->toHaveLength(13)
        ->and(substr($sku->barcode, 0, 2))->not->toBe('23')
        ->and((int) substr($sku->barcode, -1))->toBe(ProductVariantService::ean13CheckDigit(substr($sku->barcode, 0, 12)))
        ->and(ProductBarcode::where('product_id', $sku->id)->where('is_primary', true)->value('barcode'))->toBe($sku->barcode);

    $result = app(ProductLookupService::class)->lookup($sku->barcode);
    expect($result?->product->id)->toBe($sku->id);

    expect(variants()->assignMissingBarcodes($group))->toBe(0);
});

it('keeps SKU codes unique across groups', function () {
    $a = variantGroup(['المقاس' => ['M']]);
    $b = variantGroup(['المقاس' => ['M']]);

    variants()->generate($a);
    variants()->generate($b);

    expect(Product::whereNotNull('sku')->pluck('sku')->sort()->values()->all())->toBe(['TSH-M', 'TSH-M-2']);
});

it('exposes the vendor-only variants flag, off by default', function () {
    $features = app(FeatureService::class);

    expect($features->variantsEnabled())->toBeFalse()
        ->and($features->toInertiaProps())->toHaveKey('variants', false)
        ->and(OptionalFeature::VARIANTS->settingKey()->isVendorOwned())->toBeTrue();

    $features->enable(OptionalFeature::VARIANTS);

    expect($features->variantsEnabled())->toBeTrue();
});
