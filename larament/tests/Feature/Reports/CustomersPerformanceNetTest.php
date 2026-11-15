<?php

use App\Enums\OrderStatus;
use App\Filament\Pages\Reports\CustomersPerformanceReport;
use App\Filament\Widgets\CustomerActivityTrendWidget;
use App\Filament\Widgets\CustomerOrderTypePerformanceWidget;
use App\Filament\Widgets\CustomersAtRiskTableWidget;
use App\Filament\Widgets\CustomerSegmentsWidget;
use App\Filament\Widgets\CustomersPerformanceStatsWidget;
use App\Filament\Widgets\CustomersPerformanceTableWidget;
use App\Filament\Widgets\TopCustomersBySalesWidget;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\OrderReturnItem;
use App\Models\Product;
use App\Models\User;
use App\Services\CustomersPerformanceReportService;
use App\Services\Reports\CustomerReportThresholds;
use Livewire\Livewire;

function customerOrder(Customer $customer, float $gross, float $discount, float $cost, array $attributes = []): Order
{
    $order = Order::factory()->create(array_merge([
        'customer_id' => $customer->id,
        'status' => OrderStatus::COMPLETED,
        'type' => 'takeaway',
        'sub_total' => $gross,
        'discount' => $discount,
        'service' => 0,
        'tax' => 0,
        'total' => $gross - $discount,
        'profit' => $gross - $discount - $cost,
    ], $attributes));

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => Product::factory()->create()->id,
        'quantity' => 2,
        'price' => $gross / 2,
        'cost' => $cost / 2,
        'total' => $gross,
    ]);

    return $order;
}

function reportRow(Customer $customer)
{
    return app(CustomersPerformanceReportService::class)
        ->getCustomersPerformanceQuery(now()->subDays(29)->toDateString(), now()->toDateString())
        ->get()
        ->firstWhere('id', $customer->id);
}

it('reports sales and profit after discounts', function () {
    $customer = Customer::factory()->create();
    customerOrder($customer, gross: 200, discount: 40, cost: 100);

    $row = reportRow($customer);

    expect((float) $row->total_sales)->toBe(160.0)
        ->and((float) $row->total_profit)->toBe(60.0)
        ->and((float) $row->total_discount)->toBe(40.0)
        ->and((int) $row->total_orders)->toBe(1);
});

it('subtracts returns and drops fully returned orders from the order count', function () {
    $customer = Customer::factory()->create();
    customerOrder($customer, gross: 100, discount: 0, cost: 40);
    $returned = customerOrder($customer, gross: 100, discount: 0, cost: 40, attributes: ['return_status' => 'full_return']);

    $return = OrderReturn::create([
        'order_id' => $returned->id,
        'user_id' => User::factory()->create()->id,
        'shift_id' => $returned->shift_id,
        'total_refund' => 100,
        'reverse_stock' => true,
    ]);
    OrderReturnItem::create([
        'order_return_id' => $return->id,
        'order_item_id' => $returned->items()->first()->id,
        'quantity' => 2,
        'refund_amount' => 100,
    ]);

    $row = reportRow($customer);

    expect((float) $row->total_sales)->toBe(100.0)
        ->and((float) $row->total_profit)->toBe(60.0)
        ->and((float) $row->total_refunds)->toBe(100.0)
        ->and((float) $row->total_quantity)->toBe(2.0)
        ->and((int) $row->total_orders)->toBe(1);
});

it('leaves out customers with no orders in the period', function () {
    $buyer = Customer::factory()->create();
    $idle = Customer::factory()->create();
    customerOrder($buyer, gross: 100, discount: 0, cost: 40);
    customerOrder($idle, gross: 100, discount: 0, cost: 40, attributes: ['created_at' => now()->subDays(90)]);

    $ids = app(CustomersPerformanceReportService::class)
        ->getCustomersPerformanceQuery(now()->subDays(29)->toDateString(), now()->toDateString())
        ->pluck('id')->all();

    expect($ids)->toBe([$buyer->id]);
});

