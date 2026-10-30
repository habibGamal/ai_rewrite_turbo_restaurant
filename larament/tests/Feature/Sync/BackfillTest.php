<?php

use App\Models\Category;
use App\Models\ExpenceType;
use App\Models\Expense;
use App\Models\InventoryItemMovementDaily;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductComponent;
use App\Models\PurchaseInvoice;
use App\Models\ReturnPurchaseInvoice;
use App\Models\Shift;
use App\Models\Stocktaking;
use App\Models\Supplier;
use App\Models\SyncOutbox;
use App\Models\User;
use App\Models\Waste;
use App\Sync\BackfillService;
use App\Sync\DateDocument;
use App\Sync\DocumentType;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    PlatformConnection::flushCache();
    Http::preventStrayRequests();
});

it('does not duplicate pending rows on repeated backfill', function () {
    $product = Product::factory()->create();
    $category = Category::factory()->create();

    pairPlatform();
    SyncOutbox::truncate();

    $service = app(BackfillService::class);
    $count1 = $service->queueCatalog();

    expect($count1)->toBeGreaterThanOrEqual(2);
    $totalPending1 = SyncOutbox::whereNull('sent_at')->whereNull('failed_at')->count();

    $count2 = $service->queueCatalog();
    expect($count2)->toBe(0);

    $totalPending2 = SyncOutbox::whereNull('sent_at')->whereNull('failed_at')->count();
    expect($totalPending2)->toBe($totalPending1);
});

it('backfills only final orders and closed documents', function () {
    pairPlatform();

    $date = '2026-10-20';
    $shift = Shift::factory()->create(['business_date' => $date]);

    // Final orders
    $completedOrder = Order::factory()->create([
        'shift_id' => $shift->id,
        'status' => 'completed',
    ]);
    $cancelledOrder = Order::factory()->create([
        'shift_id' => $shift->id,
        'status' => 'cancelled',
    ]);

    // Non-final orders
    $processingOrder = Order::factory()->create([
        'shift_id' => $shift->id,
        'status' => 'processing',
    ]);
    $pendingOrder = Order::factory()->create([
        'shift_id' => $shift->id,
        'status' => 'pending',
    ]);

    // Closed vs unclosed purchase invoice
    $supplier = Supplier::factory()->create();
    $closedPurchase = PurchaseInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'business_date' => $date,
        'closed_at' => now(),
    ]);
    $unclosedPurchase = PurchaseInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'business_date' => $date,
        'closed_at' => null,
    ]);

    // Closed vs unclosed purchase return
    $closedPurchaseReturn = ReturnPurchaseInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'business_date' => $date,
        'closed_at' => now(),
    ]);
    $unclosedPurchaseReturn = ReturnPurchaseInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'business_date' => $date,
        'closed_at' => null,
    ]);

    // Closed vs unclosed waste
    $closedWaste = Waste::factory()->create([
        'business_date' => $date,
        'closed_at' => now(),
    ]);
    $unclosedWaste = Waste::factory()->create([
        'business_date' => $date,
        'closed_at' => null,
    ]);

    // Closed vs unclosed stocktaking
    $closedStocktaking = Stocktaking::factory()->create([
        'business_date' => $date,
        'closed_at' => now(),
    ]);
    $unclosedStocktaking = Stocktaking::factory()->create([
        'business_date' => $date,
        'closed_at' => null,
    ]);

    // Expense
    $expenseType = ExpenceType::factory()->create();
    $expense = Expense::factory()->create([
        'shift_id' => $shift->id,
        'expence_type_id' => $expenseType->id,
    ]);

    // Order Return
    $user = User::factory()->create();
    $orderReturn = OrderReturn::create([
        'order_id' => $completedOrder->id,
        'user_id' => $user->id,
        'shift_id' => $shift->id,
        'total_refund' => 20,
        'reason' => 'Defective',
    ]);

    $service = app(BackfillService::class);
    $service->queueRange(Carbon::parse('2026-10-15'), Carbon::parse('2026-10-25'));

    // Assert final orders are queued
    expect(SyncOutbox::where('doc_type', 'order')->where('doc_uuid', $completedOrder->uuid)->exists())->toBeTrue()
        ->and(SyncOutbox::where('doc_type', 'order')->where('doc_uuid', $cancelledOrder->uuid)->exists())->toBeTrue();

    // Assert non-final orders are NOT queued
    expect(SyncOutbox::where('doc_type', 'order')->where('doc_uuid', $processingOrder->uuid)->exists())->toBeFalse()
        ->and(SyncOutbox::where('doc_type', 'order')->where('doc_uuid', $pendingOrder->uuid)->exists())->toBeFalse();

    // Assert closed documents are queued
    expect(SyncOutbox::where('doc_type', 'purchase_invoice')->where('doc_uuid', $closedPurchase->uuid)->exists())->toBeTrue()
        ->and(SyncOutbox::where('doc_type', 'purchase_return')->where('doc_uuid', $closedPurchaseReturn->uuid)->exists())->toBeTrue()
        ->and(SyncOutbox::where('doc_type', 'waste')->where('doc_uuid', $closedWaste->uuid)->exists())->toBeTrue()
        ->and(SyncOutbox::where('doc_type', 'stocktaking')->where('doc_uuid', $closedStocktaking->uuid)->exists())->toBeTrue();

    // Assert unclosed documents are NOT queued
    expect(SyncOutbox::where('doc_type', 'purchase_invoice')->where('doc_uuid', $unclosedPurchase->uuid)->exists())->toBeFalse()
        ->and(SyncOutbox::where('doc_type', 'purchase_return')->where('doc_uuid', $unclosedPurchaseReturn->uuid)->exists())->toBeFalse()
        ->and(SyncOutbox::where('doc_type', 'waste')->where('doc_uuid', $unclosedWaste->uuid)->exists())->toBeFalse()
        ->and(SyncOutbox::where('doc_type', 'stocktaking')->where('doc_uuid', $unclosedStocktaking->uuid)->exists())->toBeFalse();

    // Assert shift, expense, order_return are queued
    expect(SyncOutbox::where('doc_type', 'shift')->where('doc_uuid', $shift->uuid)->exists())->toBeTrue()
        ->and(SyncOutbox::where('doc_type', 'expense')->where('doc_uuid', $expense->uuid)->exists())->toBeTrue()
        ->and(SyncOutbox::where('doc_type', 'order_return')->where('doc_uuid', $orderReturn->uuid)->exists())->toBeTrue();
});

