<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Filament\Pages\Reports\CurrentShiftReport;
use App\Filament\Pages\Reports\PeriodShiftReport;
use App\Models\Shift;
use App\Services\FeatureService;
use App\Services\ShiftsReportService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('shifts')]
#[Title('Shifts and cash')]
#[Description(<<<'TEXT'
Shifts (cashier sessions) with their money: who opened them, when, sales, profit, expenses, cash and card payments, refunds, expected cash in the drawer, counted cash and the difference. Give shift_id for one shift in detail, or a period for a list (newest first) with totals.
Use for "is a shift open now", "how much cash should be in the drawer", "which shifts had a cash shortage", "sales of yesterday's evening shift".
Not for sales by channel or day (use sales_breakdown) or expenses by type (use expenses_revenues).
Examples: «كم النقدية المتوقعة في الدرج الآن؟», «ما الورديات التي فيها عجز هذا الشهر؟», "Show the last 5 shifts".
TEXT)]
#[IsReadOnly]
class ShiftsTool extends BusinessTool
{
    private const MAX_LIST = 31;

    private readonly ShiftsReportService $shifts;

    private readonly FeatureService $features;

    /** Services come from the container when the tool is built without them. */
    public function __construct(?ShiftsReportService $shifts = null, ?FeatureService $features = null)
    {
        $this->shifts = $shifts ?? app(ShiftsReportService::class);
        $this->features = $features ?? app(FeatureService::class);
    }

