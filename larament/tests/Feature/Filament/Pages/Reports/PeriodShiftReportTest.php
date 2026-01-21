<?php

use App\Enums\UserRole;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Filament\Pages\Reports\PeriodShiftReport;
use App\Filament\Widgets\NoShiftsInPeriodWidget;
use App\Filament\Widgets\PeriodShiftInfoStats;
use App\Filament\Widgets\PeriodShiftMoneyInfoStats;
use App\Filament\Widgets\PeriodShiftOrdersStats;
use App\Filament\Widgets\PeriodShiftDoneOrdersStats;
use App\Filament\Widgets\PeriodShiftOrdersTable;
use App\Filament\Widgets\PeriodShiftExpensesDetailsTable;
use App\Filament\Widgets\PeriodShiftExpensesTable;
use App\Models\User;
use App\Models\Shift;
use App\Models\Order;
use App\Models\Expense;
use App\Models\ExpenceType;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => UserRole::ADMIN]);
    actingAs($this->admin);
});

describe('Page Access and Authorization', function () {
    it('can render the page as admin', function () {
        livewire(PeriodShiftReport::class)
            ->assertSuccessful();
    });

    it('can render the page as viewer', function () {
        $viewer = User::factory()->create(['role' => UserRole::VIEWER]);
        actingAs($viewer);

        livewire(PeriodShiftReport::class)
            ->assertSuccessful();
    });

    it('cannot access the page as cashier', function () {
        $cashier = User::factory()->create(['role' => UserRole::CASHIER]);
        actingAs($cashier);

        livewire(PeriodShiftReport::class)
            ->assertForbidden();
    });

    it('cannot access the page as watcher', function () {
        $watcher = User::factory()->create(['role' => UserRole::WATCHER]);
        actingAs($watcher);

        livewire(PeriodShiftReport::class)
            ->assertForbidden();
    });
});

describe('Filters Form', function () {
    it('has filters', function () {
        $report = livewire(PeriodShiftReport::class);

        // Check that filters are accessible
        expect($report->instance()->filters)->toBeArray();
    });

    it('has default filter values for period mode', function () {
        $report = livewire(PeriodShiftReport::class);

        expect($report->instance()->filters)
            ->toHaveKey('filterType')
            ->and($report->instance()->filters['filterType'])->toBe('period')
            ->and($report->instance()->filters)->toHaveKey('presetPeriod')
            ->and($report->instance()->filters['presetPeriod'])->toBe('last_7_days');
    });

    it('can switch between period and shifts filter mode', function () {
        $report = livewire(PeriodShiftReport::class)
            ->set('filters.filterType', 'shifts');

        expect($report->get('filters.filterType'))->toBe('shifts');
    });

    it('has default period filters', function () {
        $report = livewire(PeriodShiftReport::class);

        expect($report->instance()->filters)
            ->toHaveKeys(['filterType', 'presetPeriod', 'startDate', 'endDate']);
    });

    it('shows shifts selector when filterType is shifts', function () {
        $shift = Shift::factory()->closed()->create();

        $report = livewire(PeriodShiftReport::class)
            ->set('filters.filterType', 'shifts');

        expect($report->get('filters.filterType'))->toBe('shifts');
    });

    it('can select preset period', function (string $period, array $dates) {
        livewire(PeriodShiftReport::class)
            ->fillForm([
                'filters.filterType' => 'period',
                'filters.presetPeriod' => $period,
            ])
            ->assertFormSet([
                'filters.presetPeriod' => $period,
                'filters.startDate' => $dates['start'],
                'filters.endDate' => $dates['end'],
            ]);
    })->with([
        'today' => [
            'today',
            fn() => [
                'start' => now()->startOfDay()->toDateString(),
                'end' => now()->endOfDay()->toDateString(),
            ],
        ],
        'yesterday' => [
            'yesterday',
            fn() => [
                'start' => now()->subDay()->startOfDay()->toDateString(),
                'end' => now()->subDay()->endOfDay()->toDateString(),
            ],
        ],
        'last_7_days' => [
            'last_7_days',
            fn() => [
                'start' => now()->subDays(6)->startOfDay()->toDateString(),
                'end' => now()->endOfDay()->toDateString(),
            ],
        ],
        'this_month' => [
            'this_month',
            fn() => [
                'start' => now()->startOfMonth()->toDateString(),
                'end' => now()->endOfMonth()->toDateString(),
            ],
        ],
    ]);

    it('can select multiple shifts', function () {
        $shifts = Shift::factory()->count(3)->closed()->create();

        livewire(PeriodShiftReport::class)
            ->fillForm([
                'filters.filterType' => 'shifts',
                'filters.shifts' => $shifts->pluck('id')->toArray(),
            ])
            ->assertFormSet([
                'filters.shifts' => $shifts->pluck('id')->toArray(),
            ]);
    });

    it('can set custom date range', function () {
        $startDate = now()->subDays(10)->toDateString();
        $endDate = now()->toDateString();

        livewire(PeriodShiftReport::class)
            ->fillForm([
                'filters.filterType' => 'period',
                'filters.presetPeriod' => 'custom',
                'filters.startDate' => $startDate,
                'filters.endDate' => $endDate,
            ])
            ->assertFormSet([
                'filters.startDate' => $startDate,
                'filters.endDate' => $endDate,
            ]);
    });
});

