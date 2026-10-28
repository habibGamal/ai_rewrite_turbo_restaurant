<?php

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use App\Services\DailyComparisonReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->service = new DailyComparisonReportService;
    $this->user = User::factory()->create();
    $this->customer = Customer::factory()->create();
    $this->shift = Shift::factory()->create(['user_id' => $this->user->id]);
});

it('calculates daily performance with orders.total as sales and orders.profit as profit', function () {
    $date = Carbon::parse('2026-09-15 12:00:00');

    // Create 2 completed orders and 1 pending order
    Order::factory()->create([
        'customer_id' => $this->customer->id,
        'user_id' => $this->user->id,
        'shift_id' => $this->shift->id,
        'status' => OrderStatus::COMPLETED,
        'total' => 150.00,
        'profit' => 50.00,
        'created_at' => $date,
    ]);

    Order::factory()->create([
        'customer_id' => $this->customer->id,
        'user_id' => $this->user->id,
        'shift_id' => $this->shift->id,
        'status' => OrderStatus::COMPLETED,
        'total' => 250.00,
        'profit' => 100.00,
        'created_at' => $date,
    ]);

    Order::factory()->create([
        'customer_id' => $this->customer->id,
        'user_id' => $this->user->id,
        'shift_id' => $this->shift->id,
        'status' => OrderStatus::PENDING,
        'total' => 500.00,
        'profit' => 200.00,
        'created_at' => $date,
    ]);

    $data = $this->service->getDailyPerformance('2026-09-15', '2026-09-15');

    expect($data)->toHaveCount(1);
    $day = $data->first();
    expect($day->total_orders)->toBe(2);
    expect($day->total_sales)->toBe(400.0);
    expect($day->total_profit)->toBe(150.0);
    expect($day->avg_order_value)->toBe(200.0);
});

it('fills missing days in date range with zeros to ensure continuous timeline', function () {
    // 3 days range, order only on day 2
    Order::factory()->create([
        'customer_id' => $this->customer->id,
        'user_id' => $this->user->id,
        'shift_id' => $this->shift->id,
        'status' => OrderStatus::COMPLETED,
        'total' => 100.00,
        'profit' => 30.00,
        'created_at' => Carbon::parse('2026-09-02 10:00:00'),
    ]);

    $data = $this->service->getDailyPerformance('2026-09-01', '2026-09-03');

    expect($data)->toHaveCount(3);

    // Day 1: 0 orders
    expect($data[0]->date)->toBe('2026-09-01');
    expect($data[0]->total_orders)->toBe(0);
    expect($data[0]->total_sales)->toBe(0.0);
    expect($data[0]->total_profit)->toBe(0.0);

    // Day 2: 1 order
    expect($data[1]->date)->toBe('2026-09-02');
    expect($data[1]->total_orders)->toBe(1);
    expect($data[1]->total_sales)->toBe(100.0);
    expect($data[1]->total_profit)->toBe(30.0);

    // Day 3: 0 orders
    expect($data[2]->date)->toBe('2026-09-03');
    expect($data[2]->total_orders)->toBe(0);
    expect($data[2]->total_sales)->toBe(0.0);
});

it('orders week days strictly from Saturday to Friday', function () {
    // Saturday 2026-09-19
    Order::factory()->create([
        'customer_id' => $this->customer->id,
        'user_id' => $this->user->id,
        'shift_id' => $this->shift->id,
        'status' => OrderStatus::COMPLETED,
        'total' => 300.00,
        'profit' => 100.00,
        'created_at' => Carbon::parse('2026-09-19 14:00:00'),
    ]);

    // Friday 2026-09-25
    Order::factory()->create([
        'customer_id' => $this->customer->id,
        'user_id' => $this->user->id,
        'shift_id' => $this->shift->id,
        'status' => OrderStatus::COMPLETED,
        'total' => 500.00,
        'profit' => 200.00,
        'created_at' => Carbon::parse('2026-09-25 18:00:00'),
    ]);

    $weekData = $this->service->getDaysOfWeekPerformance('2026-09-19', '2026-09-25');

    expect($weekData)->toHaveCount(7);
    // Index 0 must be Saturday (السبت)
    expect($weekData[0]->day_name)->toBe('السبت');
    expect($weekData[0]->total_orders)->toBe(1);
    expect($weekData[0]->total_sales)->toBe(300.0);

    // Index 6 must be Friday (الجمعة)
    expect($weekData[6]->day_name)->toBe('الجمعة');
    expect($weekData[6]->total_orders)->toBe(1);
    expect($weekData[6]->total_sales)->toBe(500.0);

    // Middle days must exist with 0
    expect($weekData[1]->day_name)->toBe('الأحد');
    expect($weekData[1]->total_orders)->toBe(0);
});

it('aggregates performance by month correctly', function () {
    // January 2026
    Order::factory()->create([
        'customer_id' => $this->customer->id,
        'user_id' => $this->user->id,
        'shift_id' => $this->shift->id,
        'status' => OrderStatus::COMPLETED,
        'total' => 1000.00,
        'profit' => 400.00,
        'created_at' => Carbon::parse('2026-01-10 12:00:00'),
    ]);

    // February 2026
    Order::factory()->create([
        'customer_id' => $this->customer->id,
        'user_id' => $this->user->id,
        'shift_id' => $this->shift->id,
        'status' => OrderStatus::COMPLETED,
        'total' => 1500.00,
        'profit' => 600.00,
        'created_at' => Carbon::parse('2026-02-15 12:00:00'),
    ]);

    $monthlyData = $this->service->getMonthlyPerformance('2026-01-01', '2026-03-31');

    expect($monthlyData)->toHaveCount(3);
    expect($monthlyData[0]->day_name)->toBe('يناير');
    expect($monthlyData[0]->total_sales)->toBe(1000.0);

    expect($monthlyData[1]->day_name)->toBe('فبراير');
    expect($monthlyData[1]->total_sales)->toBe(1500.0);

    expect($monthlyData[2]->day_name)->toBe('مارس');
    expect($monthlyData[2]->total_sales)->toBe(0.0);
});

it('calculates period summary KPIs accurately', function () {
    Order::factory()->create([
        'customer_id' => $this->customer->id,
        'user_id' => $this->user->id,
        'shift_id' => $this->shift->id,
        'status' => OrderStatus::COMPLETED,
        'total' => 200.00,
        'profit' => 80.00,
        'created_at' => Carbon::parse('2026-09-01 10:00:00'),
    ]);

    Order::factory()->create([
        'customer_id' => $this->customer->id,
        'user_id' => $this->user->id,
        'shift_id' => $this->shift->id,
        'status' => OrderStatus::COMPLETED,
        'total' => 400.00,
        'profit' => 160.00,
        'created_at' => Carbon::parse('2026-09-02 10:00:00'),
    ]);

    $summary = $this->service->getPeriodSummary('2026-09-01', '2026-09-02');

    expect($summary['total_orders'])->toBe(2);
    expect($summary['total_sales'])->toBe(600.0);
    expect($summary['total_profit'])->toBe(240.0);
    expect($summary['avg_order_value'])->toBe(300.0);
    expect($summary['best_sales_period']->date)->toBe('2026-09-02');
});
