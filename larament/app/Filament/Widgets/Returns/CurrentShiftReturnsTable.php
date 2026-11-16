<?php

namespace App\Filament\Widgets\Returns;

use App\Models\Shift;
use App\Services\ShiftsReportService;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/** The returns paid in the open shift («تقرير اليوم»). */
class CurrentShiftReturnsTable extends ShiftReturnsTable
{
    protected function shiftScope(): QueryBuilder
    {
        $shift = app(ShiftsReportService::class)->getCurrentShift();

        return DB::table('shifts')->select('id')->where('id', $shift instanceof Shift ? $shift->id : 0);
    }
}
