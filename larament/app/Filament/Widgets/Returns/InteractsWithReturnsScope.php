<?php

namespace App\Filament\Widgets\Returns;

use App\Services\ShiftsReportService;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The returns a report page covers: those paid by the shifts its filters select
 * (a refund belongs to the shift that paid it, not the original order's).
 */
trait InteractsWithReturnsScope
{
    protected function shiftScope(): QueryBuilder
    {
        return app(ShiftsReportService::class)->shiftIdsQueryFromFilters($this->pageFilters ?? []);
    }

    protected function returnsScope(): QueryBuilder
    {
        return DB::table('order_returns')->whereIn('order_returns.shift_id', $this->shiftScope());
    }
}