it('queues catalog documents', function () {
    $product = Product::factory()->create();
    $componentProduct = Product::factory()->create();
    ProductComponent::create([
        'product_id' => $product->id,
        'component_id' => $componentProduct->id,
        'quantity' => 2,
    ]);

    $category = Category::factory()->create();
    $supplier = Supplier::factory()->create();
    $expenseType = ExpenceType::factory()->create();
    $staff = User::factory()->create();

    pairPlatform();
    SyncOutbox::truncate();

    $service = app(BackfillService::class);
    $service->queueCatalog();

    expect(SyncOutbox::where('doc_type', 'product')->where('doc_uuid', $product->uuid)->where('priority', 1)->exists())->toBeTrue()
        ->and(SyncOutbox::where('doc_type', 'recipe')->where('doc_uuid', $product->uuid)->where('priority', 1)->exists())->toBeTrue()
        ->and(SyncOutbox::where('doc_type', 'category')->where('doc_uuid', $category->uuid)->where('priority', 1)->exists())->toBeTrue()
        ->and(SyncOutbox::where('doc_type', 'supplier')->where('doc_uuid', $supplier->uuid)->where('priority', 1)->exists())->toBeTrue()
        ->and(SyncOutbox::where('doc_type', 'expense_type')->where('doc_uuid', $expenseType->uuid)->where('priority', 1)->exists())->toBeTrue()
        ->and(SyncOutbox::where('doc_type', 'staff')->where('doc_uuid', $staff->uuid)->where('priority', 1)->exists())->toBeTrue();
});