    protected function reportPages(): array
    {
        return [PeriodShiftReport::class, CurrentShiftReport::class];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'shift_id' => $schema->integer()->description('One shift in detail. Use "open" in status to find the open shift instead.'),
            'status' => $schema->string()->enum(['any', 'open', 'closed'])->description('Default any.'),
            ...$this->periodSchema($schema, compare: false, default: 'last_7_days'),
            'limit' => $schema->integer()->description('Shifts to list, newest first. Default 10, max '.self::MAX_LIST.'.'),
        ];
    }

    protected function run(Request $request): array
    {
        if ($request->get('shift_id') !== null) {
            $shift = Shift::query()->with('user')->find((int) $request->get('shift_id'));
            if ($shift === null) {
                throw new InvalidArgumentException('No shift with that id.');
            }

            return $this->envelope(null, ['shift' => $this->detail($shift)], $this->definitions(), $this->caveats());
        }

        $status = (string) ($request->get('status') ?? 'any');
        if ($status === 'open') {
            $open = Shift::query()->with('user')->where('closed', false)->orderByDesc('start_at')->get();

            return $this->envelope(null, [
                'open_shifts' => $open->map(fn (Shift $shift) => $this->detail($shift))->all(),
            ], $this->definitions(), $open->isEmpty() ? ['No shift is open now.'] : $this->caveats());
        }

        $period = $this->period($request, 'last_7_days', 'none');
        $from = $period->from->toDateString();
        $to = $period->to->toDateString();
        $limit = min(self::MAX_LIST, $this->limit($request));

        $query = Shift::query()->with('user')
            ->whereIn('id', $this->shifts->shiftIdsQuery($from, $to))
            ->when($status === 'closed', fn ($q) => $q->where('closed', true));
        $count = (clone $query)->count();
        $rows = $query->orderByDesc('start_at')->limit($limit)->get()
            ->map(fn (Shift $shift) => $this->row($shift))
            ->all();

        return $this->envelope($period, [
            'shifts_in_period' => $count,
            'truncated' => $count > count($rows),
            'totals' => $this->totals($this->shifts->calculatePeriodStats($from, $to)),
            'shifts' => $rows,
        ], $this->definitions(), $this->caveats(), $status === 'any' ? [] : ['status' => $status]);
    }

    /** @return array<string, mixed> */
    private function row(Shift $shift): array
    {
        $stats = $this->shifts->calculateShiftStats($shift);

        return [
            ...$this->header($shift),
            'net_sales' => round((float) $stats['netSales'], 2),
            'net_profit' => round((float) $stats['netProfit'], 2),
            'expenses' => round((float) $stats['expenses'], 2),
            ...$this->cash($shift, $stats),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(Shift $shift): array
    {
        $stats = $this->shifts->calculateShiftStats($shift);
        $statuses = [];
        foreach ($this->shifts->calculateOrderStats($shift) as $status => $row) {
            $statuses[$status] = ['count' => (int) $row['count'], 'value' => round((float) $row['value'], 2)];
        }

        return [
            ...$this->header($shift),
            ...$this->totals($stats),
            ...$this->cash($shift, $stats),
            'orders_by_status' => $statuses,
        ];
    }

    /** @return array<string, mixed> */
    private function header(Shift $shift): array
    {
        return [
            'shift_id' => $shift->id,
            'opened_by' => $shift->user?->getAttribute('name'),
            'business_date' => $shift->business_date?->toDateString(),
            'start_at' => $shift->start_at?->format('Y-m-d H:i'),
            'end_at' => $shift->end_at?->format('Y-m-d H:i'),
            'open' => ! $shift->closed,
            'start_cash' => round((float) $shift->start_cash, 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $stats
     * @return array<string, mixed>
     */
    private function cash(Shift $shift, array $stats): array
    {
        $expected = $shift->closed && $shift->end_cash !== null ? (float) $shift->end_cash : (float) $stats['availableCash'];
        $counted = $shift->real_cash !== null ? (float) $shift->real_cash : null;

        return [
            'expected_cash' => round($expected, 2),
            'counted_cash' => $counted !== null ? round($counted, 2) : null,
            'cash_difference' => $counted !== null && $shift->closed ? round($counted - $expected, 2) : null,
            'has_deficit' => (bool) $shift->has_deficit,
            'money_edited_after_close' => (int) $shift->money_edited_count,
        ];
    }

    /**
     * @param  array<string, mixed>  $stats
     * @return array<string, mixed>
     */
    private function totals(array $stats): array
    {
        $row = [
            'orders' => isset($stats['totalOrders']) ? (int) $stats['totalOrders'] : null,
            'gross_sales' => round((float) $stats['sales'], 2),
            'refunds' => round((float) $stats['returnsAmount'], 2),
            'net_sales' => round((float) $stats['netSales'], 2),
            'net_profit' => round((float) $stats['netProfit'], 2),
            'discounts' => round((float) $stats['discounts'], 2),
            'expenses' => round((float) $stats['expenses'], 2),
            'other_revenues' => round((float) $stats['revenues'], 2),
            'cash_payments' => round((float) $stats['cashPayments'], 2),
            'card_payments' => round((float) $stats['cardPayments'], 2),
            'talabat_card_payments' => round((float) $stats['talabatCardPayments'], 2),
            'cash_refunds' => round((float) $stats['cashRefunds'], 2),
            'expected_cash' => round((float) $stats['availableCash'], 2),
            'avg_order' => round((float) $stats['avgReceiptValue'], 2),
        ];

        if ($this->features->deferredPaymentEnabled()) {
            $row['uncollected_credit'] = round((float) $stats['uncollectedCredit'], 2);
            $row['previous_collections'] = round((float) $stats['previousCollections'], 2);
        }
        if (! $this->features->returnsEnabled()) {
            unset($row['refunds'], $row['cash_refunds']);
        }

        return array_filter($row, fn ($value) => $value !== null);
    }

    /** @return array<string, string> */
    private function definitions(): array
    {
        return [
            'expected_cash' => 'start_cash + cash payments + cash revenues + external cash − expenses − cash refunds − seller settlements. For a closed shift, the value saved at close (updated if its money was edited later).',
            'counted_cash' => 'Cash the cashier counted at close.',
            'cash_difference' => 'counted_cash − expected_cash: negative is a shortage, positive a surplus.',
            'net_sales' => 'Completed orders of the shift minus refunds the shift paid.',
            'business_date' => 'The day the shift counts for (a shift past midnight belongs to the day it opened).',
        ];
    }

    /** @return list<string> */
    private function caveats(): array
    {
        return $this->features->multiTerminalEnabled()
            ? ['Figures cover all sales points of the shift.']
            : [];
    }
}
