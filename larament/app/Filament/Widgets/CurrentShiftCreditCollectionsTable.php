<?php

namespace App\Filament\Widgets;

use Illuminate\Database\Query\Builder as QueryBuilder;

class CurrentShiftCreditCollectionsTable extends CreditCollectionsTable
{
    protected function reportScope(): ?QueryBuilder
    {
        $shift = $this->shiftsReportService->getCurrentShift();

        return $shift === null ? null : $this->shiftsReportService->shiftIdsQuery(null, null, [$shift->id]);
    }
}
