<?php

use App\Core\Orders\SalesBreakdown;
use App\Enums\OrderStatus;
use App\Filament\Pages\Reports\ChannelPerformanceReport;
use App\Filament\Widgets\ChannelComparisonTableWidget;
use App\Filament\Widgets\ChannelKpisWidget;
use App\Filament\Widgets\ChannelShareWidget;
use App\Filament\Widgets\NoCustomersSalesInPeriodWidget;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Services\ChannelPerformanceReportService;
use App\Services\Reports\Channels\ChannelReportFilters;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    actingAs($this->admin);
    $this->service = app(ChannelPerformanceReportService::class);
});

/**
 * An order with a known split: total = items + service − discount.
 */
function channelOrder(string $type, float $items, array $extra = []): Order
{
    $service = $extra['service'] ?? 0;
    $discount = $extra['discount'] ?? 0;

    return Order::factory()->create([
        'type' => $type,
        'status' => $extra['status'] ?? OrderStatus::COMPLETED,
        'sub_total' => $items,
        'service' => $service,
        'tax' => 0,
        'discount' => $discount,
        'fees_total' => 0,
        'equipment_total' => 0,
        'total' => $items + $service - $discount,
        'profit' => $extra['profit'] ?? ($items + $service - $discount) / 2,
        'customer_id' => $extra['customer_id'] ?? null,
        'guests_count' => $extra['guests'] ?? null,
        'created_at' => $extra['created_at'] ?? now()->subDays(2),
    ]);
}

function last30(array $extra = []): ChannelReportFilters
{
    return ChannelReportFilters::fromArray([
        'startDate' => now()->subDays(29)->toDateString(),
        'endDate' => now()->toDateString(),
        ...$extra,
    ]);
}

describe('page', function () {
    it('renders for admins and viewers, not cashiers', function () {
        livewire(ChannelPerformanceReport::class)->assertSuccessful();

        actingAs(User::factory()->viewer()->create());
        livewire(ChannelPerformanceReport::class)->assertSuccessful();

        actingAs(User::factory()->cashier()->create());
        livewire(ChannelPerformanceReport::class)->assertForbidden();
    });

    it('shows the empty state when there are no completed orders', function () {
        channelOrder('delivery', 100, ['status' => OrderStatus::PENDING]);
        channelOrder('delivery', 100, ['status' => OrderStatus::CANCELLED]);

        $widgets = livewire(ChannelPerformanceReport::class)->instance()->getWidgets();

        expect($widgets)->toBe([NoCustomersSalesInPeriodWidget::class]);
    });

    it('shows the KPIs, the comparison table and the share bars for several channels', function () {
        channelOrder('delivery', 100);
        channelOrder('dine_in', 200);

        livewire(ChannelPerformanceReport::class)
            ->assertSeeLivewire(ChannelKpisWidget::class)
            ->assertSeeLivewire(ChannelComparisonTableWidget::class)
            ->assertSeeLivewire(ChannelShareWidget::class)
            ->assertDontSeeLivewire(NoCustomersSalesInPeriodWidget::class);
    });

    it('drops the share bars when only one channel sold', function () {
        channelOrder('delivery', 100);

        $widgets = livewire(ChannelPerformanceReport::class)->instance()->getWidgets();

        expect($widgets)->toContain(ChannelComparisonTableWidget::class)
            ->not->toContain(ChannelShareWidget::class);
    });

    it('lists every channel in the table with a totals row and sorts it', function () {
        channelOrder('delivery', 100);
        channelOrder('dine_in', 300);

        livewire(ChannelComparisonTableWidget::class, ['pageFilters' => []])
            ->assertSee('دليفري')
            ->assertSee('صالة')
            ->assertSee('الإجمالي')
            ->assertSeeInOrder(['channel-row-dine_in', 'channel-row-delivery'])
            ->call('sortBy', 'net_sales')
            ->assertSet('direction', 'asc')
            ->assertSeeInOrder(['channel-row-delivery', 'channel-row-dine_in']);
    });

    it('renders the KPI and share widgets', function () {
        channelOrder('delivery', 100);
        channelOrder('dine_in', 300);

        livewire(ChannelKpisWidget::class, ['pageFilters' => []])
            ->assertSee('صافي المبيعات')
            ->assertSee('نسبة الإلغاء');
        livewire(ChannelShareWidget::class, ['pageFilters' => []])
            ->assertSee('75.0%')
            ->assertSee('25.0%');
    });
});

