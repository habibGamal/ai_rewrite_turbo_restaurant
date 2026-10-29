<?php

use App\Models\Product;
use App\Models\Stocktaking;
use App\Models\StocktakingItem;
use App\Sync\Builders\StocktakingBuilder;
use Carbon\Carbon;
use Illuminate\Support\Str;

it('builds a stocktaking snapshot', function () {
    pairPlatform();

    $product = Product::factory()->create();

    $stocktaking = Stocktaking::factory()->create([
        'business_date' => '2026-10-29',
        'closed_at' => Carbon::parse('2026-10-29 15:00:00', 'Africa/Cairo'),
        'total' => 200.00,
    ]);

    $item = StocktakingItem::factory()->create([
        'stocktaking_id' => $stocktaking->id,
        'product_id' => $product->id,
        'stock_quantity' => 10.0,
        'real_quantity' => 8.0,
        'price' => 20.00,
        'total' => 160.00,
    ]);

    $data = app(StocktakingBuilder::class)->build($stocktaking->uuid);

    expect($data)->toEqual([
        'business_date' => '2026-10-29',
        'closed_at' => '2026-10-29T15:00:00+03:00',
        'total' => '200.00',
        'items' => [
            [
                'product_uuid' => $product->uuid,
                'stock_quantity' => '10.000',
                'real_quantity' => '8.000',
                'price' => '20.00',
                'total' => '160.00',
            ],
        ],
    ]);
});

it('returns null for an unknown stocktaking uuid', function () {
    expect(app(StocktakingBuilder::class)->build((string) Str::uuid()))->toBeNull();
});
