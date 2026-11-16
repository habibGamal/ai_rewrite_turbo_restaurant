<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Filament\Widgets\CurrentShiftCreditCollectionsTable;
use App\Filament\Widgets\CurrentShiftExpensesDetailsTable;
use App\Filament\Widgets\CurrentShiftMoneyInfoStats;
use App\Filament\Widgets\CurrentShiftOrdersTable;
use App\Filament\Widgets\PeriodShiftCreditCollectionsTable;
use App\Filament\Widgets\PeriodShiftExpensesDetailsTable;
use App\Filament\Widgets\PeriodShiftMoneyInfoStats;
use App\Filament\Widgets\PeriodShiftOrdersTable;
use App\Filament\Widgets\Returns\CurrentShiftReturnsTable;
use App\Filament\Widgets\Returns\ShiftReturnsTable;
use App\Models\Customer;
use App\Models\ExpenceType;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Orders\Tenders;
use App\Services\Orders\OrderService;
use App\Services\ShiftService;
use App\Services\ShiftsReportService;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

function payLaterOrder(Shift $shift, User $user, ?Customer $customer, string $type = 'companies', float $total = 100): Order
{
    $product = Product::factory()->create(['price' => $total, 'cost' => 40, 'type' => 'manufactured']);

    $order = Order::factory()->create([
        'type' => $type,
        'status' => OrderStatus::PROCESSING,
        'payment_status' => PaymentStatus::PENDING,
        'shift_id' => $shift->id,
        'user_id' => $user->id,
        'customer_id' => $customer?->id,
        'sub_total' => $total,
        'service' => 0,
        'tax' => 0,
        'discount' => 0,
        'temp_discount_percent' => 0,
        'total' => $total,
        'profit' => 60,
    ]);

    $order->items()->create([
        'product_id' => $product->id,
        'quantity' => 1,
        'price' => $total,
        'cost' => 40,
        'total' => $total,
    ]);

    return $order;
}

/**
 * sales − uncollected credit + earlier-shift collections must equal the money received.
 *
 * @param  array<string, mixed>  $stats
 */
function expectReconciles(array $stats): void
{
    $received = (float) $stats['cashPayments'] + (float) $stats['cardPayments'] + (float) $stats['talabatCardPayments'];

    expect(round((float) $stats['sales'] - $stats['uncollectedCredit'] + $stats['previousCollections'], 2))
        ->toBe(round($received, 2));
}