describe('figures', function () {
    it('takes sales from the order totals, so they match the shift reports', function () {
        channelOrder('delivery', 100, ['service' => 12, 'discount' => 10]);
        channelOrder('dine_in', 250, ['service' => 25]);

        $filters = last30();
        $totals = $this->service->totals($filters, $this->service->channelRows($filters));

        expect($totals['net_sales'])->toBe(377.0)
            ->and($totals['net_sales'])->toBe(SalesBreakdown::fromQuery($this->service->ordersQuery($filters))->total)
            ->and($totals['charges'])->toBe(37.0)
            ->and($totals['discount'])->toBe(10.0);
    });

    it('averages per order, not per line', function () {
        channelOrder('delivery', 100);
        channelOrder('delivery', 300);

        $row = $this->service->channelRows(last30())['delivery'];

        expect($row['orders'])->toBe(2)
            ->and($row['avg_order'])->toBe(200.0)
            ->and($row['margin'])->toBe(50.0);
    });

    it('counts a customer once across channels in the totals', function () {
        $customer = Customer::factory()->create();
        channelOrder('delivery', 100, ['customer_id' => $customer->id]);
        channelOrder('dine_in', 100, ['customer_id' => $customer->id]);

        $filters = last30();
        $rows = $this->service->channelRows($filters);

        expect($rows['delivery']['customers'])->toBe(1)
            ->and($rows['dine_in']['customers'])->toBe(1)
            ->and($this->service->totals($filters, $rows)['customers'])->toBe(1);
    });

    it('computes the cancellation rate and shares', function () {
        channelOrder('delivery', 100);
        channelOrder('delivery', 100, ['status' => OrderStatus::CANCELLED]);
        channelOrder('dine_in', 200);
        channelOrder('dine_in', 200);
        channelOrder('dine_in', 200);

        $rows = $this->service->channelRows(last30());

        expect($rows['delivery']['cancel_rate'])->toBe(50.0)
            ->and($rows['dine_in']['cancel_rate'])->toBe(0.0)
            ->and($rows['dine_in']['sales_share'])->toBe(85.71)
            ->and($rows['dine_in']['orders_share'])->toBe(75.0)
            ->and(array_key_first($rows))->toBe('dine_in');
    });

    it('shows guests per channel only where they were counted', function () {
        channelOrder('dine_in', 300, ['guests' => 3]);
        channelOrder('delivery', 100);

        $report = $this->service->report(last30());

        expect($report['rows']['dine_in']['avg_per_guest'])->toBe(100.0)
            ->and($report['rows']['delivery']['guests'])->toBe(0)
            ->and($report['columns']['guests'])->toBeTrue()
            ->and($report['columns']['charges'])->toBeFalse();
    });

    it('limits everything to the chosen channels', function () {
        channelOrder('delivery', 100);
        channelOrder('dine_in', 200);

        $filters = last30(['channels' => ['delivery']]);
        $rows = $this->service->channelRows($filters);

        expect(array_keys($rows))->toBe(['delivery'])
            ->and($this->service->totals($filters, $rows)['net_sales'])->toBe(100.0);
    });

    it('compares with the previous period of the same length', function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-16 12:00'));
        channelOrder('delivery', 150, ['created_at' => '2026-11-15 13:00']);
        channelOrder('delivery', 100, ['created_at' => '2026-11-05 13:00']);
        channelOrder('delivery', 999, ['created_at' => '2026-10-20 13:00']);

        $report = $this->service->report(ChannelReportFilters::fromArray(['startDate' => '2026-11-07', 'endDate' => '2026-11-16']));

        expect($report['comparison']->startDate())->toBe('2026-10-28')
            ->and($report['comparison']->endDate())->toBe('2026-11-06')
            ->and($report['totals']['net_sales'])->toBe(150.0)
            ->and($report['previous_totals']['net_sales'])->toBe(100.0)
            ->and(ChannelPerformanceReportService::change($report['totals']['net_sales'], $report['previous_totals']['net_sales']))->toBe(50.0);
    });

    it('compares with the same period last year, or not at all', function () {
        $lastYear = ChannelReportFilters::fromArray(['startDate' => '2026-11-01', 'endDate' => '2026-11-16', 'compareTo' => 'last_year'])->comparison();
        $none = ChannelReportFilters::fromArray(['startDate' => '2026-11-01', 'endDate' => '2026-11-16', 'compareTo' => 'none']);

        expect($lastYear->startDate())->toBe('2025-11-01')
            ->and($lastYear->endDate())->toBe('2025-11-16')
            ->and($none->comparison())->toBeNull()
            ->and($this->service->report($none)['previous_totals'])->toBeNull();
    });

    it('fills every day of the period for the sparklines', function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-16 12:00'));
        channelOrder('delivery', 100, ['created_at' => '2026-11-15 13:00']);

        $daily = $this->service->daily(ChannelReportFilters::fromArray(['startDate' => '2026-11-14', 'endDate' => '2026-11-16']));

        expect(array_keys($daily))->toBe(['2026-11-14', '2026-11-15', '2026-11-16'])
            ->and($daily['2026-11-15'])->toBe(['orders' => 1, 'net_sales' => 100.0]);
    });
});

it('shows each channel change against the comparison period in the table', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 12:00'));
    channelOrder('delivery', 150, ['created_at' => '2026-11-15 13:00']);
    channelOrder('delivery', 100, ['created_at' => '2026-11-05 13:00']);

    livewire(ChannelComparisonTableWidget::class, ['pageFilters' => ['startDate' => '2026-11-07', 'endDate' => '2026-11-16']])
        ->assertSee('+50.0%')
        ->assertSee('28/10/2026 - 06/11/2026');
});