it('uses the saved segment thresholds', function () {
    $customer = Customer::factory()->create();
    customerOrder($customer, gross: 300, discount: 0, cost: 100);
    customerOrder($customer, gross: 300, discount: 0, cost: 100);

    expect(reportRow($customer)->customer_segment)->toBe(CustomerReportThresholds::SEGMENT_OCCASIONAL);

    app(CustomerReportThresholds::class)->save(array_merge(CustomerReportThresholds::DEFAULTS, [
        'vip_min_sales' => 500,
        'vip_min_orders' => 2,
    ]));

    expect(reportRow($customer)->customer_segment)->toBe(CustomerReportThresholds::SEGMENT_VIP);
});

it('computes kpis with new customers and previous period', function () {
    $returning = Customer::factory()->create();
    $new = Customer::factory()->create();
    customerOrder($returning, gross: 100, discount: 0, cost: 50, attributes: ['created_at' => now()->subDays(40)]);
    customerOrder($returning, gross: 100, discount: 0, cost: 50, attributes: ['created_at' => now()->subDays(5)]);
    customerOrder($returning, gross: 100, discount: 0, cost: 50, attributes: ['created_at' => now()->subDays(3)]);
    customerOrder($new, gross: 200, discount: 0, cost: 100, attributes: ['created_at' => now()->subDays(2)]);
    Order::factory()->create(['customer_id' => null, 'status' => OrderStatus::COMPLETED, 'total' => 100, 'profit' => 10, 'created_at' => now()->subDay()]);

    $kpis = app(CustomersPerformanceReportService::class)
        ->getKpis(now()->subDays(29)->toDateString(), now()->toDateString());

    expect($kpis['current']['customers'])->toBe(2.0)
        ->and($kpis['current']['new_customers'])->toBe(1.0)
        ->and($kpis['current']['sales'])->toBe(400.0)
        ->and($kpis['current']['sales_share'])->toBe(80.0)
        ->and($kpis['current']['avg_order_value'])->toBe(400 / 3)
        ->and($kpis['current']['return_rate'])->toBe(50.0)
        ->and($kpis['previous']['customers'])->toBe(1.0)
        ->and($kpis['previous']['sales'])->toBe(100.0);
});

it('lists regular customers who stopped ordering', function () {
    $stopped = Customer::factory()->create();
    $active = Customer::factory()->create();
    foreach ([100, 93, 86] as $daysAgo) {
        customerOrder($stopped, gross: 100, discount: 0, cost: 50, attributes: ['created_at' => now()->subDays($daysAgo)]);
    }
    foreach ([14, 7, 1] as $daysAgo) {
        customerOrder($active, gross: 100, discount: 0, cost: 50, attributes: ['created_at' => now()->subDays($daysAgo)]);
    }

    $rows = app(CustomersPerformanceReportService::class)->getAtRiskCustomersQuery()->get();

    expect($rows->pluck('id')->all())->toBe([$stopped->id])
        ->and(round((float) $rows->first()->usual_gap))->toBe(7.0)
        ->and(round((float) $rows->first()->days_since_last))->toBe(86.0);
});

it('renders the report page with the new widgets and saves thresholds', function () {
    $this->actingAs(User::factory()->admin()->create());
    $customer = Customer::factory()->create();
    customerOrder($customer, gross: 100, discount: 10, cost: 40);

    \Pest\Livewire\livewire(CustomersPerformanceReport::class)
        ->assertSuccessful()
        ->assertSeeLivewire(CustomersPerformanceStatsWidget::class)
        ->assertSeeLivewire(CustomersAtRiskTableWidget::class)
        ->callAction('reportSettings', data: array_merge(CustomerReportThresholds::DEFAULTS, ['vip_min_sales' => 1234]))
        ->assertHasNoActionErrors();

    expect(app(CustomerReportThresholds::class)->all()['vip_min_sales'])->toBe(1234.0);

    Livewire::test(CustomersPerformanceStatsWidget::class)->assertSuccessful()->assertSee('عملاء جدد');
    Livewire::test(CustomersPerformanceTableWidget::class)->assertSuccessful()->assertCanSeeTableRecords([$customer]);
    Livewire::test(CustomerSegmentsWidget::class)->assertSuccessful();
    Livewire::test(CustomerOrderTypePerformanceWidget::class)->assertSuccessful();
    Livewire::test(CustomerActivityTrendWidget::class)->assertSuccessful();
    Livewire::test(TopCustomersBySalesWidget::class)->assertSuccessful();
});
