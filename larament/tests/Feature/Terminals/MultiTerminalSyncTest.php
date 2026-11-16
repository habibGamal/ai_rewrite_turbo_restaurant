<?php

use App\Models\ExpenceType;
use App\Models\Expense;
use App\Models\Order;
use App\Models\SyncOutbox;
use App\Models\Terminal;
use App\Models\User;
use App\Services\ShiftService;
use App\Sync\Builders\ExpenseBuilder;
use App\Sync\Builders\OrderBuilder;
use App\Sync\Builders\ShiftBuilder;
use App\Sync\DocumentType;
use App\Sync\Payload;
use App\Terminals\TerminalSessionService;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    actingAs($this->admin);
    openInventoryDay();
    $this->front = Terminal::query()->create(['name' => 'كاشير ١']);
    $this->back = Terminal::query()->create(['name' => 'كاشير ٢']);
});

it('sends the shift drawers with their handovers in the shift document', function () {
    $shift = app(ShiftService::class)->startShift(100, $this->admin, $this->front);
    $cashier = User::factory()->cashier()->create();
    $back = app(TerminalSessionService::class)->openDrawer($this->back, $cashier, 50);
    $next = User::factory()->cashier()->create();
    app(TerminalSessionService::class)->handover($back, $next, 'نص الوردية');
    app(TerminalSessionService::class)->close($back->fresh(), $next, 'آخر اليوم');

    $payload = app(ShiftBuilder::class)->build($shift->uuid);
    $drawers = collect($payload['terminal_sessions']);

    expect($drawers)->toHaveCount(2)
        ->and($drawers[0]['terminal_name'])->toBe('كاشير ١')
        ->and($drawers[0]['closed_at'])->toBeNull()
        ->and($drawers[1]['uuid'])->toBe($back->uuid)
        ->and($drawers[1]['user_uuid'])->toBe($next->uuid)
        ->and($drawers[1]['closed_by_uuid'])->toBe($next->uuid)
        ->and($drawers[1]['close_note'])->toBe('آخر اليوم')
        ->and($drawers[1]['opening_cash'])->toBe(Payload_money(50))
        ->and($drawers[1]['handovers'])->toHaveCount(1)
        ->and($drawers[1]['handovers'][0]['from_user_uuid'])->toBe($cashier->uuid)
        ->and($drawers[1]['handovers'][0]['to_user_uuid'])->toBe($next->uuid)
        ->and($drawers[1]['handovers'][0]['note'])->toBe('نص الوردية');
});

it('names the drawer session on money rows and the drawer on orders', function () {
    $shift = app(ShiftService::class)->startShift(0, $this->admin, $this->front);
    $session = $shift->terminalSessions()->sole();
    $type = ExpenceType::query()->create(['name' => 'نثريات']);

    $expense = Expense::query()->create([
        'shift_id' => $shift->id,
        'expence_type_id' => $type->id,
        'amount' => 10,
        'terminal_session_id' => $session->id,
    ]);
    $order = Order::factory()->create(['shift_id' => $shift->id, 'terminal_id' => $this->front->id]);

    expect(app(ExpenseBuilder::class)->build($expense->uuid)['terminal_session_uuid'])->toBe($session->uuid)
        ->and(app(OrderBuilder::class)->build($order->uuid)['terminal_name'])->toBe('كاشير ١');
});

it('re-sends the shift when a drawer opens or closes', function () {
    $shift = app(ShiftService::class)->startShift(0, $this->admin, $this->front);
    pairPlatform();
    SyncOutbox::query()->delete();

    $session = $shift->terminalSessions()->sole();
    app(TerminalSessionService::class)->close($session, $this->admin);

    expect(SyncOutbox::query()->where('doc_type', DocumentType::Shift->value)->where('doc_uuid', $shift->uuid)->count())->toBe(1);
});

function Payload_money(float $value): mixed
{
    return Payload::money($value);
}
