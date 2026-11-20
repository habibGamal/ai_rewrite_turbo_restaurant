<?php

use App\Enums\PaymentMethod;
use App\Enums\SettingKey;
use App\Filament\Pages\Reports\CurrentShiftReport;
use App\Filament\Widgets\ShiftMoney\ShiftMoneyExpensesTable;
use App\Filament\Widgets\Terminals\CurrentShiftDrawersTable;
use App\Filament\Widgets\Terminals\PeriodShiftDrawersTable;
use App\Models\ExpenceType;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shift;
use App\Models\Terminal;
use App\Models\TerminalSession;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\ShiftMoneyService;
use App\Services\ShiftService;
use App\Terminals\TerminalSessionResolver;
use App\Terminals\TerminalSessionService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    actingAs($this->admin);
    openInventoryDay();

    app(SettingsService::class)->set(SettingKey::FEATURE_MULTI_TERMINAL_ENABLED->value, true);
    $this->front = Terminal::query()->create(['name' => 'كاشير ١']);
    $this->back = Terminal::query()->create(['name' => 'كاشير ٢']);

    $this->shift = app(ShiftService::class)->startShift(100, $this->admin, $this->front);
    $this->frontSession = $this->shift->terminalSessions()->sole();
    $this->backSession = app(TerminalSessionService::class)
        ->openDrawer($this->back, User::factory()->cashier()->create(), 50);
    $this->type = ExpenceType::query()->create(['name' => 'نثريات']);
});

function takeCash(TerminalSession $session, float $amount): void
{
    $resolver = app(TerminalSessionResolver::class);
    $resolver->setCurrent($session);
    $order = Order::factory()->create(['shift_id' => $session->shift_id]);
    Payment::query()->create([
        'order_id' => $order->id,
        'shift_id' => $session->shift_id,
        'amount' => $amount,
        'method' => PaymentMethod::CASH->value,
        'paid_at' => now(),
    ]);
    $resolver->flush();
}

it('breaks a drawer cash down into in, out and expected', function () {
    takeCash($this->backSession, 80);
    Expense::query()->create([
        'shift_id' => $this->shift->id,
        'expence_type_id' => $this->type->id,
        'amount' => 30,
        'terminal_session_id' => $this->backSession->id,
    ]);

    $row = app(TerminalSessionService::class)->breakdown($this->backSession);

    expect($row['opening'])->toBe(50.0)
        ->and($row['cash_in'])->toBe(80.0)
        ->and($row['cash_out'])->toBe(30.0)
        ->and($row['expected'])->toBe(100.0)
        ->and(app(TerminalSessionService::class)->breakdown($this->frontSession)['expected'])->toBe(100.0);
});

it('lists the shift drawers on the current shift report', function () {
    $page = new CurrentShiftReport;
    $page->boot();
    expect($page->getWidgets())->toContain(CurrentShiftDrawersTable::class);

    takeCash($this->backSession, 80);

    livewire(CurrentShiftDrawersTable::class)
        ->assertCanSeeTableRecords([$this->frontSession, $this->backSession])
        ->assertTableColumnStateSet('expected', 130.0, $this->backSession)
        ->assertTableColumnStateSet('expected', 100.0, $this->frontSession);
});

it('lists the drawers of picked shifts on the period report', function () {
    $other = Shift::factory()->closed()->create();
    $otherSession = app(TerminalSessionService::class)->openDefault($other);

    livewire(PeriodShiftDrawersTable::class, ['pageFilters' => ['filterType' => 'shifts', 'shifts' => [$this->shift->id]]])
        ->assertCanSeeTableRecords([$this->frontSession, $this->backSession])
        ->assertCanNotSeeTableRecords([$otherSession]);
});

it('hides the drawers table while the feature is off', function () {
    app(TerminalSessionService::class)->closeAllOpen($this->shift, $this->admin);
    app(ShiftService::class)->endShift(0);
    app(SettingsService::class)->set(SettingKey::FEATURE_MULTI_TERMINAL_ENABLED->value, false);

    expect(CurrentShiftDrawersTable::canView())->toBeFalse();
});

it('makes the admin pick the drawer for an expense on the shift money page', function () {
    livewire(ShiftMoneyExpensesTable::class, ['pageFilters' => ['shift_id' => $this->shift->id]])
        ->callAction(TestAction::make('addExpense')->table(), [
            'expence_type_id' => $this->type->id,
            'amount' => 20,
        ])
        ->assertHasFormErrors(['terminal_session_id' => 'required']);

    livewire(ShiftMoneyExpensesTable::class, ['pageFilters' => ['shift_id' => $this->shift->id]])
        ->callAction(TestAction::make('addExpense')->table(), [
            'expence_type_id' => $this->type->id,
            'amount' => 20,
            'terminal_session_id' => $this->backSession->id,
        ])
        ->assertHasNoFormErrors();

    expect(Expense::query()->sole()->terminal_session_id)->toBe($this->backSession->id);
});

it('refuses a drawer from another shift', function () {
    $other = Shift::factory()->closed()->create();
    $otherSession = app(TerminalSessionService::class)->openDefault($other);

    expect(fn () => app(ShiftMoneyService::class)->addExpense($this->shift, [
        'expence_type_id' => $this->type->id,
        'amount' => 5,
        'terminal_session_id' => $otherSession->id,
    ]))->toThrow(ValidationException::class);
});
