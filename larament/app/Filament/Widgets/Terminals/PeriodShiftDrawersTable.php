<?php

namespace App\Filament\Widgets\Terminals;

use App\Services\ShiftsReportService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Database\Query\Builder as QueryBuilder;

class PeriodShiftDrawersTable extends ShiftDrawersTable
{
    use InteractsWithPageFilters;

    protected static ?string $heading = 'منافذ البيع في الورديات';

    protected function shiftIds(): QueryBuilder
    {
        return app(ShiftsReportService::class)->shiftIdsQueryFromFilters($this->pageFilters);
    }
}
