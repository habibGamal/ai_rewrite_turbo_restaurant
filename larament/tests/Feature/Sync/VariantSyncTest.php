<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductGroup;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\SyncOutbox;
use App\Services\Products\ProductVariantService;
use App\Sync\BackfillService;
use App\Sync\BatchSender;
use App\Sync\DocumentType;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * @param  array<string, array<int, string>>  $axes  option name => values
 */
function syncVariantGroup(array $axes = ['Size' => ['S', 'M'], 'Color' => ['Black']]): ProductGroup
{
    $group = ProductGroup::create([
        'name' => 'Tee',
        'category_id' => Category::factory()->create()->id,
        'sku_prefix' => 'TEE',
        'base_price' => 150,
        'base_cost' => 80,
    ]);

    $position = 0;
    foreach ($axes as $name => $values) {
        $option = ProductOption::create(['product_group_id' => $group->id, 'name' => $name, 'position' => $position++]);
        foreach ($values as $i => $value) {
            ProductOptionValue::create(['product_option_id' => $option->id, 'value' => $value, 'position' => $i]);
        }
    }

    return $group->fresh();
}

function outboxRows(DocumentType $type, string $uuid, string $op = 'upsert'): int
{
    return SyncOutbox::where(['doc_type' => $type->value, 'doc_uuid' => $uuid, 'op' => $op])->count();
}

/**
 * Fake the platform accepting every document it is sent.
 */
function fakePlatformAcceptsAll(): void
{
    Http::fake([
        '*/api/v1/ingest/batches' => function (Request $request) {
            $payload = json_decode((string) gzdecode($request->body()), true);

            return Http::response([
                'batch_uuid' => $payload['batch_uuid'],
                'accepted' => collect($payload['documents'])->pluck('uuid')->unique()->values()->all(),
                'rejected' => [],
            ], 202);
        },
    ]);
}

/** @return array<int, array<string, mixed>> */
function sentDocuments(): array
{
    $documents = [];
    Http::assertSent(function (Request $request) use (&$documents) {
        $payload = json_decode((string) gzdecode($request->body()), true);
        $documents = [...$documents, ...$payload['documents']];

        return true;
    });

    return $documents;
}

it('records nothing for groups and barcodes when not paired', function () {
    $group = syncVariantGroup();
    app(ProductVariantService::class)->generate($group);
    ProductBarcode::create(['product_id' => Product::factory()->create()->id, 'barcode' => 'X-1']);
    $group->update(['brand' => 'Acme']);

    expect(SyncOutbox::count())->toBe(0);
});

it('records the group when the group, an option or a value is saved or deleted', function () {
    pairPlatform();

    $group = syncVariantGroup();
    // Group create + 2 options + 3 values.
    expect(outboxRows(DocumentType::ProductGroup, $group->uuid))->toBe(6);

    SyncOutbox::truncate();
    $group->update(['brand' => 'Acme']);
    expect(outboxRows(DocumentType::ProductGroup, $group->uuid))->toBe(1);

    SyncOutbox::truncate();
    $option = $group->options()->where('name', 'Color')->first();
    $option->update(['name' => 'Colour']);
    expect(outboxRows(DocumentType::ProductGroup, $group->uuid))->toBe(1);

    SyncOutbox::truncate();
    $value = ProductOptionValue::create(['product_option_id' => $option->id, 'value' => 'White']);
    expect(outboxRows(DocumentType::ProductGroup, $group->uuid))->toBe(1);

    SyncOutbox::truncate();
    $value->delete();
    expect(outboxRows(DocumentType::ProductGroup, $group->uuid))->toBe(1);

    SyncOutbox::truncate();
    $option->delete();
    expect(outboxRows(DocumentType::ProductGroup, $group->uuid))->toBe(1);
});

it('records a group delete tombstone and re-sends its variants without the group', function () {
    pairPlatform();

    // A SKU that uses option values blocks the group delete (restrictOnDelete on
    // product_variant_values), so these products sit in the group without values.
    $group = syncVariantGroup();
    $skus = Product::factory()->count(2)->create(['product_group_id' => $group->id]);
    SyncOutbox::truncate();

    $group->delete();

    expect(outboxRows(DocumentType::ProductGroup, $group->uuid, 'delete'))->toBe(1);
    foreach ($skus as $sku) {
        expect(outboxRows(DocumentType::Product, $sku->uuid))->toBe(1);
    }

    // Variants are recorded before the group tombstone.
    expect(SyncOutbox::orderBy('id')->first()->doc_type)->toBe(DocumentType::Product->value);

    fakePlatformAcceptsAll();
    app(BatchSender::class)->run();

    $productDocs = collect(sentDocuments())->where('type', 'product');
    expect($productDocs)->toHaveCount($skus->count())
        ->and($productDocs->pluck('data.product_group_uuid')->unique()->all())->toBe([null]);
});

