<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\SettingKey;
use App\Filament\Pages\Reports\CurrentShiftReport;
use App\Filament\Pages\Reports\PeriodShiftReport;
use App\Filament\Widgets\CurrentShiftExpensesDetailsTable;
use App\Filament\Widgets\CurrentShiftMoneyInfoStats;
use App\Filament\Widgets\CurrentShiftOrdersTable;
use App\Filament\Widgets\PeriodShiftOrdersTable;
use App\Models\ExpenceType;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Terminal;
use App\Models\TerminalSession;
use App\Models\User;
use App\Services\Reports\ReportTerminalScope;
use App\Services\SettingsService;
use App\Services\ShiftService;
use App\Services\ShiftsReportService;
use App\Terminals\TerminalSessionResolver;
use App\Terminals\TerminalSessionService;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    actingAs($this->admin);
    openInventoryDay();

    app(SettingsService::class)->set(SettingKey::FEATURE_MULTI_TERMINAL_ENABLED->value, true);
    $this->front = Terminal::query()->create(['name' => 'منفذ ١']);
    $this->back = Terminal::query()->create(['name' => 'منفذ ٢']);

    $this->shift = app(ShiftService::class)->startShift(100, $this->admin, $this->front);
    $this->frontSession = $this->shift->terminalSessions()->sole();
    $this->backSession = app(TerminalSessionService::class)
        ->openDrawer($this->back, User::factory()->cashier()->create(), 50);
    app(TerminalSessionResolver::class)->flush();
});

function sellOn(TerminalSession $session, float $total): Order
{
    $resolver = app(TerminalSessionResolver::class);
    $resolver->setCurrent($session);
    $order = Order::factory()->create([
        'shift_id' => $session->shift_id,
        'terminal_id' => $session->terminal_id,
        'status' => OrderStatus::COMPLETED,
        'total' => $total,
        'profit' => $total / 2,
        'discount' => 0,
    ]);
    Payment::query()->create([
        'order_id' => $order->id,
        'shift_id' => $session->shift_id,
        'amount' => $total,
        'method' => PaymentMethod::CASH->value,
        'paid_at' => now(),
    ]);
    $resolver->setCurrent(null);
    $resolver->flush();

    return $order;
}

it('offers «الكل» plus a tab per sales point that worked in the shift', function () {
    expect(ReportTerminalScope::tabs([$this->shift->id]))->toBe([
        'all' => 'الكل',
        (string) $this->front->id => 'منفذ ١',
        (string) $this->back->id => 'منفذ ٢',
    ]);

    // Feature off: no tabs, and a stale filter value is ignored.
    Setting::query()->updateOrCreate(['key' => SettingKey::FEATURE_MULTI_TERMINAL_ENABLED->value], ['value' => 'false']);
    Setting::invalidateCache(SettingKey::FEATURE_MULTI_TERMINAL_ENABLED->value);

    $scope = app(ReportTerminalScope::class);
    $scope->setFromFilters(['terminal' => (string) $this->back->id]);

    expect(ReportTerminalScope::tabs([$this->shift->id]))->toBe([])
        ->and($scope->id())->toBeNull();
});

it('shows no tabs with a single sales point', function () {
    $this->backSession->delete();

    expect(ReportTerminalScope::tabs([$this->shift->id]))->toBe([]);
});

it('splits the shift numbers by sales point and keeps the whole shift on «الكل»', function () {
    sellOn($this->frontSession, 100);
    sellOn($this->backSession, 40);
    $type = ExpenceType::query()->create(['name' => 'نثريات']);
    Expense::query()->create(['shift_id' => $this->shift->id, 'expence_type_id' => $type->id, 'amount' => 15, 'terminal_session_id' => $this->backSession->id]);

    $service = app(ShiftsReportService::class);
    $scope = app(ReportTerminalScope::class);

    $all = $service->calculateShiftStats($this->shift->fresh());
    expect((float) $all['sales'])->toBe(140.0)
        ->and((float) $all['expenses'])->toBe(15.0)
        ->and((float) $all['availableCash'])->toBe(150.0 + 140.0 - 15.0);

    $scope->set($this->back->id);
    $back = $service->calculateShiftStats($this->shift->fresh());
    expect((float) $back['sales'])->toBe(40.0)
        ->and((float) $back['cashPayments'])->toBe(40.0)
        ->and((float) $back['expenses'])->toBe(15.0)
        ->and((float) $back['availableCash'])->toBe(50.0 + 40.0 - 15.0);

    $period = $service->calculatePeriodStats(null, null, [$this->shift->id]);
    expect((float) $period['sales'])->toBe(40.0)
        ->and((float) $period['availableCash'])->toBe(75.0);

    $scope->set($this->front->id);
    $front = $service->calculatePeriodStats(null, null, [$this->shift->id]);
    expect((float) $front['sales'])->toBe(100.0)
        ->and((float) $front['expenses'])->toBe(0.0)
        ->and((float) $front['availableCash'])->toBe(200.0)
        ->and($service->calculatePeriodOrderStats(null, null, [$this->shift->id])[OrderStatus::COMPLETED->value]['count'])->toBe(1);
});

it('narrows the report widgets to the picked tab', function () {
    $frontOrder = sellOn($this->frontSession, 100);
    $backOrder = sellOn($this->backSession, 40);
    $type = ExpenceType::query()->create(['name' => 'نثريات']);
    $frontExpense = Expense::query()->create(['shift_id' => $this->shift->id, 'expence_type_id' => $type->id, 'amount' => 5, 'terminal_session_id' => $this->frontSession->id]);
    $backExpense = Expense::query()->create(['shift_id' => $this->shift->id, 'expence_type_id' => $type->id, 'amount' => 15, 'terminal_session_id' => $this->backSession->id]);

    livewire(CurrentShiftOrdersTable::class, ['pageFilters' => ['terminal' => (string) $this->back->id]])
        ->assertCanSeeTableRecords([$backOrder])
        ->assertCanNotSeeTableRecords([$frontOrder]);

    livewire(CurrentShiftOrdersTable::class, ['pageFilters' => ['terminal' => 'all']])
        ->assertCanSeeTableRecords([$backOrder, $frontOrder]);

    livewire(CurrentShiftExpensesDetailsTable::class, ['pageFilters' => ['terminal' => (string) $this->front->id]])
        ->assertCanSeeTableRecords([$frontExpense])
        ->assertCanNotSeeTableRecords([$backExpense]);

    livewire(PeriodShiftOrdersTable::class, ['pageFilters' => ['filterType' => 'shifts', 'shifts' => [$this->shift->id], 'terminal' => (string) $this->front->id]])
        ->assertCanSeeTableRecords([$frontOrder])
        ->assertCanNotSeeTableRecords([$backOrder]);

    livewire(CurrentShiftMoneyInfoStats::class, ['pageFilters' => ['terminal' => (string) $this->back->id]])
        ->assertSee('40.00');
});

it('renders the tabs on both reports', function () {
    livewire(CurrentShiftReport::class)->assertOk()->assertSee('الكل')->assertSee('منفذ ٢');
    livewire(PeriodShiftReport::class)->assertOk()->assertSee('الكل')->assertSee('منفذ ٢');
});
