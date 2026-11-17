<?php

use App\Enums\OptionalFeature;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\RevenueMethod;
use App\Models\ExpenceType;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Revenue;
use App\Models\RevenueType;
use App\Models\Shift;
use App\Models\Terminal;
use App\Models\TerminalSession;
use App\Models\User;
use App\Services\FeatureService;
use App\Services\ShiftMoneyService;
use App\Services\ShiftService;
use App\Services\ShiftsReportService;
use App\Terminals\TerminalSessionResolver;
use App\Terminals\TerminalSessionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->user = User::factory()->admin()->create();
    actingAs($this->user);
});

describe('drawer sessions while multiple terminals are off', function () {
    it('creates the hidden default drawer once', function () {
        $first = Terminal::default();
        $second = Terminal::default();

        expect($first->is($second))->toBeTrue()
            ->and($first->is_default)->toBeTrue()
            ->and(Terminal::query()->where('is_default', true)->count())->toBe(1);
    });

    it('opens one default session when the shift starts', function () {
        openInventoryDay();

        $shift = app(ShiftService::class)->startShift(250);
        $sessions = $shift->terminalSessions()->get();

        expect($sessions)->toHaveCount(1)
            ->and($sessions->first()->terminal->is_default)->toBeTrue()
            ->and((float) $sessions->first()->opening_cash)->toBe(250.0)
            ->and($sessions->first()->user_id)->toBe($this->user->id)
            ->and($sessions->first()->isOpen())->toBeTrue();
    });

    it('stamps every money row and the order with the shift drawer', function () {
        openInventoryDay();
        $shift = app(ShiftService::class)->startShift(100);
        $session = $shift->terminalSessions()->sole();

        $order = Order::factory()->create(['shift_id' => $shift->id]);
        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'shift_id' => $shift->id,
            'amount' => 40,
            'method' => PaymentMethod::CASH,
        ]);
        $expense = Expense::factory()->create(['shift_id' => $shift->id, 'expence_type_id' => ExpenceType::factory()->create()->id, 'amount' => 10]);
        $revenue = Revenue::factory()->create(['shift_id' => $shift->id, 'amount' => 5, 'method' => RevenueMethod::CASH]);

        expect($order->terminal_id)->toBe($session->terminal_id)
            ->and($payment->terminal_session_id)->toBe($session->id)
            ->and($expense->terminal_session_id)->toBe($session->id)
            ->and($revenue->terminal_session_id)->toBe($session->id);
    });

    it('keeps a drawer session given explicitly', function () {
        $shift = Shift::factory()->active()->create();
        $other = TerminalSession::query()->create([
            'shift_id' => $shift->id,
            'terminal_id' => Terminal::query()->create(['name' => 'كاشير ٢'])->id,
            'opening_cash' => 0,
            'opened_at' => now(),
        ]);

        $expense = Expense::factory()->create([
            'shift_id' => $shift->id,
            'expence_type_id' => ExpenceType::factory()->create()->id,
            'terminal_session_id' => $other->id,
        ]);

        expect($expense->terminal_session_id)->toBe($other->id);
    });

    it('uses the current drawer of the device when one is set', function () {
        $shift = Shift::factory()->active()->create();
        $default = app(TerminalSessionService::class)->openDefault($shift);
        $lane2 = TerminalSession::query()->create([
            'shift_id' => $shift->id,
            'terminal_id' => Terminal::query()->create(['name' => 'كاشير ٢'])->id,
            'opening_cash' => 0,
            'opened_at' => now(),
        ]);

        $resolver = app(TerminalSessionResolver::class);
        expect($resolver->forShift($shift->id)->id)->toBe($default->id);

        $resolver->setCurrent($lane2);
        $expense = Expense::factory()->create(['shift_id' => $shift->id, 'expence_type_id' => ExpenceType::factory()->create()->id]);

        expect($expense->terminal_session_id)->toBe($lane2->id);
    });

    it('opens a default session on demand for a shift that has none', function () {
        $shift = Shift::factory()->active()->create(['start_cash' => 70]);

        $expense = Expense::factory()->create(['shift_id' => $shift->id, 'expence_type_id' => ExpenceType::factory()->create()->id]);

        $session = TerminalSession::query()->where('shift_id', $shift->id)->sole();
        expect($expense->terminal_session_id)->toBe($session->id)
            ->and((float) $session->opening_cash)->toBe(70.0)
            ->and($session->isOpen())->toBeTrue();
    });

    it('closes the drawer with the same expected cash as the shift', function () {
        openInventoryDay();
        $shift = app(ShiftService::class)->startShift(100);
        $order = Order::factory()->create(['shift_id' => $shift->id, 'status' => OrderStatus::COMPLETED]);
        Payment::query()->create(['order_id' => $order->id, 'shift_id' => $shift->id, 'amount' => 60, 'method' => PaymentMethod::CASH]);
        Payment::query()->create(['order_id' => $order->id, 'shift_id' => $shift->id, 'amount' => 30, 'method' => PaymentMethod::CARD]);
        Expense::factory()->create(['shift_id' => $shift->id, 'expence_type_id' => ExpenceType::factory()->create()->id, 'amount' => 25]);
        Revenue::factory()->create(['shift_id' => $shift->id, 'amount' => 15, 'method' => RevenueMethod::CASH]);

        $closed = app(ShiftService::class)->endShift(0);
        $session = $closed->terminalSessions()->sole();

        expect((float) $session->expected_cash)->toBe(150.0)
            ->and((float) $closed->end_cash)->toBe(150.0)
            ->and($session->isOpen())->toBeFalse()
            ->and($session->closed_by)->toBe($this->user->id);
    });

    it('refreshes the drawer expected cash when a closed shift is edited', function () {
        $shift = Shift::factory()->create([
            'start_cash' => 100,
            'closed' => true,
            'start_at' => now()->subHours(8),
            'end_at' => now()->subHour(),
            'business_date' => now()->toDateString(),
            'end_cash' => 100,
        ]);
        $session = app(TerminalSessionService::class)->openDefault($shift);
        expect($session->isOpen())->toBeFalse();

        app(FeatureService::class)->enable(OptionalFeature::CLOSED_SHIFT_EDITS);
        app(ShiftMoneyService::class)->addRevenue($shift, [
            'revenue_type_id' => RevenueType::factory()->create()->id,
            'amount' => 40,
            'method' => RevenueMethod::CASH->value,
        ], 'إيراد متأخر');

        expect((float) $session->fresh()->expected_cash)->toBe(140.0)
            ->and((float) $shift->fresh()->end_cash)->toBe(140.0)
            ->and((float) app(ShiftsReportService::class)->calculateShiftStats($shift->fresh())['availableCash'])->toBe(140.0);
    });
});

describe('backfill of shifts made before drawers', function () {
    it('gives each old shift one session and points its money rows at it', function () {
        $shift = Shift::factory()->create([
            'start_cash' => 90,
            'closed' => true,
            'start_at' => now()->subHours(5),
            'end_at' => now()->subHour(),
            'end_cash' => 300,
        ]);
        $type = ExpenceType::factory()->create();
        DB::table('expenses')->insert([
            'uuid' => (string) Str::uuid(),
            'shift_id' => $shift->id,
            'expence_type_id' => $type->id,
            'amount' => 12,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_11_28_000001_create_terminals_tables.php');
        (fn () => $this->backfill())->call($migration);

        $session = TerminalSession::query()->where('shift_id', $shift->id)->sole();
        expect((float) $session->opening_cash)->toBe(90.0)
            ->and((float) $session->expected_cash)->toBe(300.0)
            ->and($session->isOpen())->toBeFalse()
            ->and($session->terminal->is_default)->toBeTrue()
            ->and(DB::table('expenses')->where('shift_id', $shift->id)->value('terminal_session_id'))->toBe($session->id);
    });
});
