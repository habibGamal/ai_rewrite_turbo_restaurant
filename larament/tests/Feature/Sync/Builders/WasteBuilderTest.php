<?php

use App\Models\Product;
use App\Models\Waste;
use App\Models\WastedItem;
use App\Sync\Builders\WasteBuilder;
use Carbon\Carbon;
use Illuminate\Support\Str;

it('builds a waste document snapshot', function () {
    pairPlatform();

    $product = Product::factory()->create();

    $waste = Waste::factory()->create([
        'business_date' => '2026-10-29',
        'closed_at' => Carbon::parse('2026-10-29 14:00:00', 'Africa/Cairo'),
        'total' => 60.00,
    ]);

    $item = WastedItem::factory()->create([
        'waste_id' => $waste->id,
        'product_id' => $product->id,
        'quantity' => 2.0,
        'price' => 30.00,
        'total' => 60.00,
    ]);

    $data = app(WasteBuilder::class)->build($waste->uuid);

    expect($data)->toEqual([
        'business_date' => '2026-10-29',
        'closed_at' => '2026-10-29T14:00:00+03:00',
        'total' => '60.00',
        'items' => [
            [
                'product_uuid' => $product->uuid,
                'quantity' => '2.000',
                'price' => '30.00',
                'total' => '60.00',
            ],
        ],
    ]);
});

it('returns null for an unknown waste uuid', function () {
    expect(app(WasteBuilder::class)->build((string) Str::uuid()))->toBeNull();
});
