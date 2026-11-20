<?php

use App\Enums\OptionalFeature;
use App\Models\User;
use App\Services\FeatureService;
use App\Services\Products\ProductVariantService;
use App\Support\Barcodes\Code128;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

require_once __DIR__.'/../../Support/VariantGrid.php';

it('encodes code 128 with the right checksum and widths', function () {
    // Set B example: start B (104) + P J J 1 2 3 C, checksum 879 mod 103 = 55.
    expect(Code128::values('PJJ123C'))->toBe([104, 48, 42, 42, 17, 18, 19, 35, 55]);

    // Even-length digits use set C, two digits per symbol.
    expect(Code128::values('6221000000017')[0])->toBe(104)
        ->and(Code128::values('622100000001')[0])->toBe(105)
        ->and(Code128::values('622100000001'))->toHaveCount(1 + 6 + 1);

    // Every symbol is 11 modules wide and the stop 13.
    $values = Code128::values('ABC-12');
    expect(array_sum(Code128::widths('ABC-12')))->toBe(count($values) * 11 + 13);

    expect(Code128::svg('12345678'))->toStartWith('<svg')->toContain('<rect');
});

it('prints one label per variant, or as many as are in stock', function () {
    app(FeatureService::class)->enable(OptionalFeature::VARIANTS);
    $group = gridGroup(['المقاس' => ['S', 'M'], 'اللون' => ['أسود']]);
    app(ProductVariantService::class)->assignMissingBarcodes($group);
    setStock(gridSku($group, 'S / أسود'), 3);
    actingAs(User::factory()->admin()->create());

    $one = get(route('labels.group', ['group' => $group]))->assertOk();
    expect(substr_count($one->getContent(), 'class="label"'))->toBe(2);

    $stock = get(route('labels.group', ['group' => $group, 'copies' => 'stock']))->assertOk();
    expect(substr_count($stock->getContent(), 'class="label"'))->toBe(3)
        ->and($stock->getContent())->toContain('S / أسود');
});

it('answers 404 while variants are off', function () {
    app(FeatureService::class)->enable(OptionalFeature::VARIANTS);
    $group = gridGroup(['المقاس' => ['S']]);
    app(FeatureService::class)->disable(OptionalFeature::VARIANTS);
    actingAs(User::factory()->admin()->create());

    get(route('labels.group', ['group' => $group]))->assertNotFound();
});