it('queues closed inventory days and day summaries with deterministic uuids', function () {
    $connection = pairPlatform();
    $product = Product::factory()->create();

    // Closed day
    $closedDate = '2026-10-18';
    InventoryItemMovementDaily::create([
        'product_id' => $product->id,
        'date' => $closedDate,
        'start_quantity' => 10,
        'end_quantity' => 5,
        'closed_at' => now(),
    ]);

    // Unclosed day
    $unclosedDate = '2026-10-19';
    InventoryItemMovementDaily::create([
        'product_id' => $product->id,
        'date' => $unclosedDate,
        'start_quantity' => 5,
        'end_quantity' => 5,
        'closed_at' => null,
    ]);

    $service = app(BackfillService::class);
    $service->queueRange(Carbon::parse('2026-10-15'), Carbon::parse('2026-10-25'));

    $expectedInvUuid = DateDocument::uuid(DocumentType::InventoryDay, $closedDate, $connection->branch_uuid);
    $expectedSummaryUuid = DateDocument::uuid(DocumentType::DaySummary, $closedDate, $connection->branch_uuid);

    $invRow = SyncOutbox::where('doc_type', 'inventory_day')->where('doc_key', $closedDate)->first();
    expect($invRow)->not->toBeNull()
        ->and($invRow->doc_uuid)->toBe($expectedInvUuid)
        ->and($invRow->priority)->toBe(1);

    $summaryRow = SyncOutbox::where('doc_type', 'day_summary')->where('doc_key', $closedDate)->first();
    expect($summaryRow)->not->toBeNull()
        ->and($summaryRow->doc_uuid)->toBe($expectedSummaryUuid)
        ->and($summaryRow->priority)->toBe(1);

    // Unclosed date should not produce inventory_day or day_summary
    expect(SyncOutbox::where('doc_key', $unclosedDate)->exists())->toBeFalse();
});

