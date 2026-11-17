<?php

namespace App\Filament\Widgets\Concerns;

use App\Services\Reports\ReportTerminalScope;

/**
 * For widgets of the current-shift and shift-period reports: narrows the
 * report queries to the sales point tab picked on the page («الكل» = all).
 * Call reportTerminal() before computing anything. Use it with
 * InteractsWithPageFilters.
 */
trait ScopesReportToTerminal
{
    protected function reportTerminal(): ReportTerminalScope
    {
        $scope = app(ReportTerminalScope::class);
        $scope->setFromFilters((array) ($this->pageFilters ?? []));

        return $scope;
    }
}
