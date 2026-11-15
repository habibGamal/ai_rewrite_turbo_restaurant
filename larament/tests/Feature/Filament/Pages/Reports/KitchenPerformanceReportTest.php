<?php

use App\Enums\KitchenItemStatus;
use App\Enums\KitchenTicketStatus;
use App\Filament\Pages\Reports\KitchenPerformanceReport;
use App\Filament\Widgets\KitchenPerformanceOrderTypesWidget;
use App\Filament\Widgets\KitchenPerformanceSlowestOrdersWidget;
use App\Filament\Widgets\KitchenPerformanceSlowestProductsWidget;
use App\Filament\Widgets\KitchenPerformanceStationsWidget;
use App\Filament\Widgets\KitchenPerformanceStatsWidget;
use App\Filament\Widgets\KitchenPerformanceWasteCandidatesWidget;
use App\Models\KitchenStation;
use App\Models\KitchenTicket;
use App\Models\KitchenTicketItem;
use App\Models\KitchenTicketRound;
use App\Models\Order;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    actingAs($this->admin);
});

/**
 * @return array{0: KitchenTicket, 1: KitchenTicketRound}
 */
function kdsReportPageTicket(): array
{
    $base = now()->startOfDay()->addHours(10);

    $order = Order::factory()->create();
    $ticket = KitchenTicket::factory()->create([
        'order_id' => $order->id,
        'status' => KitchenTicketStatus::DONE,
        'first_sent_at' => $base,
        'done_at' => $base->copy()->addMinutes(6),
    ]);

    $round = KitchenTicketRound::factory()->create([
        'kitchen_ticket_id' => $ticket->id,
        'number' => 1,
        'started_at' => $base,
        'ended_at' => $base->copy()->addMinutes(6),
    ]);

    KitchenTicketItem::factory()->create([
        'kitchen_ticket_id' => $ticket->id,
        'kitchen_ticket_round_id' => $round->id,
        'product_id' => null,
        'kitchen_station_id' => null,
        'product_name' => 'شاورما',
        'status' => KitchenItemStatus::DONE,
        'target_minutes' => 10,
        'sent_at' => $base,
        'done_at' => $base->copy()->addMinutes(6),
    ]);

    return [$ticket, $round];
}

it('can render the kitchen performance report page', function () {
    livewire(KitchenPerformanceReport::class)
        ->assertSuccessful();
});

it('has the correct title and navigation metadata', function () {
    livewire(KitchenPerformanceReport::class)
        ->assertSee('تقرير أداء المطبخ');

    expect(KitchenPerformanceReport::getNavigationGroup())->toBe('التقارير')
        ->and(KitchenPerformanceReport::getNavigationLabel())->toBe('تقرير أداء المطبخ');

    expect((new KitchenPerformanceReport)->getTitle())->toBe('تقرير أداء المطبخ');
});

it('can be accessed by a viewer', function () {
    actingAs(User::factory()->viewer()->create());

    livewire(KitchenPerformanceReport::class)
        ->assertSuccessful();
});

it('cannot be accessed by a cashier', function () {
    actingAs(User::factory()->cashier()->create());

    livewire(KitchenPerformanceReport::class)
        ->assertForbidden();
});

it('shows all kitchen widgets', function () {
    livewire(KitchenPerformanceReport::class)
        ->assertSuccessful()
        ->assertSeeLivewire(KitchenPerformanceStatsWidget::class)
        ->assertSeeLivewire(KitchenPerformanceOrderTypesWidget::class)
        ->assertSeeLivewire(KitchenPerformanceSlowestOrdersWidget::class)
        ->assertSeeLivewire(KitchenPerformanceSlowestProductsWidget::class)
        ->assertSeeLivewire(KitchenPerformanceStationsWidget::class)
        ->assertSeeLivewire(KitchenPerformanceWasteCandidatesWidget::class)
        ->assertSee('مرشحة للهدر');
});

it('applies a custom date range and the optional station filter', function () {
    kdsReportPageTicket();

    $station = KitchenStation::factory()->create(['name' => 'المشويات']);

    $component = livewire(KitchenPerformanceReport::class)
        ->fillForm([
            'presetPeriod' => 'custom',
            'startDate' => now()->subDays(7)->toDateString(),
            'endDate' => now()->toDateString(),
            'kitchen_station_id' => $station->id,
        ], 'filtersForm')
        ->assertHasNoFormErrors()
        ->assertSuccessful();

    expect($component->instance()->filters['presetPeriod'])->toBe('custom')
        ->and((int) $component->instance()->filters['kitchen_station_id'])->toBe($station->id)
        ->and($component->instance()->filters['startDate'])->toBe(now()->subDays(7)->toDateString());
});

it('renders each kitchen widget directly', function () {
    kdsReportPageTicket();

    livewire(KitchenPerformanceStatsWidget::class)->assertSuccessful();
    livewire(KitchenPerformanceOrderTypesWidget::class)->assertSuccessful();
    livewire(KitchenPerformanceSlowestOrdersWidget::class)->assertSuccessful();
    livewire(KitchenPerformanceSlowestProductsWidget::class)->assertSuccessful();
    livewire(KitchenPerformanceStationsWidget::class)->assertSuccessful();
    livewire(KitchenPerformanceWasteCandidatesWidget::class)->assertSuccessful();
});

it('supports search, sort, and filters on slowest orders widget', function () {
    kdsReportPageTicket();

    livewire(KitchenPerformanceSlowestOrdersWidget::class)
        ->searchTable('شاورما')
        ->assertSuccessful()
        ->sortTable('kitchen_seconds', 'asc')
        ->assertSuccessful()
        ->filterTable('late_only', true)
        ->assertSuccessful();
});

it('supports search, sort, and filters on slowest products widget', function () {
    kdsReportPageTicket();

    livewire(KitchenPerformanceSlowestProductsWidget::class)
        ->searchTable('شاورما')
        ->assertSuccessful()
        ->sortTable('avg_prep_seconds', 'asc')
        ->assertSuccessful()
        ->filterTable('late_only', true)
        ->assertSuccessful();
});

it('supports search and sort on waste candidates widget', function () {
    kdsReportPageTicket();

    livewire(KitchenPerformanceWasteCandidatesWidget::class)
        ->searchTable('شاورما')
        ->assertSuccessful()
        ->sortTable('voided_at', 'asc')
        ->assertSuccessful();
});