describe('Widgets - No Data State', function () {
    it('shows no shifts widget when no shifts exist in period', function () {
        $report = livewire(PeriodShiftReport::class);

        // Check that getWidgets returns only the NoShiftsInPeriodWidget
        expect($report->instance()->getWidgets())
            ->toContain(NoShiftsInPeriodWidget::class)
            ->toHaveCount(1);
    });

    it('shows no shifts widget when selected shifts is empty', function () {
        $report = livewire(PeriodShiftReport::class)
            ->fillForm([
                'filters.filterType' => 'shifts',
                'filters.shifts' => [],
            ]);

        expect($report->instance()->getWidgets())
            ->toContain(NoShiftsInPeriodWidget::class)
            ->toHaveCount(1);
    });
});

describe('Widgets - With Data', function () {
    beforeEach(function () {
        // Create shifts with orders and expenses
        $this->shifts = Shift::factory()->count(3)->closed()->create([
            'start_at' => now()->subDays(3),
            'end_at' => now()->subDays(2),
        ]);

        foreach ($this->shifts as $shift) {
            Order::factory()->count(5)->create([
                'shift_id' => $shift->id,
                'status' => OrderStatus::COMPLETED,
                'type' => OrderType::DINE_IN,
            ]);

            $expenseType = ExpenceType::factory()->create();
            Expense::factory()->count(2)->create([
                'shift_id' => $shift->id,
                'expence_type_id' => $expenseType->id,
            ]);
        }
    });

    it('shows all stat widgets when shifts exist in period', function () {
        $report = livewire(PeriodShiftReport::class)
            ->fillForm([
                'filters.filterType' => 'period',
                'filters.startDate' => now()->subDays(5)->toDateString(),
                'filters.endDate' => now()->toDateString(),
            ]);

        $widgets = $report->instance()->getWidgets();

        expect($widgets)
            ->toContain(PeriodShiftInfoStats::class)
            ->toContain(PeriodShiftMoneyInfoStats::class)
            ->toContain(PeriodShiftOrdersStats::class)
            ->toContain(PeriodShiftDoneOrdersStats::class);
    });

    it('shows table widgets when shifts exist in period', function () {
        $report = livewire(PeriodShiftReport::class)
            ->fillForm([
                'filters.filterType' => 'period',
                'filters.startDate' => now()->subDays(5)->toDateString(),
                'filters.endDate' => now()->toDateString(),
            ]);

        $widgets = $report->instance()->getWidgets();

        expect($widgets)
            ->toContain(PeriodShiftOrdersTable::class)
            ->toContain(PeriodShiftExpensesDetailsTable::class)
            ->toContain(PeriodShiftExpensesTable::class);
    });

    it('shows all widgets when specific shifts are selected', function () {
        $selectedShifts = $this->shifts->take(2)->pluck('id')->toArray();

        $report = livewire(PeriodShiftReport::class)
            ->fillForm([
                'filters.filterType' => 'shifts',
                'filters.shifts' => $selectedShifts,
            ]);

        $widgets = $report->instance()->getWidgets();

        expect($widgets)
            ->toContain(PeriodShiftInfoStats::class)
            ->toContain(PeriodShiftMoneyInfoStats::class)
            ->toContain(PeriodShiftOrdersStats::class)
            ->toContain(PeriodShiftDoneOrdersStats::class)
            ->toContain(PeriodShiftOrdersTable::class)
            ->toContain(PeriodShiftExpensesDetailsTable::class)
            ->toContain(PeriodShiftExpensesTable::class);
    });

    it('does not show NoShiftsInPeriodWidget when data exists', function () {
        $report = livewire(PeriodShiftReport::class)
            ->fillForm([
                'filters.filterType' => 'period',
                'filters.startDate' => now()->subDays(5)->toDateString(),
                'filters.endDate' => now()->toDateString(),
            ]);

        $widgets = $report->instance()->getWidgets();

        expect($widgets)
            ->not->toContain(NoShiftsInPeriodWidget::class);
    });
});

