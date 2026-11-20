<?php

namespace App\Filament\Widgets;

use App\Services\FeatureService;
use App\Services\Reports\ExpensesRevenuesReportService;
use App\Services\ShiftsReportService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/**
 * «تقرير المصروفات والإيرادات» summary: order revenue (computed, net of
 * refunds, with its parts), other revenues by type, expenses and the net.
 * Shown while the revenues feature is on.
 */
class ExpensesRevenuesSummaryWidget extends Widget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected string $view = 'filament.widgets.expenses-revenues-summary';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return app(FeatureService::class)->revenuesEnabled();
    }

    /**
     * @return array{summary: array<string, mixed>}
     */
    protected function getViewData(): array
    {
        $shiftIds = app(ShiftsReportService::class)->shiftIdsQueryFromFilters($this->pageFilters);

        return [
            'summary' => app(ExpensesRevenuesReportService::class)->summary($shiftIds),
        ];
    }
}
