<?php

namespace App\Filament\Components;

use App\Services\Reports\ReportTerminalScope;
use Closure;
use Filament\Forms\Components\ToggleButtons;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The sales point tabs at the top of the current-shift and shift-period
 * reports: «الكل» (the whole shift) and one tab per sales point that worked
 * in the report's shifts. Hidden while there is at most one sales point.
 */
class ReportTerminalTabs
{
    /**
     * @param  Closure(): (QueryBuilder|array<int|string>)  $shiftIds
     */
    public static function make(Closure $shiftIds): ToggleButtons
    {
        return ToggleButtons::make(ReportTerminalScope::FILTER)
            ->label('منفذ البيع')
            ->hiddenLabel()
            ->options(fn (): array => ReportTerminalScope::tabs($shiftIds()))
            ->default(ReportTerminalScope::ALL)
            ->grouped()
            ->live()
            ->columnSpanFull()
            ->visible(fn (): bool => ReportTerminalScope::tabs($shiftIds()) !== []);
    }
}