it('records the product when one of its barcodes is saved or deleted', function () {
    $product = Product::factory()->create();
    pairPlatform();

    $barcode = ProductBarcode::create(['product_id' => $product->id, 'barcode' => 'CASE-12', 'pack_quantity' => 12]);
    expect(outboxRows(DocumentType::Product, $product->uuid))->toBe(1);

    SyncOutbox::truncate();
    $barcode->update(['pack_quantity' => 6]);
    expect(outboxRows(DocumentType::Product, $product->uuid))->toBe(1);

    SyncOutbox::truncate();
    $barcode->delete();
    expect(outboxRows(DocumentType::Product, $product->uuid))->toBe(1);
});

it('sends generated SKUs with their option values, after their group', function () {
    pairPlatform();

    $group = syncVariantGroup();
    $skus = app(ProductVariantService::class)->generate($group);

    fakePlatformAcceptsAll();
    app(BatchSender::class)->run();

    $documents = collect(sentDocuments());
    $types = $documents->pluck('type')->values();
    $groupIndex = $types->search('product_group');
    $firstProductIndex = $types->search('product');

    expect($groupIndex)->toBeLessThan($firstProductIndex)
        ->and($documents->where('type', 'product_group'))->toHaveCount(1)
        ->and($documents->where('type', 'product'))->toHaveCount($skus->count());

    $small = $documents->firstWhere('data.variant_label', 'S / Black');
    $sizeS = ProductOptionValue::where('value', 'S')->first();
    $black = ProductOptionValue::where('value', 'Black')->first();

    expect($small['data']['product_group_uuid'])->toBe($group->uuid)
        ->and($small['data']['option_value_uuids'])->toBe([$sizeS->uuid, $black->uuid]);
});

it('moves a group upsert ahead of the first product in the same batch', function () {
    $documents = [
        ['type' => 'category', 'uuid' => 'c1', 'op' => 'upsert'],
        ['type' => 'product', 'uuid' => 'p1', 'op' => 'upsert'],
        ['type' => 'shift', 'uuid' => 's1', 'op' => 'upsert'],
        ['type' => 'product_group', 'uuid' => 'g1', 'op' => 'upsert'],
        ['type' => 'product', 'uuid' => 'p2', 'op' => 'upsert'],
        ['type' => 'product_group', 'uuid' => 'g2', 'op' => 'delete'],
    ];

    expect(collect(BatchSender::orderDependencies($documents))->pluck('uuid')->all())
        ->toBe(['c1', 'g1', 'p1', 's1', 'p2', 'g2']);

    $alreadyOrdered = [
        ['type' => 'product_group', 'uuid' => 'g1', 'op' => 'upsert'],
        ['type' => 'product', 'uuid' => 'p1', 'op' => 'upsert'],
    ];
    expect(BatchSender::orderDependencies($alreadyOrdered))->toBe($alreadyOrdered);
});

it('sends a group re-recorded after its SKUs ahead of them', function () {
    pairPlatform();

    $group = syncVariantGroup();
    $skus = app(ProductVariantService::class)->generate($group);
    // Only the SKU rows first, then a later group change.
    SyncOutbox::where('doc_type', 'product_group')->delete();
    $group->update(['brand' => 'Acme']);

    $rowTypes = SyncOutbox::whereIn('doc_type', ['product', 'product_group'])->orderBy('id')->pluck('doc_type');
    expect($rowTypes->first())->toBe('product')
        ->and($rowTypes->last())->toBe('product_group');

    fakePlatformAcceptsAll();
    app(BatchSender::class)->run();

    $types = collect(sentDocuments())->pluck('type')
        ->filter(fn ($t) => in_array($t, ['product', 'product_group'], true))->values();
    expect($types->first())->toBe('product_group')
        ->and($types->filter(fn ($t) => $t === 'product'))->toHaveCount($skus->count());
});

it('backfills product groups before products', function () {
    $group = syncVariantGroup();
    $skus = app(ProductVariantService::class)->generate($group);

    pairPlatform();
    SyncOutbox::truncate();

    app(BackfillService::class)->queueCatalog();

    $groupRow = SyncOutbox::where(['doc_type' => 'product_group', 'doc_uuid' => $group->uuid])->first();
    expect($groupRow)->not->toBeNull()
        ->and($groupRow->priority)->toBe(1)
        ->and(SyncOutbox::where('doc_type', 'product')->min('id'))->toBeGreaterThan($groupRow->id)
        ->and(SyncOutbox::where('doc_type', 'product')->whereIn('doc_uuid', $skus->pluck('uuid'))->count())->toBe($skus->count());

    // Re-running does not duplicate pending group rows.
    app(BackfillService::class)->queueCatalog();
    expect(SyncOutbox::where(['doc_type' => 'product_group', 'doc_uuid' => $group->uuid])->count())->toBe(1);
});