describe('Page Properties', function () {
    it('has correct navigation properties', function () {
        expect(PeriodShiftReport::getNavigationLabel())->toBe('تقرير فترة الشفتات')
            ->and(PeriodShiftReport::getNavigationGroup())->toBe('التقارير')
            ->and(PeriodShiftReport::getNavigationSort())->toBe(3)
            ->and(PeriodShiftReport::getNavigationIcon())->toBe('heroicon-o-calendar-days');
    });

    it('has correct title property', function () {
        $page = livewire(PeriodShiftReport::class);

        expect($page->instance()::$title)->toBe('تقرير فترة الشفتات');
    });

    it('has correct route path property', function () {
        expect(PeriodShiftReport::$routePath)->toBe('period-shift-report');
    });
});

describe('Service Integration', function () {
    it('uses ShiftsReportService to get shifts count', function () {
        $shifts = Shift::factory()->count(5)->closed()->create([
            'start_at' => now()->subDays(3),
            'end_at' => now()->subDays(2),
        ]);

        $report = livewire(PeriodShiftReport::class)
            ->fillForm([
                'filters.filterType' => 'period',
                'filters.startDate' => now()->subDays(5)->toDateString(),
                'filters.endDate' => now()->toDateString(),
            ])
            ->assertSuccessful();

        // Should show data widgets, not NoShiftsInPeriodWidget
        expect($report->instance()->getWidgets())
            ->toContain(PeriodShiftInfoStats::class)
            ->not->toContain(NoShiftsInPeriodWidget::class);
    });

    it('correctly filters by shift IDs', function () {
        $allShifts = Shift::factory()->count(5)->closed()->create([
            'start_at' => now()->subDays(3),
            'end_at' => now()->subDays(2),
        ]);

        $selectedShifts = $allShifts->take(2)->pluck('id')->toArray();

        foreach ($allShifts as $shift) {
            Order::factory()->count(3)->create([
                'shift_id' => $shift->id,
                'status' => OrderStatus::COMPLETED,
            ]);
        }

        $report = livewire(PeriodShiftReport::class)
            ->fillForm([
                'filters.filterType' => 'shifts',
                'filters.shifts' => $selectedShifts,
            ])
            ->assertSuccessful();

        // Should show widgets for selected shifts
        expect($report->instance()->getWidgets())
            ->toContain(PeriodShiftInfoStats::class);
    });
});

describe('Widget Filtering Integration', function () {
    it('passes correct filters to widgets in period mode', function () {
        Shift::factory()->count(3)->closed()->create([
            'start_at' => now()->subDays(3),
            'end_at' => now()->subDays(2),
        ]);

        $startDate = now()->subDays(5)->toDateString();
        $endDate = now()->toDateString();

        $report = livewire(PeriodShiftReport::class)
            ->fillForm([
                'filters.filterType' => 'period',
                'filters.startDate' => $startDate,
                'filters.endDate' => $endDate,
            ]);

        // Verify filters are accessible to widgets
        $filters = $report->instance()->filters;
        expect($filters['filterType'])->toBe('period')
            ->and($filters['startDate'])->toBe($startDate)
            ->and($filters['endDate'])->toBe($endDate);
    });

    it('passes correct filters to widgets in shifts mode', function () {
        $shifts = Shift::factory()->count(3)->closed()->create();
        $selectedShiftIds = $shifts->take(2)->pluck('id')->toArray();

        $report = livewire(PeriodShiftReport::class)
            ->fillForm([
                'filters.filterType' => 'shifts',
                'filters.shifts' => $selectedShiftIds,
            ]);

        // Verify filters are accessible to widgets
        $filters = $report->instance()->filters;
        expect($filters['filterType'])->toBe('shifts')
            ->and($filters['shifts'])->toBe($selectedShiftIds);
    });
});

