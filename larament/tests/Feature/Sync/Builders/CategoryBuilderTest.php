<?php

use App\Models\Category;
use App\Sync\Builders\CategoryBuilder;
use Illuminate\Support\Str;

it('builds a category snapshot', function () {
    pairPlatform();

    $category = Category::factory()->create([
        'name' => 'Hot Drinks',
    ]);

    $data = app(CategoryBuilder::class)->build($category->uuid);

    expect($data)->toEqual([
        'name' => 'Hot Drinks',
    ]);
});

it('returns null for an unknown category uuid', function () {
    expect(app(CategoryBuilder::class)->build((string) Str::uuid()))->toBeNull();
});
