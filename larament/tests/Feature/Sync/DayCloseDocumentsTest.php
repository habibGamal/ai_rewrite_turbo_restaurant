<?php

use App\Enums\OrderStatus;
use App\Models\ExpenceType;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shift;
use App\Models\SyncOutbox;
use App\Models\Waste;
use App\Services\InventoryDailyAggregationService;
use App\Sync\Builders\DaySummaryBuilder;
use App\Sync\DateDocument;
use App\Sync\DocumentType;
use Carbon\Carbon;
use Illuminate\Support\Str;

it('records inventory_day and day_summary when the day is closed', function () {
    pairPlatform();

    $product = Product::factory()->create();
    $date = Carbon::parse('2026-10-29');
    openInventoryDay($product, $date);

    $service = app(InventoryDailyAggregationService::class);
    $closedCount = $service->closeDay();

    expect($closedCount)->toBeGreaterThan(0);

    $expectedInventoryUuid = DateDocument::uuid(DocumentType::InventoryDay, '2026-10-29');
    $expectedDaySummaryUuid = DateDocument::uuid(DocumentType::DaySummary, '2026-10-29');

    expect(SyncOutbox::where('doc_type', 'inventory_day')->count())->toBe(1)
        ->and(SyncOutbox::where('doc_type', 'day_summary')->count())->toBe(1);

    $invOutbox = SyncOutbox::where('doc_type', 'inventory_day')->first();
    expect($invOutbox->doc_uuid)->toBe($expectedInventoryUuid)
        ->and($invOutbox->doc_key)->toBe('2026-10-29')
        ->and($invOutbox->op)->toBe('upsert')
        ->and($invOutbox->priority)->toBe(5);

    $summaryOutbox = SyncOutbox::where('doc_type', 'day_summary')->first();
    expect($summaryOutbox->doc_uuid)->toBe($expectedDaySummaryUuid)
        ->and($summaryOutbox->doc_key)->toBe('2026-10-29')
        ->and($summaryOutbox->op)->toBe('upsert')
        ->and($summaryOutbox->priority)->toBe(5);
});

it('does not record day close documents when not paired', function () {
    $product = Product::factory()->create();
    $date = Carbon::parse('2026-10-29');
    openInventoryDay($product, $date);

    $service = app(InventoryDailyAggregationService::class);
    $service->closeDay();

    expect(SyncOutbox::whereIn('doc_type', ['inventory_day', 'day_summary'])->count())->toBe(0);
});

