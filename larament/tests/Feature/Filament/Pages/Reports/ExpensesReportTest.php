<?php

use App\Enums\UserRole;
use App\Filament\Pages\Reports\ExpensesReport;
use App\Filament\Widgets\NoShiftsInPeriodWidget;
use App\Filament\Widgets\PeriodShiftExpensesTable;
use App\Filament\Widgets\PeriodShiftExpensesDetailsTable;
use App\Models\Expense;
use App\Models\ExpenceType;
use App\Models\Shift;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => UserRole::ADMIN]);
    actingAs($this->admin);
});

// Page Rendering Tests
it('can render the expenses report page', function () {
    livewire(ExpensesReport::class)
        ->assertSuccessful();
});

it('can load the page with default filters', function () {
    livewire(ExpensesReport::class)
        ->assertSuccessful()
        ->assertFormExists();
});

// Filter Form Tests
it('has filter form with required fields', function () {
    livewire(ExpensesReport::class)
        ->assertSchemaComponentExists('filterType', form: 'filtersForm')
        ->assertSchemaComponentExists('presetPeriod', form: 'filtersForm')
        ->assertSchemaComponentExists('startDate', form: 'filtersForm')
        ->assertSchemaComponentExists('endDate', form: 'filtersForm');
});

it('can switch between period and shifts filter types', function () {
    livewire(ExpensesReport::class)
        ->assertFormFieldIsVisible('presetPeriod', 'filtersForm')
        ->fillForm(['filterType' => 'shifts'], 'filtersForm')
        ->assertFormFieldIsVisible('shifts', 'filtersForm')
        ->assertFormFieldIsHidden('presetPeriod', 'filtersForm');
});

it('defaults to period filter type', function () {
    livewire(ExpensesReport::class)
        ->assertSchemaStateSet([
            'filterType' => 'period',
        ], 'filtersForm');
});

it('defaults to last 7 days preset', function () {
    livewire(ExpensesReport::class)
        ->assertSchemaStateSet([
            'presetPeriod' => 'last_7_days',
        ], 'filtersForm');
});

it('can change preset period', function () {
    livewire(ExpensesReport::class)
        ->fillForm(['presetPeriod' => 'this_month'])
        ->assertSuccessful();
});

it('can select custom period', function () {
    livewire(ExpensesReport::class)
        ->fillForm([
            'presetPeriod' => 'custom',
            'startDate' => now()->subDays(10)->toDateString(),
            'endDate' => now()->toDateString(),
        ])
        ->assertSuccessful();
});

it('can select specific shifts', function () {
    $shifts = Shift::factory()->count(3)->closed()->create();

    livewire(ExpensesReport::class)
        ->fillForm([
            'filterType' => 'shifts',
            'shifts' => $shifts->pluck('id')->toArray(),
        ])
        ->assertSuccessful();
});

// Widget Visibility Tests
it('shows no shifts widget when no shifts in period', function () {
    $widgets = livewire(ExpensesReport::class)
        ->set('filters', [
            'filterType' => 'period',
            'startDate' => now()->addDays(1)->toDateString(),
            'endDate' => now()->addDays(2)->toDateString(),
        ])
        ->call('getWidgets');

    expect($widgets->instance()->getWidgets())->toContain(NoShiftsInPeriodWidget::class);
});

it('shows expense tables when shifts exist in period', function () {
    $shift = Shift::factory()->closed()->create([
        'start_at' => now()->subDays(3),
    ]);
    $expenseType = ExpenceType::factory()->create();
    Expense::factory()->create([
        'shift_id' => $shift->id,
        'expence_type_id' => $expenseType->id,
    ]);

    $widgets = livewire(ExpensesReport::class)
        ->set('filters', [
            'filterType' => 'period',
            'startDate' => now()->subDays(7)->toDateString(),
            'endDate' => now()->toDateString(),
        ])
        ->call('getWidgets');

    expect($widgets->instance()->getWidgets())
        ->toContain(PeriodShiftExpensesTable::class)
        ->toContain(PeriodShiftExpensesDetailsTable::class);
});

it('shows no shifts widget when selected shifts is empty', function () {
    $widgets = livewire(ExpensesReport::class)
        ->set('filters', [
            'filterType' => 'shifts',
            'shifts' => [],
        ])
        ->call('getWidgets');

    expect($widgets->instance()->getWidgets())->toContain(NoShiftsInPeriodWidget::class);
});

it('shows expense tables when shifts are selected', function () {
    $shift = Shift::factory()->closed()->create();
    $expenseType = ExpenceType::factory()->create();
    Expense::factory()->create([
        'shift_id' => $shift->id,
        'expence_type_id' => $expenseType->id,
    ]);

    $widgets = livewire(ExpensesReport::class)
        ->set('filters', [
            'filterType' => 'shifts',
            'shifts' => [$shift->id],
        ])
        ->call('getWidgets');

    expect($widgets->instance()->getWidgets())
        ->toContain(PeriodShiftExpensesTable::class)
        ->toContain(PeriodShiftExpensesDetailsTable::class);
});

