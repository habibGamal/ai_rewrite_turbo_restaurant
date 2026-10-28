<?php

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Reports\DailyComparisonReport;
use App\Filament\Widgets\DailyComparisonChartWidget;
use App\Filament\Widgets\DailyComparisonStatsWidget;
use App\Filament\Widgets\DailyComparisonTableWidget;
use App\Filament\Widgets\NoCustomersSalesInPeriodWidget;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => UserRole::ADMIN]);
    actingAs($this->admin);

    $this->shift = Shift::factory()->create();
});

it('can render the daily comparison report page', function () {
    livewire(DailyComparisonReport::class)
        ->assertSuccessful();
});

it('has correct page title', function () {
    livewire(DailyComparisonReport::class)
        ->assertSee('تقرير مقارنة أداء أيام الأسبوع والشهر والشهور');
});

it('is in the reports navigation group and has correct attributes', function () {
    expect(DailyComparisonReport::getNavigationGroup())->toBe('التقارير')
        ->and(DailyComparisonReport::getNavigationLabel())->toBe('تقرير مقارنة الأيام والفترات')
        ->and(DailyComparisonReport::getNavigationSort())->toBe(9);

    $reflection = new ReflectionClass(DailyComparisonReport::class);
    $property = $reflection->getProperty('routePath');
    $property->setAccessible(true);
    expect($property->getValue())->toBe('daily-comparison-report');
});

it('allows viewer access', function () {
    $viewer = User::factory()->create(['role' => UserRole::VIEWER]);
    actingAs($viewer);

    expect(DailyComparisonReport::canAccess())->toBeTrue();
});

it('denies cashier access', function () {
    $cashier = User::factory()->create(['role' => UserRole::CASHIER]);
    actingAs($cashier);

    expect(DailyComparisonReport::canAccess())->toBeFalse();
});

it('shows no sales widget when no orders exist in period', function () {
    $component = livewire(DailyComparisonReport::class);

    $widgets = $component->instance()->getWidgets();
    expect($widgets)->toContain(NoCustomersSalesInPeriodWidget::class);
});

it('shows comparison widgets when completed orders exist', function () {
    Order::factory()->create([
        'shift_id' => $this->shift->id,
        'status' => OrderStatus::COMPLETED,
        'total' => 350.00,
        'profit' => 120.00,
        'created_at' => now()->startOfMonth()->addDays(2),
    ]);

    $component = livewire(DailyComparisonReport::class);
    $widgets = $component->instance()->getWidgets();

    expect($widgets)->toContain(DailyComparisonStatsWidget::class)
        ->and($widgets)->toContain(DailyComparisonChartWidget::class)
        ->and($widgets)->toContain(DailyComparisonTableWidget::class);
});

it('can set filters with presets', function (string $preset) {
    livewire(DailyComparisonReport::class)
        ->fillForm([
            'presetPeriod' => $preset,
        ])
        ->assertHasNoFormErrors();
})->with([
    'this_month',
    'last_month',
    'this_week',
    'last_week',
    'last_30_days',
    'last_3_months',
    'this_year',
]);

it('can switch viewType between by_date, by_day_of_week, and by_month', function (string $viewType) {
    livewire(DailyComparisonReport::class)
        ->fillForm([
            'viewType' => $viewType,
        ])
        ->assertHasNoFormErrors();
})->with([
    'by_date',
    'by_day_of_week',
    'by_month',
]);

it('can set custom date range filters', function () {
    livewire(DailyComparisonReport::class)
        ->fillForm([
            'presetPeriod' => 'custom',
            'startDate' => now()->subDays(15)->toDateString(),
            'endDate' => now()->toDateString(),
        ])
        ->assertHasNoFormErrors();
});

it('can select custom month and year', function () {
    livewire(DailyComparisonReport::class)
        ->fillForm([
            'presetPeriod' => 'custom_month',
            'selectedMonth' => 5,
            'selectedYear' => 2026,
        ])
        ->assertHasNoFormErrors();
});

it('renders chart and stats widgets properly', function () {
    Order::factory()->create([
        'shift_id' => $this->shift->id,
        'status' => OrderStatus::COMPLETED,
        'total' => 250.00,
        'profit' => 80.00,
        'created_at' => now(),
    ]);

    livewire(DailyComparisonStatsWidget::class)
        ->assertSuccessful();

    livewire(DailyComparisonChartWidget::class)
        ->assertSuccessful();

    livewire(DailyComparisonTableWidget::class)
        ->assertSuccessful();
});
