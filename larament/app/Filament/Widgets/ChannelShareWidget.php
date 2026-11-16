<?php

namespace App\Filament\Widgets;

use App\Services\ChannelPerformanceReportService;
use App\Services\Reports\Channels\ChannelReportFilters;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/**
 * Each channel's share of orders against its share of net sales, as two
 * stacked 100% bars. A channel whose sales share beats its orders share
 * brings bigger orders than the rest.
 */
class ChannelShareWidget extends Widget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected string $view = 'filament.widgets.channel-share';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $rows = app(ChannelPerformanceReportService::class)->channelRows(ChannelReportFilters::fromArray($this->pageFilters));

        return [
            // Refunds can push a channel's net sales below zero; it has no share then.
            'rows' => array_values(array_filter($rows, fn (array $row) => $row['orders'] > 0 || $row['net_sales'] > 0)),
        ];
    }
}