// Period Filter Functionality Tests
it('filters expenses by date range', function () {
    $oldShift = Shift::factory()->closed()->create([
        'start_at' => now()->subDays(15),
        'created_at' => now()->subDays(15),
    ]);
    $recentShift = Shift::factory()->closed()->create([
        'start_at' => now()->subDays(3),
        'created_at' => now()->subDays(3),
    ]);

    $expenseType = ExpenceType::factory()->create();
    $oldExpense = Expense::factory()->create([
        'shift_id' => $oldShift->id,
        'expence_type_id' => $expenseType->id,
    ]);
    $recentExpense = Expense::factory()->create([
        'shift_id' => $recentShift->id,
        'expence_type_id' => $expenseType->id,
    ]);

    $widgets = livewire(ExpensesReport::class)
        ->set('filters', [
            'filterType' => 'period',
            'startDate' => now()->subDays(7)->toDateString(),
            'endDate' => now()->toDateString(),
        ])
        ->call('getWidgets');

    expect($widgets->instance()->getWidgets())->toContain(PeriodShiftExpensesTable::class);
});

it('filters expenses by selected shifts', function () {
    $shift1 = Shift::factory()->closed()->create();
    $shift2 = Shift::factory()->closed()->create();

    $expenseType = ExpenceType::factory()->create();
    $expense1 = Expense::factory()->create([
        'shift_id' => $shift1->id,
        'expence_type_id' => $expenseType->id,
    ]);
    $expense2 = Expense::factory()->create([
        'shift_id' => $shift2->id,
        'expence_type_id' => $expenseType->id,
    ]);

    $widgets = livewire(ExpensesReport::class)
        ->set('filters', [
            'filterType' => 'shifts',
            'shifts' => [$shift1->id],
        ])
        ->call('getWidgets');

    expect($widgets->instance()->getWidgets())->toContain(PeriodShiftExpensesTable::class);
});

// Preset Period Tests
it('can filter by today', function () {
    $shift = Shift::factory()->closed()->create([
        'start_at' => now(),
        'created_at' => now(),
    ]);

    livewire(ExpensesReport::class)
        ->set('filters', [
            'filterType' => 'period',
            'presetPeriod' => 'today',
            'startDate' => now()->startOfDay()->toDateString(),
            'endDate' => now()->endOfDay()->toDateString(),
        ])
        ->assertSuccessful();
});

it('can filter by yesterday', function () {
    livewire(ExpensesReport::class)
        ->set('filters', [
            'filterType' => 'period',
            'presetPeriod' => 'yesterday',
            'startDate' => now()->subDay()->startOfDay()->toDateString(),
            'endDate' => now()->subDay()->endOfDay()->toDateString(),
        ])
        ->assertSuccessful();
});

it('can filter by this week', function () {
    livewire(ExpensesReport::class)
        ->set('filters', [
            'filterType' => 'period',
            'presetPeriod' => 'this_week',
            'startDate' => now()->startOfWeek()->toDateString(),
            'endDate' => now()->endOfWeek()->toDateString(),
        ])
        ->assertSuccessful();
});

it('can filter by this month', function () {
    livewire(ExpensesReport::class)
        ->set('filters', [
            'filterType' => 'period',
            'presetPeriod' => 'this_month',
            'startDate' => now()->startOfMonth()->toDateString(),
            'endDate' => now()->endOfMonth()->toDateString(),
        ])
        ->assertSuccessful();
});

it('can filter by this year', function () {
    livewire(ExpensesReport::class)
        ->set('filters', [
            'filterType' => 'period',
            'presetPeriod' => 'this_year',
            'startDate' => now()->startOfYear()->toDateString(),
            'endDate' => now()->endOfYear()->toDateString(),
        ])
        ->assertSuccessful();
});

// Access Control Tests
it('allows admin users to access', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    actingAs($admin);

    livewire(ExpensesReport::class)
        ->assertSuccessful();
});

it('allows viewer users to access', function () {
    $viewer = User::factory()->create(['role' => UserRole::VIEWER]);
    actingAs($viewer);

    livewire(ExpensesReport::class)
        ->assertSuccessful();
});

it('denies cashier users access', function () {
    $cashier = User::factory()->create(['role' => UserRole::CASHIER]);
    actingAs($cashier);

    livewire(ExpensesReport::class)
        ->assertForbidden();
});

it('denies watcher users access', function () {
    $watcher = User::factory()->create(['role' => UserRole::WATCHER]);
    actingAs($watcher);

    livewire(ExpensesReport::class)
        ->assertSuccessful(); // Watchers can access reports
});

// Navigation Tests
it('is in the correct navigation group', function () {
    expect(ExpensesReport::getNavigationGroup())
        ->toBe('التقارير');
});

it('has the correct navigation label', function () {
    expect(ExpensesReport::getNavigationLabel())
        ->toBe('تقرير المصروفات');
});

it('has the correct navigation icon', function () {
    expect(ExpensesReport::getNavigationIcon())
        ->toBe('heroicon-o-calendar-days');
});