it('runs platform:backfill artisan command', function () {
    pairPlatform();
    Product::factory()->create();

    $this->artisan('platform:backfill', ['--catalog' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('catalog documents');
});

it('fails platform:backfill on invalid date format', function (string $optName, string $optVal) {
    pairPlatform();

    $this->artisan('platform:backfill', [$optName => $optVal])
        ->assertFailed()
        ->expectsOutputToContain('Expected format: YYYY-MM-DD');
})->with([
    ['--from', 'invalid-date'],
    ['--from', '2026-02-30'],
    ['--to', 'not-a-date'],
    ['--to', '2026-13-01'],
]);

it('fails platform:backfill when --to is given without --from or when range is inverted', function () {
    pairPlatform();

    // --to without --from
    $this->artisan('platform:backfill', ['--to' => '2026-10-25'])
        ->assertFailed()
        ->expectsOutputToContain('The --from option is required when --to is specified.');

    // inverted range (--from after --to)
    $this->artisan('platform:backfill', ['--from' => '2026-10-30', '--to' => '2026-10-10'])
        ->assertFailed()
        ->expectsOutputToContain('cannot be after --to');
});

it('fails platform:backfill on service error', function () {
    pairPlatform();

    $mock = Mockery::mock(BackfillService::class);
    $mock->shouldReceive('queueCatalog')->andThrow(new RuntimeException('DB connection lost'));
    $this->app->instance(BackfillService::class, $mock);

    $this->artisan('platform:backfill', ['--catalog' => true])
        ->assertFailed()
        ->expectsOutputToContain('Backfill failed: DB connection lost');
});

it('succeeds platform:backfill on no-op cases', function () {
    // Case 1: POS not paired -> 0 queued, exits 0
    PlatformConnection::flushCache();
    $this->artisan('platform:backfill', ['--catalog' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Total documents queued: 0');

    // Case 2: Paired, but empty range -> 0 queued, exits 0
    pairPlatform();
    $this->artisan('platform:backfill', ['--from' => '2099-01-01', '--to' => '2099-01-02'])
        ->assertSuccessful()
        ->expectsOutputToContain('Total documents queued: 0');
});

it('recovers incomplete backfill via platform:backfill --recover', function () {
    Product::factory()->create();

    $today = Carbon::today();
    $connection = PlatformConnection::create([
        'url' => 'https://platform.test',
        'branch_uuid' => '01930c11-0000-7000-8000-000000000099',
        'token' => 'recover-token',
        'branch_secret' => 'recover-secret',
        'timezone' => 'Africa/Cairo',
        'status' => 'active',
        'paired_at' => now(),
        'backfill_status' => 'pending',
        'backfill_catalog_queued' => false,
        'backfill_range_queued' => false,
        'backfill_range_from' => $today->copy()->subDays(90)->toDateString(),
        'backfill_range_to' => $today->toDateString(),
        'backfill_attempts' => 0,
        'backfill_next_attempt_at' => null,
    ]);

    $this->artisan('platform:backfill', ['--recover' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Initial backfill recovered successfully.');

    expect($connection->fresh()->backfill_status)->toBe('completed')
        ->and(SyncOutbox::where('doc_type', 'product')->exists())->toBeTrue();
});

it('prevents duplicate outbox entries across multiple recovery attempts on partial progress', function () {
    $product = Product::factory()->create();
    $category = Category::factory()->create();
    $shift = Shift::factory()->create(['business_date' => Carbon::today()->subDays(2)->toDateString()]);
    $order = Order::factory()->create(['shift_id' => $shift->id, 'status' => 'completed']);

    $today = Carbon::today();
    $connection = PlatformConnection::create([
        'url' => 'https://platform.test',
        'branch_uuid' => '01930c11-0000-7000-8000-000000000088',
        'token' => 'dup-token',
        'branch_secret' => 'dup-secret',
        'timezone' => 'Africa/Cairo',
        'status' => 'active',
        'paired_at' => now(),
        'backfill_status' => 'in_progress',
        'backfill_catalog_queued' => true, // Partial progress: catalog already completed
        'backfill_range_queued' => false,
        'backfill_range_from' => $today->copy()->subDays(90)->toDateString(),
        'backfill_range_to' => $today->toDateString(),
        'backfill_attempts' => 1,
        'backfill_lock_owner' => 'crashed-worker-prior',
        'backfill_locked_at' => now()->subMinutes(20), // Expired lease
    ]);

    SyncOutbox::truncate();
    // Simulate catalog outbox rows that were committed during the catalog step
    SyncOutbox::create([
        'doc_type' => 'product',
        'doc_uuid' => $product->uuid,
        'op' => 'upsert',
        'priority' => 1,
        'occurred_at' => now()->subMinutes(20),
    ]);
    SyncOutbox::create([
        'doc_type' => 'category',
        'doc_uuid' => $category->uuid,
        'op' => 'upsert',
        'priority' => 1,
        'occurred_at' => now()->subMinutes(20),
    ]);

    $catalogCountBefore = SyncOutbox::count();
    expect($catalogCountBefore)->toBe(2);

    $service = app(BackfillService::class);

    // 1st Recovery attempt
    $recovered1 = $service->recoverInitialBackfillIfNeeded($connection);
    expect($recovered1)->toBeTrue();

    $fresh1 = $connection->fresh();
    expect($fresh1->backfill_status)->toBe('completed')
        ->and($fresh1->backfill_catalog_queued)->toBeTrue()
        ->and($fresh1->backfill_range_queued)->toBeTrue()
        ->and($fresh1->backfill_lock_owner)->toBeNull();

    // Catalog documents were NOT re-queued or duplicated
    expect(SyncOutbox::where('doc_type', 'product')->count())->toBe(1)
        ->and(SyncOutbox::where('doc_type', 'category')->count())->toBe(1)
        ->and(SyncOutbox::where('doc_type', 'order')->where('doc_uuid', $order->uuid)->count())->toBe(1);

    $totalCountAfterFirstRecovery = SyncOutbox::count();

    // 2nd Recovery attempt (should be a clean no-op because backfill is already completed)
    $recovered2 = $service->recoverInitialBackfillIfNeeded($connection);
    expect($recovered2)->toBeTrue();

    // Outbox count is completely unchanged: zero duplicates
    expect(SyncOutbox::count())->toBe($totalCountAfterFirstRecovery);

    // 3rd Recovery attempt via artisan command
    $this->artisan('platform:backfill', ['--recover' => true])
        ->assertSuccessful();

    expect(SyncOutbox::count())->toBe($totalCountAfterFirstRecovery);
});