describe('pay-later order of 100: 50 in shift 1, 50 in shift 2', function () {
    beforeEach(function () {
        $this->user = User::factory()->admin()->create();
        actingAs($this->user);

        $this->customer = Customer::factory()->create(['name' => 'شركة النيل', 'phone' => '01000000000']);
        $this->orders = app(OrderService::class);
        $this->shifts = app(ShiftService::class);
        $this->reports = app(ShiftsReportService::class);

        $this->shift1 = Shift::factory()->active()->create(['user_id' => $this->user->id, 'start_cash' => 200]);
        $this->order = payLaterOrder($this->shift1, $this->user, $this->customer);
        $this->orders->completeOrder($this->order->id, ['cash' => 50]);
    });

    it('closes shift 1 expecting only the 50 actually received, with no false shortage', function () {
        $closed = $this->shifts->endShift(250);

        expect((float) $closed->end_cash)->toBe(250.0)
            ->and((float) $closed->losses_amount)->toBe(0.0)
            ->and($closed->has_deficit)->toBeFalse();
    });

    it('closes shift 2 counting the 50 it collected', function () {
        $this->shifts->endShift(250);
        $shift2 = Shift::factory()->active()->create(['user_id' => $this->user->id, 'start_cash' => 100]);
        $this->orders->collectRemaining($this->order->id, Tenders::fromArray(['cash' => 50]));

        $closed = $this->shifts->endShift(150);

        expect($closed->id)->toBe($shift2->id)
            ->and((float) $closed->end_cash)->toBe(150.0)
            ->and($closed->has_deficit)->toBeFalse();
    });

    it('reconciles each shift and the whole period', function () {
        $this->shifts->endShift(250);
        $shift2 = Shift::factory()->active()->create(['user_id' => $this->user->id]);
        $this->orders->collectRemaining($this->order->id, Tenders::fromArray(['card' => 50]));

        $s1 = $this->reports->calculateShiftStats($this->shift1->fresh());
        $s2 = $this->reports->calculateShiftStats($shift2->fresh());

        expect((float) $s1['sales'])->toBe(100.0)
            ->and($s1['uncollectedCredit'])->toBe(50.0)
            ->and($s1['uncollectedCreditCount'])->toBe(1)
            ->and($s1['previousCollections'])->toBe(0.0)
            ->and((float) $s2['sales'])->toBe(0.0)
            ->and($s2['uncollectedCredit'])->toBe(0.0)
            ->and($s2['previousCollections'])->toBe(50.0)
            ->and($s2['previousCollectionsCount'])->toBe(1)
            ->and((float) $s2['cardPayments'])->toBe(50.0);

        expectReconciles($s1);
        expectReconciles($s2);

        // Both shifts together: the collection happened inside the period, so nothing is outstanding.
        $period = $this->reports->calculatePeriodStats(null, null, [$this->shift1->id, $shift2->id]);

        expect($period['uncollectedCredit'])->toBe(0.0)
            ->and($period['previousCollections'])->toBe(0.0);
        expectReconciles($period);

        // Shift 2 alone in the period report: the 50 is an earlier-shift collection.
        expectReconciles($this->reports->calculatePeriodStats(null, null, [$shift2->id]));
    });

    it('shows shift 1 orders table as 50 paid and 50 uncollected even after shift 2 collected', function () {
        $this->shifts->endShift(250);
        Shift::factory()->active()->create(['user_id' => $this->user->id]);
        $this->orders->collectRemaining($this->order->id, Tenders::fromArray(['cash' => 50]));

        livewire(PeriodShiftOrdersTable::class, ['pageFilters' => ['filterType' => 'shifts', 'shifts' => [$this->shift1->id]]])
            ->assertCanSeeTableRecords([$this->order])
            ->assertTableColumnStateSet('paid_in_scope', 50, (string) $this->order->getKey())
            ->assertTableColumnStateSet('uncollected_in_scope', 50, (string) $this->order->getKey())
            ->assertTableColumnStateSet('cash', 50, (string) $this->order->getKey())
            ->assertTableColumnStateSet('payment_status', PaymentStatus::FULL_PAID, (string) $this->order->getKey())
            ->filterTable('has_credit', true)
            ->assertCanSeeTableRecords([$this->order]);
    });

    it('lists the shift 2 collection in the credit collections table', function () {
        $this->shifts->endShift(250);
        $shift2 = Shift::factory()->active()->create(['user_id' => $this->user->id]);
        $this->orders->collectRemaining($this->order->id, Tenders::fromArray(['cash' => 50]));

        $collection = Payment::query()->where('order_id', $this->order->id)->where('shift_id', $shift2->id)->firstOrFail();
        $firstPayment = Payment::query()->where('order_id', $this->order->id)->where('shift_id', $this->shift1->id)->firstOrFail();

        livewire(CurrentShiftCreditCollectionsTable::class)
            ->assertCanSeeTableRecords([$collection])
            ->assertCanNotSeeTableRecords([$firstPayment])
            ->assertTableColumnStateSet('remaining_now', 0, (string) $collection->getKey());

        livewire(PeriodShiftCreditCollectionsTable::class, ['pageFilters' => ['filterType' => 'shifts', 'shifts' => [$shift2->id]]])
            ->assertCanSeeTableRecords([$collection]);

        // A period covering both shifts has no "other shift" collections.
        livewire(PeriodShiftCreditCollectionsTable::class, ['pageFilters' => ['filterType' => 'shifts', 'shifts' => [$this->shift1->id, $shift2->id]]])
            ->assertCanNotSeeTableRecords([$collection]);
    });

    it('points every card scroll at a table id that exists on the report', function () {
        $filters = ['pageFilters' => ['filterType' => 'shifts', 'shifts' => [$this->shift1->id]]];

        $pages = [
            'current' => [
                'cards' => [livewire(CurrentShiftMoneyInfoStats::class)->html()],
                'tables' => [CurrentShiftOrdersTable::class, CurrentShiftReturnsTable::class, CurrentShiftCreditCollectionsTable::class, CurrentShiftExpensesDetailsTable::class],
                'params' => [],
            ],
            'period' => [
                'cards' => [livewire(PeriodShiftMoneyInfoStats::class, $filters)->html()],
                'tables' => [PeriodShiftOrdersTable::class, ShiftReturnsTable::class, PeriodShiftCreditCollectionsTable::class, PeriodShiftExpensesDetailsTable::class],
                'params' => $filters,
            ],
        ];

        foreach ($pages as $name => $page) {
            $tablesHtml = implode('', array_map(fn (string $table): string => livewire($table, $page['params'])->html(), $page['tables']));

            preg_match_all("/getElementById\\(&#039;([a-z_]+)&#039;\\)|getElementById\\('([a-z_]+)'\\)/", implode('', $page['cards']), $matches);
            $targets = array_unique(array_filter([...$matches[1], ...$matches[2]]));

            expect($targets)->toContain('orders_table', 'credit_collections_table', 'expenses_table');

            foreach ($targets as $id) {
                expect(str_contains($tablesHtml, 'id="'.$id.'"'))->toBeTrue("{$name} report card scrolls to missing #{$id}");
            }

            // Scroll-only cards must not go through wire:click (Livewire would run `$wire.document…`).
            expect(implode('', $page['cards']))->not->toMatch('/wire:click="\s*document\./');
        }
    });

    it('does not show the earlier order in the current shift orders table', function () {
        $this->shifts->endShift(250);
        Shift::factory()->active()->create(['user_id' => $this->user->id]);
        $this->orders->collectRemaining($this->order->id, Tenders::fromArray(['cash' => 50]));

        livewire(CurrentShiftOrdersTable::class)
            ->assertCanNotSeeTableRecords([$this->order]);
    });
});

