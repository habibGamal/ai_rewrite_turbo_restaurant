<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Filament\Pages\Reports\ReturnsReport;
use App\Models\User;
use App\Services\FeatureService;
use App\Services\Reports\ReturnsAggregates;
use App\Services\ShiftsReportService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('returns_analysis')]
#[Title('Returns analysis')]
#[Description(<<<'TEXT'
Sales returns (refunds) paid in a period: count, amount, profit lost, refund methods, items restocked vs damaged, grouped by product, channel, cashier, reason or day.
Use for "how much did we refund this week", "most returned products", "which cashier makes most refunds", "why are customers returning items".
Not for cancelled orders (use orders with status cancelled) or waste (use inventory_movements).
Examples: «ما أكثر الأصناف المرتجعة هذا الشهر؟», «كم بلغت المرتجعات أمس؟», "Refunds by cashier this week".
TEXT)]
#[IsReadOnly]
class ReturnsAnalysisTool extends BusinessTool
{
    public const GROUPS = ['product', 'channel', 'cashier', 'reason', 'day'];

    private readonly ReturnsAggregates $returns;

    private readonly ShiftsReportService $shifts;

    private readonly FeatureService $features;

    /** Services come from the container when the tool is built without them. */
    public function __construct(?ReturnsAggregates $returns = null, ?ShiftsReportService $shifts = null, ?FeatureService $features = null)
    {
        $this->returns = $returns ?? app(ReturnsAggregates::class);
        $this->shifts = $shifts ?? app(ShiftsReportService::class);
        $this->features = $features ?? app(FeatureService::class);
    }

    protected function reportPages(): array
    {
        return [ReturnsReport::class];
    }

    public function allowed(): bool
    {
        return $this->features->returnsEnabled() && parent::allowed();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'group_by' => $schema->string()->enum(self::GROUPS)->description('Optional split. Default: totals only.'),
            ...$this->periodSchema($schema),
            'limit' => $schema->integer()->description('Rows when grouped. Default 10, max 100.'),
        ];
    }

    protected function run(Request $request): array
    {
        $period = $this->period($request, 'this_month');
        $scope = $this->shifts->shiftIdsQuery($period->from->toDateString(), $period->to->toDateString());
        $summary = $this->returns->forShifts(clone $scope);
        $comparison = $period->comparison();
        $previous = $comparison !== null
            ? $this->returns->forShifts($this->shifts->shiftIdsQuery($comparison->from->toDateString(), $comparison->to->toDateString()))
            : null;

        $totals = [
            'returns' => $summary['returnsCount'],
            'refunded' => $summary['returnsAmount'],
            'profit_lost' => $summary['returnsProfitImpact'],
            'cash_refunds' => $summary['cashRefunds'],
            'card_refunds' => $summary['cardRefunds'],
            'talabat_card_refunds' => $summary['talabatCardRefunds'],
            'restocked_quantity' => $summary['restockedQuantity'],
            'damaged_quantity' => $summary['damagedQuantity'],
            'damaged_amount' => $summary['damagedAmount'],
            'items_refunds' => $summary['itemsRefunds'],
            'fees_refunds' => $summary['feesRefunds'],
            'equipment_refunds' => $summary['equipmentRefunds'],
        ];

        $data = ['totals' => $totals];
        if ($previous !== null) {
            $data['previous'] = ['returns' => $previous['returnsCount'], 'refunded' => $previous['returnsAmount']];
            $data['change_percent'] = ['refunded' => self::change($summary['returnsAmount'], $previous['returnsAmount'])];
        }

        $group = $request->get('group_by');
        if ($group !== null) {
            if (! in_array($group, self::GROUPS, true)) {
                throw new InvalidArgumentException('group_by must be one of: '.implode(', ', self::GROUPS).'.');
            }
            $returns = DB::table('order_returns')->whereIn('order_returns.shift_id', clone $scope);
            $data['rows'] = $this->grouped((string) $group, $returns, $summary['returnsAmount'], $this->limit($request));
        }

        return $this->envelope(
            $period,
            $data,
            [
                'refunded' => 'Money paid back, by the shifts that paid it (not the original order\'s day).',
                'profit_lost' => 'Refund minus the cost of items put back in stock; damaged items lose the whole refund.',
                'restocked_quantity' => 'Returned item quantity put back in stock; damaged_quantity was not.',
            ],
            $summary['returnsCount'] === 0 ? ['No returns in this period.'] : [],
            [],
            $comparison !== null ? ['from' => $comparison->from->toDateString(), 'to' => $comparison->to->toDateString()] : null,
        );
    }

    /** @return list<array<string, mixed>> */
    private function grouped(string $group, Builder $returns, float $total, int $limit): array
    {
        if ($group === 'product') {
            return $this->returns->linesQuery($returns)
                ->join('products', 'products.id', '=', 'roi.product_id')
                ->groupBy('products.id', 'products.name')
                ->select('products.id', 'products.name')
                ->selectRaw('COUNT(DISTINCT orr.id) as returns_count, SUM(ori.quantity) as qty, SUM(ori.refund_amount) as amount')
                ->selectRaw('SUM(CASE WHEN ori.restocked = 0 THEN ori.quantity ELSE 0 END) as damaged')
                ->orderByDesc('amount')
                ->limit($limit)
                ->get()
                ->map(fn ($row): array => [
                    'product_id' => (int) $row->id,
                    'product' => $row->name,
                    'returns' => (int) $row->returns_count,
                    'quantity' => (float) $row->qty,
                    'damaged_quantity' => (float) $row->damaged,
                    'refunded' => round((float) $row->amount, 2),
                    'share' => self::share((float) $row->amount, $total),
                ])->all();
        }

        $expression = match ($group) {
            'channel' => 'ro.type',
            'cashier' => 'orr.user_id',
            'reason' => "COALESCE(orr.reason, '')",
            default => 'DATE(orr.created_at)',
        };

        $rows = $this->returns->groupedBy($returns, $expression);
        uasort($rows, fn ($a, $b) => $group === 'day' ? 0 : $b['amount'] <=> $a['amount']);
        if ($group === 'day') {
            ksort($rows);
        }

        $names = $group === 'cashier'
            ? User::query()->withoutGlobalScopes()->whereIn('id', array_keys($rows))->get()->mapWithKeys(fn (User $u) => [(string) $u->id => $u->isVendor() ? User::VENDOR_DISPLAY_NAME : $u->name])->all()
            : [];

        $result = [];
        foreach (array_slice($rows, 0, $group === 'day' ? 93 : $limit, true) as $key => $row) {
            $label = match ($group) {
                'cashier' => $names[(string) $key] ?? (string) $key,
                'reason' => $key === '' ? '(no reason given)' : (string) $key,
                default => (string) $key,
            };
            $result[] = [
                $group => $label,
                'returns' => $row['count'],
                'quantity' => $row['quantity'],
                'refunded' => $row['amount'],
                'profit_lost' => $row['profit_impact'],
                'share' => self::share($row['amount'], $total),
            ];
        }

        return $result;
    }
}
