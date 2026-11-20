<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Database\Query\Builder as QueryBuilder;

class PeriodShiftCreditCollectionsTable extends CreditCollectionsTable
{
    use InteractsWithPageFilters;

    protected function reportScope(): ?QueryBuilder
    {
        return $this->shiftsReportService->shiftIdsQueryFromFilters($this->pageFilters);
    }
}
