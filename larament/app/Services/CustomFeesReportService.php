<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\OrderFee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * «تقرير الرسوم الإضافية»: fee lines of completed orders in a set of shifts.
 * Names are grouped by their normalised key, so «رسوم دخول  تورتة» and
 * «رسوم دخول تورتة» are one row.
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
     * One row per fee name: count, amount and share of all fees.
     *
     * @param  QueryBuilder|array<int|string>  $shiftIds
     * @return array<string, array{name: string, count: int, amount: float, average: float, share: float}>
     */
    public function summaryByName(QueryBuilder|array $shiftIds): array
    {
        $rows = $this->linesQuery($shiftIds)
            ->toBase()
            ->selectRaw('name_key, MAX(id) as latest_id, COUNT(*) as uses, SUM(amount) as amount')
            ->groupBy('name_key')
            ->orderByDesc('amount')
            ->get();

        $names = OrderFee::query()->whereIn('id', $rows->pluck('latest_id'))->pluck('name', 'id');
        $total = (float) $rows->sum('amount');

        $summary = [];
        foreach ($rows as $row) {
            $amount = round((float) $row->amount, 2);
            $count = (int) $row->uses;
            $summary[(string) $row->name_key] = [
                'name' => (string) ($names[$row->latest_id] ?? $row->name_key),
                'count' => $count,
                'amount' => $amount,
                'average' => $count > 0 ? round($amount / $count, 2) : 0.0,
                'share' => $total > 0 ? round($amount / $total * 100, 1) : 0.0,
            ];
        }

        return $summary;
    }
}
