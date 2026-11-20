<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\OrderFee;
use App\Services\Reports\ReturnsAggregates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * «تقرير الرسوم الإضافية»: fee lines of completed orders in a set of shifts.
 * Names are grouped by their normalised key, so «رسوم دخول  تورتة» and
 * «رسوم دخول تورتة» are one row. Refunds count in the shift that paid them,
 * like every other return.
 */
class CustomFeesReportService
{
    /**
     * Fee lines of completed orders whose shift is in the scope.
     *
     * @param  QueryBuilder|array<int|string>  $shiftIds
     * @return Builder<OrderFee>
     */
    public function linesQuery(QueryBuilder|array $shiftIds): Builder
    {
        return OrderFee::query()
            ->whereHas('order', fn (Builder $query) => $query
                ->where('status', OrderStatus::COMPLETED)
                ->whereIn('shift_id', $shiftIds instanceof QueryBuilder ? clone $shiftIds : $shiftIds));
    }

    /**
     * One row per fee name: count, amount, refunded, net and share of all fees.
     *
     * @param  QueryBuilder|array<int|string>  $shiftIds
     * @return array<string, array{name: string, count: int, amount: float, average: float, share: float, refunded: float, net: float}>
     */
    public function summaryByName(QueryBuilder|array $shiftIds): array
    {
        $rows = $this->linesQuery($shiftIds)
            ->toBase()
            ->selectRaw('name_key, MAX(id) as latest_id, COUNT(*) as uses, SUM(amount) as amount')
            ->groupBy('name_key')
            ->orderByDesc('amount')
            ->get()
            ->keyBy(fn ($row): string => (string) $row->name_key);

        $refunds = $this->refundsByName($shiftIds);

        // A fee charged in an earlier shift but refunded in this scope still gets a row.
        $latestIds = $rows->pluck('latest_id', 'name_key')->all();
        $missing = array_diff(array_keys($refunds), array_keys($latestIds));
        if ($missing !== []) {
            $latestIds += OrderFee::query()->whereIn('name_key', $missing)->groupBy('name_key')
                ->selectRaw('name_key, MAX(id) as latest_id')->pluck('latest_id', 'name_key')->all();
        }

        $names = OrderFee::query()->whereIn('id', array_values($latestIds))->pluck('name', 'id');
        $total = (float) $rows->sum('amount');

        $summary = [];
        foreach (array_keys($latestIds) as $key) {
            $row = $rows->get((string) $key);
            $amount = round((float) ($row->amount ?? 0), 2);
            $count = (int) ($row->uses ?? 0);
            $refunded = $refunds[(string) $key] ?? 0.0;
            $summary[(string) $key] = [
                'name' => (string) ($names[$latestIds[$key]] ?? $key),
                'count' => $count,
                'amount' => $amount,
                'average' => $count > 0 ? round($amount / $count, 2) : 0.0,
                'share' => $total > 0 ? round($amount / $total * 100, 1) : 0.0,
                'refunded' => $refunded,
                'net' => round($amount - $refunded, 2),
            ];
        }

        return $summary;
    }

    /**
     * Fee refunds paid in the scope's shifts, per fee name key.
     *
     * @param  QueryBuilder|array<int|string>  $shiftIds
     * @return array<string, float>
     */
    public function refundsByName(QueryBuilder|array $shiftIds): array
    {
        return app(ReturnsAggregates::class)->feeRefundsByName(
            DB::table('order_returns')->whereIn('order_returns.shift_id', $shiftIds instanceof QueryBuilder ? clone $shiftIds : $shiftIds),
        );
    }
}
