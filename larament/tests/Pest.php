<?php

use App\Enums\OptionalFeature;
use App\Models\InventoryItemMovementDaily;
use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\FeatureService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

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

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
| Deferred payment and sales returns are vendor features that start off on a
| fresh install. These suites exercise the features themselves (or flows that
| pass through them), so they run with both switched on. The off state is
| covered by tests/Feature/Features/DeferredPaymentAndReturnsGatingTest.php.
*/
pest()->beforeEach(function (): void {
    app(FeatureService::class)->updateFeatures([
        OptionalFeature::DEFERRED_PAYMENT->value => true,
        OptionalFeature::RETURNS->value => true,
    ]);
})->in(
    'Feature/Orders/Returns',
    'Feature/Returns',
    'Feature/Orders/OrderTypeCreditSettingsTest.php',
    'Feature/Orders/CompaniesPartialPaymentTest.php',
    'Feature/Reports/PayLaterShiftReconciliationTest.php',
    'Feature/SafetyNet/ExternalSellerAndReturnTest.php',
    'Feature/SafetyNet/OrderLifecycleTest.php',
    'Feature/Orders/OrderStockRoutingTest.php',
    'Feature/Filament/Pages/CompaniesAccountsTest.php',
    'Feature/Modules/ExternalSellers/ExternalOrderTest.php',
    'Feature/Orders/Instant/InstantSaleTest.php',
    'Feature/Orders/OrderControllerFeatureTest.php',
    'Feature/Filament/Pages/ChannelPerformanceReportTest.php',
    'Feature/Filament/Pages/Reports/CurrentShiftReportTest.php',
    'Feature/Filament/Pages/Reports/PeriodShiftReportTest.php',
    'Feature/Filament/Resources/OrderResourceTest.php',
    'Feature/Filament/Resources/OrderTypeRowResourceTest.php',
    'Feature/Orders/CashierTabLayoutTest.php',
    'Feature/Orders/CashierTabsTest.php',
    'Feature/Orders/ClosedDayOrderCancellationAndReturnTest.php',
    'Feature/Orders/OrderDomainEventsTest.php',
    'Unit/Actions/Orders/CompleteOrderActionTest.php',
);

/*
| Editing a closed shift's expenses and revenues is a vendor feature that
| starts off. The shift money suite exercises it, so it runs with it on; the
| off state is covered by tests/Feature/Features/ClosedShiftEditsGatingTest.php.
*/
pest()->beforeEach(function (): void {
    app(FeatureService::class)->updateFeatures([
        OptionalFeature::CLOSED_SHIFT_EDITS->value => true,
    ]);
})->in(
    'Feature/Shifts/ShiftMoneyTest.php',
);

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

function openInventoryDay(?Product $product = null, ?Carbon $date = null): InventoryItemMovementDaily
{
    $date = $date ?? Carbon::today();
    $product = $product ?? Product::first() ?? Product::factory()->create();

    $daily = InventoryItemMovementDaily::firstOrNew([
        'product_id' => $product->id,
        'warehouse_id' => Warehouse::default()->id,
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

function pairPlatform(string $token = 'test-token'): PlatformConnection
{
    PlatformConnection::flushCache();

    $connection = PlatformConnection::create([
        'url' => 'https://platform.test',
        'branch_uuid' => '01930c11-0000-7000-8000-000000000001',
        'token' => $token,
        'branch_secret' => 'test-secret',
        'timezone' => 'Africa/Cairo',
        'status' => 'active',
        'paired_at' => now(),
    ]);

    PlatformConnection::flushCache();

    return $connection;
}
