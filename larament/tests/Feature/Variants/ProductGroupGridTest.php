<?php

use App\Enums\OptionalFeature;
use App\Filament\Resources\ProductGroups\Pages\ProductGroupStock;
use App\Models\User;
use App\Services\FeatureService;
use App\Services\Products\ProductGroupGridService;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

require_once __DIR__.'/../../Support/VariantGrid.php';

beforeEach(function () {
    app(FeatureService::class)->enable(OptionalFeature::VARIANTS);
});

it('lays the variants out on size down and colour across', function () {
    $group = gridGroup(['المقاس' => ['S', 'M'], 'اللون' => ['أسود', 'أبيض']]);

    $grid = app(ProductGroupGridService::class)->grid($group);

    expect($grid['row']->name)->toBe('المقاس')
        ->and($grid['col']->name)->toBe('اللون')
        ->and($grid['rows']->pluck('value')->all())->toBe(['S', 'M'])
        ->and($grid['cols']->pluck('value')->all())->toBe(['أسود', 'أبيض']);

    $m = $grid['rows']->firstWhere('value', 'M')->id;
    $white = $grid['cols']->firstWhere('value', 'أبيض')->id;
    expect($grid['cells'][$m][$white]->id)->toBe(gridSku($group, 'M / أبيض'));
});

it('needs a filter for every axis that is not on the grid', function () {
    $group = gridGroup(['المقاس' => ['S', 'M'], 'اللون' => ['أسود'], 'القصة' => ['ضيق', 'واسع']]);
    $service = app(ProductGroupGridService::class);
    $grid = $service->grid($group);
    $s = $grid['rows']->firstWhere('value', 'S')->id;
    $black = $grid['cols']->first()->id;

    expect($grid['cells'][$s][$black])->toBeNull();

    $fit = $service->otherAxes($group, $grid['row'], $grid['col'])->sole();
    $slim = $fit->values->firstWhere('value', 'ضيق')->id;
    $filtered = $service->grid($group, fixed: [$fit->id => $slim]);

    expect($filtered['cells'][$s][$black]->id)->toBe(gridSku($group, 'S / أسود / ضيق'));
});

it('shows the stock matrix with row and column totals', function () {
    $group = gridGroup(['المقاس' => ['S', 'M'], 'اللون' => ['أسود', 'أبيض']]);
    setStock(gridSku($group, 'S / أسود'), 3);
    setStock(gridSku($group, 'S / أبيض'), 2);
    setStock(gridSku($group, 'M / أسود'), 5);
    actingAs(User::factory()->admin()->create());

    $page = livewire(ProductGroupStock::class, ['record' => $group->getRouteKey()])->assertSuccessful();
    $matrix = $page->instance()->matrix();

    $s = $matrix['rows']->firstWhere('value', 'S')->id;
    $black = $matrix['cols']->firstWhere('value', 'أسود')->id;
    expect($matrix['rowTotals'][$s])->toBe(5.0)
        ->and($matrix['colTotals'][$black])->toBe(8.0)
        ->and($matrix['total'])->toBe(10.0);

    $page->set('rowOptionId', $matrix['col']->id)->set('colOptionId', $matrix['row']->id);
    expect($page->instance()->matrix()['row']->name)->toBe('اللون');
});
