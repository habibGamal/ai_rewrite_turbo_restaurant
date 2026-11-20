<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Filament\Pages\Reports\ExpensesReport;
use App\Mcp\Support\Period;
use App\Services\FeatureService;
use App\Services\Reports\ExpensesRevenuesReportService;
use App\Services\ShiftsReportService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('expenses_revenues')]
#[Title('Expenses and revenues')]
#[Description(<<<'TEXT'
Expenses by type (gas, salaries, supplies...), other revenues by type, order revenue net of refunds, and the period's net (revenues − expenses), with a comparison period. Optionally the expense trend by day.
Use for "what did we spend on gas this month", "expenses as a share of sales", "biggest expense", "net after expenses".
Not for cost of goods or profit per item (use product_performance) or purchases from suppliers.
Examples: «كم أنفقنا على الغاز هذا الشهر؟», «ما نسبة المصروفات من المبيعات؟», "Which expense grew most vs last month?".
TEXT)]
#[IsReadOnly]
class ExpensesRevenuesTool extends BusinessTool
{
    private readonly ExpensesRevenuesReportService $report;

    private readonly ShiftsReportService $shifts;

    private readonly FeatureService $features;

    /** Services come from the container when the tool is built without them. */
    public function __construct(?ExpensesRevenuesReportService $report = null, ?ShiftsReportService $shifts = null, ?FeatureService $features = null)
    {
        $this->report = $report ?? app(ExpensesRevenuesReportService::class);
        $this->shifts = $shifts ?? app(ShiftsReportService::class);
        $this->features = $features ?? app(FeatureService::class);
    }

    protected function reportPages(): array
    {
        return [ExpensesReport::class];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->periodSchema($schema),
            'expense_type' => $schema->string()->description('Only this expense type (name, as in the shop). Default: all.'),
            'by_day' => $schema->boolean()->description('Also return expenses per business day (max 93 days). Default false.'),
        ];
    }

    protected function run(Request $request): array
    {
        $period = $this->period($request);
        $current = $this->summary($period);
        $comparison = $period->comparison();
        $previous = $comparison !== null ? $this->summary($comparison) : null;

        $type = trim((string) ($request->get('expense_type') ?? ''));
        $expenseTypes = $current['expense_types'];
        if ($type !== '') {
            $expenseTypes = array_values(array_filter($expenseTypes, fn (array $row) => mb_stripos($row['name'], $type) !== false));
        }

        $previousByType = collect($previous['expense_types'] ?? [])->keyBy('name');
        $expenseRows = array_map(fn (array $row): array => [
            'type' => $row['name'],
            'count' => $row['count'],
            'amount' => $row['total'],
            'share_of_expenses' => self::share($row['total'], $current['expenses']),
            'share_of_net_sales' => self::share($row['total'], $current['orders']['net']),
            'previous' => $previous !== null ? (float) ($previousByType->get($row['name'])['total'] ?? 0) : null,
            'change_percent' => $previous !== null ? self::change($row['total'], $previousByType->get($row['name'])['total'] ?? null) : null,
        ], $expenseTypes);

        $data = [
            'order_revenue' => [
                'orders' => $current['orders']['count'],
                'gross' => $current['orders']['gross'],
                'refunds' => $current['orders']['refunds'],
                'net' => $current['orders']['net'],
            ],
            'expenses_total' => $current['expenses'],
            'expenses_share_of_net_sales' => self::share($current['expenses'], $current['orders']['net']),
            'expense_types' => array_map(fn ($row) => array_filter($row, fn ($v) => $v !== null), $expenseRows),
            'net' => $current['net'],
        ];

        if ($this->features->revenuesEnabled()) {
            $data['other_revenues_total'] = $current['other_revenues'];
            $data['revenue_types'] = array_map(fn (array $row) => ['type' => $row['name'], 'count' => $row['count'], 'cash' => $row['cash'], 'card' => $row['card'], 'amount' => $row['total']], $current['revenue_types']);
        }

        if ($previous !== null) {
            $data['previous'] = ['net_sales' => $previous['orders']['net'], 'expenses_total' => $previous['expenses'], 'net' => $previous['net']];
            $data['change_percent'] = [
                'expenses_total' => self::change($current['expenses'], $previous['expenses']),
                'net' => self::change($current['net'], $previous['net']),
            ];
        }

        if ((bool) $request->get('by_day') && $period->days() <= 93) {
            $data['expenses_by_day'] = $this->byDay($period, $type);
        }

        $caveats = [];
        if ((bool) $request->get('by_day') && $period->days() > 93) {
            $caveats[] = 'Daily rows are limited to 93 days; ask for a shorter period.';
        }
        if ($type !== '' && $expenseRows === []) {
            $caveats[] = "No expense type matches \"{$type}\". Names: ".implode(', ', array_column($current['expense_types'], 'name')).'.';
        }

        return $this->envelope(
            $period,
            $data,
            [
                'order_revenue.net' => 'Completed orders of the period\'s shifts minus refunds those shifts paid.',
                'net' => 'order revenue net + other revenues − expenses.',
                'share_of_net_sales' => 'amount / order revenue net, percent.',
                'period' => 'Shifts that started in the period (the expenses report\'s rule).',
            ],
            $caveats,
            $type === '' ? [] : ['expense_type' => $type],
            $comparison !== null ? ['from' => $comparison->from->toDateString(), 'to' => $comparison->to->toDateString()] : null,
        );
    }

    /** @return array<string, mixed> */
    private function summary(Period $period): array
    {
        return $this->report->summary($this->shifts->shiftIdsQuery($period->from->toDateString(), $period->to->toDateString()));
    }

    /** @return list<array{date: string, amount: float}> */
    private function byDay(Period $period, string $type): array
    {
        return DB::table('expenses')
            ->join('shifts', 'shifts.id', '=', 'expenses.shift_id')
            ->leftJoin('expence_types', 'expence_types.id', '=', 'expenses.expence_type_id')
            ->whereIn('expenses.shift_id', $this->shifts->shiftIdsQuery($period->from->toDateString(), $period->to->toDateString()))
            ->when($type !== '', fn ($q) => $q->where('expence_types.name', 'like', '%'.$type.'%'))
            ->selectRaw('DATE(shifts.start_at) as day, SUM(expenses.amount) as amount')
            ->groupByRaw('DATE(shifts.start_at)')
            ->orderBy('day')
            ->get()
            ->map(fn ($row): array => ['date' => (string) $row->day, 'amount' => round((float) $row->amount, 2)])
            ->all();
    }
}