describe('shift close expected cash', function () {
    beforeEach(function () {
        $this->user = User::factory()->admin()->create();
        actingAs($this->user);
        $this->shift = Shift::factory()->active()->create(['user_id' => $this->user->id, 'start_cash' => 100]);
    });

    it('counts only the cash part of a split payment and subtracts expenses', function () {
        $order = payLaterOrder($this->shift, $this->user, null, 'takeaway');
        app(OrderService::class)->completeOrder($order->id, ['cash' => 60, 'card' => 40]);

        Expense::factory()->create([
            'shift_id' => $this->shift->id,
            'expence_type_id' => ExpenceType::factory()->create()->id,
            'amount' => 10,
        ]);

        $closed = app(ShiftService::class)->endShift(140);

        // 100 opening + 60 cash − 10 expense
        expect((float) $closed->end_cash)->toBe(150.0)
            ->and((float) $closed->losses_amount)->toBe(10.0)
            ->and($closed->has_deficit)->toBeTrue();
    });

    it('records a surplus as a negative difference', function () {
        $closed = app(ShiftService::class)->endShift(120);

        expect((float) $closed->end_cash)->toBe(100.0)
            ->and((float) $closed->losses_amount)->toBe(-20.0)
            ->and($closed->has_deficit)->toBeFalse();

        expect(Payment::query()->where('method', PaymentMethod::CASH)->count())->toBe(0);
    });
});
