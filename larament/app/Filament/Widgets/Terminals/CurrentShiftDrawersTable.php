<?php

namespace App\Filament\Widgets\Terminals;

use App\Services\ShiftsReportService;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class CurrentShiftDrawersTable extends ShiftDrawersTable
{
    protected static ?string $heading = 'أدراج الوردية الحالية';

    protected function shiftIds(): QueryBuilder
    {
        $shift = app(ShiftsReportService::class)->getCurrentShift();

        return DB::table('shifts')->select('id')->where('id', $shift?->getKey() ?? 0);
    }
}