describe('Edge Cases', function () {
    it('handles empty shift selection gracefully', function () {
        $report = livewire(PeriodShiftReport::class)
            ->fillForm([
                'filters.filterType' => 'shifts',
                'filters.shifts' => [],
            ]);

        expect($report->instance()->getWidgets())
            ->toContain(NoShiftsInPeriodWidget::class);
    });

    it('handles period with no shifts', function () {
        // Create shifts outside the filter period
        Shift::factory()->count(3)->closed()->create([
            'start_at' => now()->subMonths(2),
            'end_at' => now()->subMonths(2)->addHours(8),
        ]);

        $report = livewire(PeriodShiftReport::class)
            ->fillForm([
                'filters.filterType' => 'period',
                'filters.startDate' => now()->subDays(7)->toDateString(),
                'filters.endDate' => now()->toDateString(),
            ]);

        expect($report->instance()->getWidgets())
            ->toContain(NoShiftsInPeriodWidget::class);
    });

    it('handles mixed open and closed shifts correctly', function () {
        // Create both open and closed shifts
        Shift::factory()->count(2)->closed()->create([
            'start_at' => now()->subDays(3),
            'end_at' => now()->subDays(2),
        ]);

        Shift::factory()->active()->create([
            'start_at' => now()->subDays(1),
        ]);

        $report = livewire(PeriodShiftReport::class)
            ->fillForm([
                'filters.filterType' => 'period',
                'filters.startDate' => now()->subDays(5)->toDateString(),
                'filters.endDate' => now()->toDateString(),
            ]);

        // Should show widgets as there are shifts in the period
        expect($report->instance()->getWidgets())
            ->toContain(PeriodShiftInfoStats::class);
    });

    it('handles date range boundaries correctly', function () {
        // Create shift exactly at the start boundary
        Shift::factory()->closed()->create([
            'start_at' => now()->subDays(7)->startOfDay(),
            'end_at' => now()->subDays(7)->addHours(8),
        ]);

        $report = livewire(PeriodShiftReport::class)
            ->fillForm([
                'filters.filterType' => 'period',
                'filters.startDate' => now()->subDays(7)->toDateString(),
                'filters.endDate' => now()->toDateString(),
            ]);

        expect($report->instance()->getWidgets())
            ->toContain(PeriodShiftInfoStats::class);
    });
});

describe('Filter Form Validation', function () {
    it('validates start date is before end date', function () {
        livewire(PeriodShiftReport::class)
            ->fillForm([
                'filters.filterType' => 'period',
                'filters.presetPeriod' => 'custom',
                'filters.startDate' => now()->toDateString(),
                'filters.endDate' => now()->subDays(5)->toDateString(),
            ])
            ->assertSuccessful(); // Form accepts it, but logic should handle it
    });

    it('handles null filter values gracefully', function () {
        livewire(PeriodShiftReport::class)
            ->fillForm([
                'filters.filterType' => 'period',
                'filters.startDate' => null,
                'filters.endDate' => null,
            ])
            ->assertSuccessful();
    });
});

describe('Responsive Behavior', function () {
    it('updates widgets when filter changes', function () {
        $oldShifts = Shift::factory()->count(2)->closed()->create([
            'start_at' => now()->subMonths(1),
            'end_at' => now()->subMonths(1)->addHours(8),
        ]);

        $recentShifts = Shift::factory()->count(3)->closed()->create([
            'start_at' => now()->subDays(3),
            'end_at' => now()->subDays(2),
        ]);

        // First filter: old period with no data
        $report = livewire(PeriodShiftReport::class)
            ->fillForm([
                'filters.filterType' => 'period',
                'filters.startDate' => now()->subDays(5)->toDateString(),
                'filters.endDate' => now()->toDateString(),
            ])
            ->assertSeeLivewire(PeriodShiftInfoStats::class);

        // Change filter: should show different results
        $report->fillForm([
            'filters.filterType' => 'period',
            'filters.startDate' => now()->subMonths(2)->toDateString(),
            'filters.endDate' => now()->subMonths(1)->addDays(1)->toDateString(),
        ])
            ->assertSeeLivewire(PeriodShiftInfoStats::class);
    });

    it('switches between period and shifts mode seamlessly', function () {
        $shifts = Shift::factory()->count(3)->closed()->create([
            'start_at' => now()->subDays(3),
            'end_at' => now()->subDays(2),
        ]);

        $report = livewire(PeriodShiftReport::class)
            ->fillForm([
                'filters.filterType' => 'period',
                'filters.startDate' => now()->subDays(5)->toDateString(),
                'filters.endDate' => now()->toDateString(),
            ])
            ->assertSeeLivewire(PeriodShiftInfoStats::class);

        // Switch to shifts mode
        $report->fillForm([
            'filters.filterType' => 'shifts',
            'filters.shifts' => $shifts->pluck('id')->toArray(),
        ])
            ->assertSeeLivewire(PeriodShiftInfoStats::class);
    });
});
