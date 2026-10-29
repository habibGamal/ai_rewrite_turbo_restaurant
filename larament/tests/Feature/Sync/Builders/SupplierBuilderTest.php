<?php

use App\Models\Supplier;
use App\Sync\Builders\SupplierBuilder;
use Illuminate\Support\Str;

it('builds a supplier snapshot', function () {
    pairPlatform();

    $supplier = Supplier::factory()->create([
        'name' => 'Meat Farm',
        'phone' => '01099998888',
    ]);

    $data = app(SupplierBuilder::class)->build($supplier->uuid);

    expect($data)->toEqual([
        'name' => 'Meat Farm',
        'phone' => '01099998888',
    ]);
});

it('returns null for an unknown supplier uuid', function () {
    expect(app(SupplierBuilder::class)->build((string) Str::uuid()))->toBeNull();
});
