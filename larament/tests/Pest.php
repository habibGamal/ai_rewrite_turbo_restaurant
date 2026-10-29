<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

function openInventoryDay(?\App\Models\Product $product = null, ?\Carbon\Carbon $date = null): \App\Models\InventoryItemMovementDaily
{
    $date = $date ?? \Carbon\Carbon::today();
    $product = $product ?? \App\Models\Product::first() ?? \App\Models\Product::factory()->create();

    $daily = \App\Models\InventoryItemMovementDaily::firstOrNew([
        'product_id' => $product->id,
        'date' => $date->toDateString(),
    ]);

    $daily->fill([
        'start_quantity' => $daily->start_quantity ?? 0,
        'incoming_quantity' => $daily->incoming_quantity ?? 0,
        'return_sales_quantity' => $daily->return_sales_quantity ?? 0,
        'sales_quantity' => $daily->sales_quantity ?? 0,
        'return_waste_quantity' => $daily->return_waste_quantity ?? 0,
        'end_quantity' => $daily->end_quantity ?? 0,
        'closed_at' => null,
    ]);
    $daily->save();

    return $daily;
}

function pairPlatform(string $token = 'test-token'): \App\Models\PlatformConnection
{
    \App\Models\PlatformConnection::flushCache();

    $connection = \App\Models\PlatformConnection::create([
        'url' => 'https://platform.test',
        'branch_uuid' => '01930c11-0000-7000-8000-000000000001',
        'token' => $token,
        'branch_secret' => 'test-secret',
        'timezone' => 'Africa/Cairo',
        'status' => 'active',
        'paired_at' => now(),
    ]);

    \App\Models\PlatformConnection::flushCache();

    return $connection;
}