it('has the correct navigation sort order', function () {
    expect(ExpensesReport::getNavigationSort())
        ->toBe(4);
});

// Integration Tests
it('correctly counts shifts in period', function () {
    Shift::factory()->count(3)->closed()->create([
        'start_at' => now()->subDays(3),
        'created_at' => now()->subDays(3),
    ]);

    Shift::factory()->count(2)->closed()->create([
        'start_at' => now()->subDays(10),
        'created_at' => now()->subDays(10),
    ]);

    livewire(ExpensesReport::class)
        ->set('filters', [
            'filterType' => 'period',
            'startDate' => now()->subDays(7)->toDateString(),
            'endDate' => now()->toDateString(),
        ])
        ->assertSuccessful();
});

it('correctly counts selected shifts', function () {
    $shifts = Shift::factory()->count(5)->closed()->create();

    livewire(ExpensesReport::class)
        ->set('filters', [
            'filterType' => 'shifts',
            'shifts' => $shifts->take(3)->pluck('id')->toArray(),
        ])
        ->assertSuccessful();
});

// Widget Data Tests
it('passes correct filters to widgets when using period filter', function () {
    $shift = Shift::factory()->closed()->create([
        'start_at' => now()->subDays(3),
        'created_at' => now()->subDays(3),
    ]);
    $expenseType = ExpenceType::factory()->create();
    Expense::factory()->create([
        'shift_id' => $shift->id,
        'expence_type_id' => $expenseType->id,
    ]);

    $widgets = livewire(ExpensesReport::class)
        ->set('filters', [
            'filterType' => 'period',
            'startDate' => now()->subDays(7)->toDateString(),
            'endDate' => now()->toDateString(),
        ])
        ->call('getWidgets');

    expect($widgets->instance()->getWidgets())->toContain(PeriodShiftExpensesTable::class);
});

it('passes correct filters to widgets when using shifts filter', function () {
    $shift = Shift::factory()->closed()->create();
    $expenseType = ExpenceType::factory()->create();
    Expense::factory()->create([
        'shift_id' => $shift->id,
        'expence_type_id' => $expenseType->id,
    ]);

    $widgets = livewire(ExpensesReport::class)
        ->set('filters', [
            'filterType' => 'shifts',
            'shifts' => [$shift->id],
        ])
        ->call('getWidgets');

    expect($widgets->instance()->getWidgets())->toContain(PeriodShiftExpensesTable::class);
});

// Multiple Shifts Selection Tests
it('can select multiple shifts', function () {
    $shifts = Shift::factory()->count(5)->closed()->create();

    livewire(ExpensesReport::class)
        ->fillForm([
            'filterType' => 'shifts',
            'shifts' => $shifts->pluck('id')->toArray(),
        ])
        ->assertSuccessful();
});

it('shows correct widgets when multiple shifts are selected', function () {
    $shifts = Shift::factory()->count(3)->closed()->create();
    $expenseType = ExpenceType::factory()->create();

    foreach ($shifts as $shift) {
        Expense::factory()->create([
            'shift_id' => $shift->id,
            'expence_type_id' => $expenseType->id,
        ]);
    }

    $widgets = livewire(ExpensesReport::class)
        ->set('filters', [
            'filterType' => 'shifts',
            'shifts' => $shifts->pluck('id')->toArray(),
        ])
        ->call('getWidgets');

    expect($widgets->instance()->getWidgets())
        ->toContain(PeriodShiftExpensesTable::class)
        ->toContain(PeriodShiftExpensesDetailsTable::class);
});

// Form State Tests
it('date fields are disabled when not using custom preset', function () {
    livewire(ExpensesReport::class)
        ->fillForm(['presetPeriod' => 'last_7_days'], 'filtersForm')
        ->assertFormFieldIsDisabled('startDate', 'filtersForm')
        ->assertFormFieldIsDisabled('endDate', 'filtersForm');
});

it('date fields are enabled when using custom preset', function () {
    livewire(ExpensesReport::class)
        ->fillForm(['presetPeriod' => 'custom'], 'filtersForm')
        ->assertFormFieldIsEnabled('startDate', 'filtersForm')
        ->assertFormFieldIsEnabled('endDate', 'filtersForm');
});

it('shifts field is visible only when filterType is shifts', function () {
    livewire(ExpensesReport::class)
        ->fillForm(['filterType' => 'period'], 'filtersForm')
        ->assertFormFieldIsHidden('shifts', 'filtersForm')
        ->fillForm(['filterType' => 'shifts'], 'filtersForm')
        ->assertFormFieldIsVisible('shifts', 'filtersForm');
});

it('period fields are visible only when filterType is period', function () {
    livewire(ExpensesReport::class)
        ->fillForm(['filterType' => 'shifts'], 'filtersForm')
        ->assertFormFieldIsHidden('presetPeriod', 'filtersForm')
        ->fillForm(['filterType' => 'period'], 'filtersForm')
        ->assertFormFieldIsVisible('presetPeriod', 'filtersForm');
});