it('computes day_summary totals from completed orders of shifts with that business date', function () {
    pairPlatform();

    $date = '2026-10-29';

    // Shift 1: Started on 2026-10-29 at 20:00:00, ended before midnight
    $shift1 = Shift::factory()->create([
        'business_date' => $date,
        'start_at' => Carbon::parse('2026-10-29 20:00:00'),
        'end_at' => Carbon::parse('2026-10-29 23:55:00'),
        'closed' => true,
    ]);

    // Shift 2: Started after midnight at 2026-10-30 00:30:00, but has business_date 2026-10-29
    $shift2 = Shift::factory()->create([
        'business_date' => $date,
        'start_at' => Carbon::parse('2026-10-30 00:30:00'),
        'end_at' => Carbon::parse('2026-10-30 04:00:00'),
        'closed' => true,
    ]);

    // Shift for another business date (2026-10-28) to test date isolation
    $shiftOther = Shift::factory()->create([
        'business_date' => '2026-10-28',
        'closed' => true,
    ]);

    $productA = Product::factory()->create();
    $productB = Product::factory()->create();

    // Completed Order 1 (on Shift 1)
    $order1 = Order::factory()->create([
        'shift_id' => $shift1->id,
        'status' => OrderStatus::COMPLETED,
        'total' => 300.00,
        'tax' => 20.00,
        'discount' => 15.00,
    ]);
    OrderItem::factory()->create([
        'order_id' => $order1->id,
        'product_id' => $productA->id,
        'quantity' => 2.000,
        'cost' => 40.00, // cogs = 80.00
    ]);
    OrderItem::factory()->create([
        'order_id' => $order1->id,
        'product_id' => $productB->id,
        'quantity' => 1.000,
        'cost' => 50.00, // cogs = 50.00
    ]);

    // Completed Order 2 (on Shift 1)
    $order2 = Order::factory()->create([
        'shift_id' => $shift1->id,
        'status' => OrderStatus::COMPLETED,
        'total' => 250.00,
        'tax' => 10.00,
        'discount' => 5.00,
    ]);
    OrderItem::factory()->create([
        'order_id' => $order2->id,
        'product_id' => $productA->id,
        'quantity' => 3.000,
        'cost' => 30.00, // cogs = 90.00
    ]);

    // Completed Order 3 (on Shift 2, created past midnight at 2026-10-30 01:15:00)
    $order3 = Order::factory()->create([
        'shift_id' => $shift2->id,
        'status' => OrderStatus::COMPLETED,
        'total' => 150.00,
        'tax' => 0.00,
        'discount' => 10.00,
    ]);
    $order3->created_at = Carbon::parse('2026-10-30 01:15:00');
    $order3->save();

    OrderItem::factory()->create([
        'order_id' => $order3->id,
        'product_id' => $productB->id,
        'quantity' => 1.500,
        'cost' => 20.00, // cogs = 30.00
    ]);

    // Cancelled Order 4 (on Shift 2, created past midnight at 2026-10-30 02:00:00)
    $order4 = Order::factory()->create([
        'shift_id' => $shift2->id,
        'status' => OrderStatus::CANCELLED,
        'total' => 500.00,
        'tax' => 50.00,
        'discount' => 50.00,
    ]);
    $order4->created_at = Carbon::parse('2026-10-30 02:00:00');
    $order4->save();

    OrderItem::factory()->create([
        'order_id' => $order4->id,
        'product_id' => $productA->id,
        'quantity' => 5.000,
        'cost' => 60.00, // cogs = 300.00 - should NOT be included in cogs_total
    ]);

    // Completed Order on another date (shiftOther) - should NOT be included
    $orderOther = Order::factory()->create([
        'shift_id' => $shiftOther->id,
        'status' => OrderStatus::COMPLETED,
        'total' => 999.00,
        'tax' => 99.00,
        'discount' => 99.00,
    ]);
    OrderItem::factory()->create([
        'order_id' => $orderOther->id,
        'product_id' => $productA->id,
        'quantity' => 10.000,
        'cost' => 50.00,
    ]);

    // Expenses on shifts with business_date = 2026-10-29
    $expenseType = ExpenceType::factory()->create();

    Expense::factory()->create([
        'shift_id' => $shift1->id,
        'expence_type_id' => $expenseType->id,
        'amount' => 75.50,
    ]);
    Expense::factory()->create([
        'shift_id' => $shift2->id,
        'expence_type_id' => $expenseType->id,
        'amount' => 24.50,
    ]);
    // Expense on another date's shift - should NOT be included
    Expense::factory()->create([
        'shift_id' => $shiftOther->id,
        'expence_type_id' => $expenseType->id,
        'amount' => 200.00,
    ]);

    // Wastes
    // Closed waste 1 on 2026-10-29
    Waste::factory()->create([
        'business_date' => $date,
        'total' => 45.00,
        'closed_at' => Carbon::parse('2026-10-29 23:00:00'),
    ]);
    // Closed waste 2 on 2026-10-29 (closed past midnight)
    Waste::factory()->create([
        'business_date' => $date,
        'total' => 15.00,
        'closed_at' => Carbon::parse('2026-10-30 02:00:00'),
    ]);
    // Open waste on 2026-10-29 - should NOT be included
    Waste::factory()->create([
        'business_date' => $date,
        'total' => 300.00,
        'closed_at' => null,
    ]);
    // Closed waste on another date - should NOT be included
    Waste::factory()->create([
        'business_date' => '2026-10-28',
        'total' => 120.00,
        'closed_at' => Carbon::parse('2026-10-28 20:00:00'),
    ]);

    $builder = app(DaySummaryBuilder::class);
    $payload = $builder->withKey($date)->build('dummy-uuid');

    expect($payload)->toBe([
        'date' => '2026-10-29',
        'orders_count' => 4,
        'completed_count' => 3,
        'net_total' => '670.00',
        'discount_total' => '30.00',
        'cogs_total' => '250.00',
        'expenses_total' => '100.00',
        'revenues_total' => '0.00',
        'waste_total' => '60.00',
        'refunds_total' => '0.00',
        'refunds_cash' => '0.00',
    ]);
});

it('produces the same uuid for the same branch and date', function () {
    pairPlatform();

    $invUuid1 = DateDocument::uuid(DocumentType::InventoryDay, '2026-10-29');
    $invUuid2 = DateDocument::uuid(DocumentType::InventoryDay, '2026-10-29');

    expect($invUuid1)->toBe($invUuid2)
        ->and(Str::isUuid($invUuid1))->toBeTrue();

    $summaryUuid1 = DateDocument::uuid(DocumentType::DaySummary, '2026-10-29');
    $summaryUuid2 = DateDocument::uuid(DocumentType::DaySummary, '2026-10-29');

    expect($summaryUuid1)->toBe($summaryUuid2)
        ->and(Str::isUuid($summaryUuid1))->toBeTrue()
        ->and($summaryUuid1)->not->toBe($invUuid1);

    // Different date produces different UUID
    $diffDateUuid = DateDocument::uuid(DocumentType::InventoryDay, '2026-10-30');
    expect($diffDateUuid)->not->toBe($invUuid1);
});
