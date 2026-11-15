<?php

use App\Enums\OptionalFeature;
use App\Enums\ProductType;
use App\Filament\Resources\ConsumableProducts\Pages\EditConsumableProduct;
use App\Filament\Resources\ConsumableProducts\Pages\ListConsumableProducts;
use App\Filament\Resources\ProductGroups\Pages\CreateProductGroup;
use App\Filament\Resources\ProductGroups\Pages\EditProductGroup;
use App\Filament\Resources\ProductGroups\Pages\ListProductGroups;
use App\Filament\Resources\ProductGroups\ProductGroupResource;
use App\Filament\Resources\ProductGroups\RelationManagers\VariantsRelationManager;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductGroup;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\User;
use App\Services\FeatureService;
use App\Services\Products\ProductVariantService;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

/**
 * @param  array<string, array<int, string>>  $axes
 */
function adminVariantGroup(array $axes = ['المقاس' => ['S', 'M'], 'اللون' => ['أسود', 'أبيض']], array $attributes = []): ProductGroup
{
    $group = ProductGroup::create(array_merge([
        'name' => 'تيشيرت',
        'category_id' => Category::factory()->create()->id,
        'sku_prefix' => 'TSH',
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

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    actingAs($this->admin);
    app(FeatureService::class)->enable(OptionalFeature::VARIANTS);
});

it('hides the groups resource and forbids its pages even for admin when the flag is off', function () {
    $group = adminVariantGroup();
    app(ProductVariantService::class)->generate($group);
    app(FeatureService::class)->disable(OptionalFeature::VARIANTS);

    expect(ProductGroupResource::canAccess())->toBeFalse()
        ->and(ProductGroupResource::shouldRegisterNavigation())->toBeFalse()
        ->and(ProductGroupResource::canCreate())->toBeFalse()
        ->and(ProductGroupResource::canGloballySearch())->toBeFalse()
        ->and(ProductGroupResource::getGlobalSearchResults('تيشيرت'))->toBeEmpty();

    livewire(ListProductGroups::class)->assertForbidden();
    livewire(CreateProductGroup::class)->assertForbidden();
    livewire(EditProductGroup::class, ['record' => $group->id])->assertForbidden();
    livewire(VariantsRelationManager::class, [
        'ownerRecord' => $group,
        'pageClass' => EditProductGroup::class,
    ])->assertForbidden();

    $this->get(ProductGroupResource::getUrl('index'))->assertNotFound(); // hidden screens answer 404 (Phase 5)
});

it('shows the groups resource when the flag is on', function () {
    $group = adminVariantGroup();

    expect(ProductGroupResource::canAccess())->toBeTrue()
        ->and(ProductGroupResource::shouldRegisterNavigation())->toBeTrue();

    livewire(ListProductGroups::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$group]);
    livewire(EditProductGroup::class, ['record' => $group->id])->assertSuccessful();
});

it('creates a group with any number of option axes and ordered values', function () {
    $undoRepeaterFake = Repeater::fake();
    $category = Category::factory()->create();

    livewire(CreateProductGroup::class)
        ->fillForm([
            'name' => 'بنطلون',
            'category_id' => $category->id,
            'brand' => 'ماركة',
            'sku_prefix' => 'PNT',
            'base_price' => 300,
            'base_cost' => 150,
            'unit' => 'piece',
            'is_active' => true,
            'options' => [
                ['name' => 'المقاس', 'values' => [['value' => '30'], ['value' => '32']]],
                ['name' => 'اللون', 'values' => [['value' => 'أزرق', 'code' => 'BLU', 'color_hex' => '#0000ff']]],
                ['name' => 'القصة', 'values' => [['value' => 'سليم'], ['value' => 'واسع']]],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $undoRepeaterFake();

    $group = ProductGroup::where('name', 'بنطلون')->firstOrFail();
    $options = $group->options()->with('values')->get();

    expect($options->pluck('name')->all())->toBe(['المقاس', 'اللون', 'القصة'])
        ->and($options->pluck('position')->all())->toBe([1, 2, 3])
        ->and($options[0]->values->pluck('value')->all())->toBe(['30', '32'])
        ->and($options[1]->values->first()->code)->toBe('BLU')
        ->and($options[1]->values->first()->color_hex)->toBe('#0000ff');
});

it('rejects weighed units for a group', function () {
    livewire(CreateProductGroup::class)
        ->fillForm([
            'name' => 'جبنة',
            'category_id' => Category::factory()->create()->id,
            'base_price' => 10,
            'base_cost' => 5,
            'unit' => 'kg',
        ])
        ->call('create')
        ->assertHasFormErrors(['unit']);
});

it('previews and generates the missing variants from the edit page', function () {
    $group = adminVariantGroup();

    livewire(EditProductGroup::class, ['record' => $group->id])
        ->mountAction('generateVariants')
        ->assertMountedActionModalSee(['4', 'S / أسود', 'M / أبيض'])
        ->callMountedAction()
        ->assertNotified('تم إنشاء 4 متغير');

    expect($group->variants()->count())->toBe(4)
        ->and($group->variants()->pluck('sku')->sort()->values()->all())
        ->toBe(['TSH-M-أبيض', 'TSH-M-أسود', 'TSH-S-أبيض', 'TSH-S-أسود']);

    // Running again creates nothing new.
    livewire(EditProductGroup::class, ['record' => $group->id])
        ->callAction('generateVariants')
        ->assertNotified('كل المتغيرات موجودة بالفعل');

    expect($group->variants()->count())->toBe(4);
});

it('shows a validation error instead of failing when a used option value is removed', function () {
    $group = adminVariantGroup();
    app(ProductVariantService::class)->generate($group);

    $size = $group->options()->where('name', 'المقاس')->firstOrFail();
    $small = $size->values()->where('value', 'S')->firstOrFail();

    $component = livewire(EditProductGroup::class, ['record' => $group->id]);
    $values = $component->get("data.options.record-{$size->id}.values");
    unset($values["record-{$small->id}"]);

    $component
        ->set("data.options.record-{$size->id}.values", $values)
        ->call('save')
        ->assertHasErrors(['data.options'])
        ->assertNotified('تعذر حفظ الخيارات');

    expect(ProductOptionValue::find($small->id))->not->toBeNull()
        ->and($group->variants()->count())->toBe(4);
});

it('lets an unused option value be removed', function () {
    $group = adminVariantGroup(['المقاس' => ['S', 'M']]);
    app(ProductVariantService::class)->generate($group);

    // L is added after generating, so no SKU uses it yet.
    $size = $group->options()->firstOrFail();
    $large = ProductOptionValue::create(['product_option_id' => $size->id, 'value' => 'L', 'position' => 2]);

    $component = livewire(EditProductGroup::class, ['record' => $group->id]);
    $values = $component->get("data.options.record-{$size->id}.values");
    unset($values["record-{$large->id}"]);

    $component
        ->set("data.options.record-{$size->id}.values", $values)
        ->call('save')
        ->assertHasNoErrors();

    expect(ProductOptionValue::find($large->id))->toBeNull();
});

it('edits a variant: sku, barcode and a price override that can be cleared again', function () {
    $group = adminVariantGroup();
    app(ProductVariantService::class)->generate($group);
    $variant = $group->variants()->orderBy('id')->firstOrFail();

    livewire(VariantsRelationManager::class, ['ownerRecord' => $group, 'pageClass' => EditProductGroup::class])
        ->assertCanSeeTableRecords($group->variants)
        ->callAction(TestAction::make('edit')->table($variant), [
            'sku' => 'CUSTOM-1',
            'barcode' => '1234567890123',
            'price_override' => 175,
            'min_stock' => 3,
            'is_active' => true,
        ])
        ->assertHasNoFormErrors();

    $variant->refresh();
    expect($variant->sku)->toBe('CUSTOM-1')
        ->and($variant->barcode)->toBe('1234567890123')
        ->and((float) $variant->price)->toBe(175.0)
        ->and($variant->has_custom_price)->toBeTrue()
        ->and((float) $variant->min_stock)->toBe(3.0)
        ->and(ProductBarcode::where('barcode', '1234567890123')->value('product_id'))->toBe($variant->id);

    // The group price no longer reaches the overridden SKU.
    $group->update(['base_price' => 200]);
    expect((float) $variant->fresh()->price)->toBe(175.0);

    livewire(VariantsRelationManager::class, ['ownerRecord' => $group, 'pageClass' => EditProductGroup::class])
        ->callAction(TestAction::make('edit')->table($variant), [
            'price_override' => null,
        ])
        ->assertHasNoFormErrors();

    $variant->refresh();
    expect((float) $variant->price)->toBe(200.0)
        ->and($variant->has_custom_price)->toBeFalse();
});

it('rejects a barcode that another product already uses', function () {
    $other = Product::factory()->create(['type' => ProductType::Consumable, 'barcode' => '5555555555555']);
    $group = adminVariantGroup();
    app(ProductVariantService::class)->generate($group);
    $variant = $group->variants()->firstOrFail();

    livewire(VariantsRelationManager::class, ['ownerRecord' => $group, 'pageClass' => EditProductGroup::class])
        ->callAction(TestAction::make('edit')->table($variant), [
            'barcode' => '5555555555555',
        ])
        ->assertHasFormErrors(['barcode']);

    expect($variant->fresh()->barcode)->toBeNull()
        ->and($other->fresh()->barcode)->toBe('5555555555555');
});

it('sets and clears prices and toggles activity in bulk', function () {
    $group = adminVariantGroup();
    app(ProductVariantService::class)->generate($group);
    $selected = $group->variants()->orderBy('id')->take(2)->get();

    $rm = fn () => livewire(VariantsRelationManager::class, ['ownerRecord' => $group, 'pageClass' => EditProductGroup::class]);

    $rm()->selectTableRecords($selected->pluck('id')->all())
        ->callAction(TestAction::make('setPrice')->table()->bulk(), ['price' => 99]);

    expect($group->variants()->where('has_custom_price', true)->pluck('price')->map(fn ($p) => (float) $p)->all())->toBe([99.0, 99.0]);

    $rm()->selectTableRecords($selected->pluck('id')->all())
        ->callAction(TestAction::make('deactivate')->table()->bulk());
    expect($group->variants()->where('is_active', false)->count())->toBe(2);

    $rm()->selectTableRecords($selected->pluck('id')->all())
        ->callAction(TestAction::make('activate')->table()->bulk());
    expect($group->variants()->where('is_active', false)->count())->toBe(0);

    $rm()->selectTableRecords($selected->pluck('id')->all())
        ->callAction(TestAction::make('clearPrice')->table()->bulk());
    expect($group->variants()->where('has_custom_price', true)->count())->toBe(0)
        ->and($group->variants()->pluck('price')->map(fn ($p) => (float) $p)->unique()->all())->toBe([150.0]);
});

it('generates barcodes for the variants that have none', function () {
    $group = adminVariantGroup();
    app(ProductVariantService::class)->generate($group);
    $group->variants()->orderBy('id')->first()->update(['barcode' => '1111111111111']);

    livewire(VariantsRelationManager::class, ['ownerRecord' => $group, 'pageClass' => EditProductGroup::class])
        ->callAction(TestAction::make('generateBarcodes')->table())
        ->assertNotified('تم توليد 3 باركود');

    $barcodes = $group->variants()->pluck('barcode');
    expect($barcodes->filter()->count())->toBe(4)
        ->and($barcodes->unique()->count())->toBe(4)
        ->and($barcodes->contains('1111111111111'))->toBeTrue();
});

it('hides variant rows from the consumable products list by default and shows them with the filter', function () {
    $standalone = Product::factory()->create(['type' => ProductType::Consumable]);
    $group = adminVariantGroup();
    $variants = app(ProductVariantService::class)->generate($group);

    livewire(ListConsumableProducts::class)
        ->assertCanSeeTableRecords([$standalone])
        ->assertCanNotSeeTableRecords($variants)
        ->filterTable('variants', true)
        ->assertCanSeeTableRecords($variants)
        ->assertCanNotSeeTableRecords([$standalone])
        ->filterTable('variants', null)
        ->assertCanSeeTableRecords([$standalone, ...$variants]);
});

it('links a variant to its group and refuses a weighed unit on the consumable edit page', function () {
    $group = adminVariantGroup();
    $variant = app(ProductVariantService::class)->generate($group)->first();

    livewire(EditConsumableProduct::class, ['record' => $variant->id])
        ->assertSuccessful()
        ->assertSee($group->name)
        ->assertSee(ProductGroupResource::getUrl('edit', ['record' => $group->id]), escape: false)
        ->fillForm(['unit' => 'kg'])
        ->call('save')
        ->assertHasFormErrors(['unit']);

    expect($variant->fresh()->unit)->toBe('piece');

    // A standalone consumable may still use weighed units.
    $standalone = Product::factory()->create(['type' => ProductType::Consumable, 'unit' => 'piece']);
    livewire(EditConsumableProduct::class, ['record' => $standalone->id])
        ->fillForm(['unit' => 'kg'])
        ->call('save')
        ->assertHasNoFormErrors(['unit']);
});

it('lists variants left incomplete after an axis is added in the generate preview', function () {
    $group = adminVariantGroup(['المقاس' => ['S', 'M']]);
    app(ProductVariantService::class)->generate($group);

    $fit = ProductOption::create(['product_group_id' => $group->id, 'name' => 'القصة', 'position' => 5]);
    ProductOptionValue::create(['product_option_id' => $fit->id, 'value' => 'سليم', 'position' => 0]);

    livewire(EditProductGroup::class, ['record' => $group->id])
        ->mountAction('generateVariants')
        ->assertMountedActionModalSee(['S / سليم', 'لا تطابق الخيارات الحالية']);
});

it('shows variant rows in the consumable list without the filter when the flag is off', function () {
    $group = adminVariantGroup();
    $variants = app(ProductVariantService::class)->generate($group);
    app(FeatureService::class)->disable(OptionalFeature::VARIANTS);

    livewire(ListConsumableProducts::class)
        ->assertTableFilterHidden('variants')
        ->assertCanSeeTableRecords($variants);
});
