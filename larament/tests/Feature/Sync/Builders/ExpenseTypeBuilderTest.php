<?php

use App\Enums\ExpenseCategory;
use App\Models\ExpenceType;
use App\Sync\Builders\ExpenseTypeBuilder;
use Illuminate\Support\Str;

it('builds an expense type snapshot', function () {
    pairPlatform();

    $expenseType = ExpenceType::factory()->create([
        'name' => 'Monthly Rent',
        'category' => ExpenseCategory::Rent,
        'avg_month_rate' => 15000.00,
    ]);

    $data = app(ExpenseTypeBuilder::class)->build($expenseType->uuid);

    expect($data)->toEqual([
        'name' => 'Monthly Rent',
        'category' => 'rent',
        'avg_month_rate' => '15000.00',
    ]);
});

it('returns null for an unknown expense type uuid', function () {
    expect(app(ExpenseTypeBuilder::class)->build((string) Str::uuid()))->toBeNull();
});
