<?php

namespace App\Filament\Widgets;

use App\Services\ChannelPerformanceReportService;
use App\Services\Reports\Channels\ChannelReportFilters;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/**
 * «الساعات × أيام الأسبوع»: when each channel is busy. Darker = more orders
 * (or sales), for one channel or all of them.
 */
class ChannelHeatmapWidget extends Widget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected string $view = 'filament.widgets.channel-heatmap';

    protected int|string|array $columnSpan = 'full';

    /** Order type code, '' for all channels. */
    public string $channel = '';

    /** 'orders' or 'sales'. */
    public string $metric = 'orders';

    /** Weekdays as SqlDates::dayOfWeek numbers them, Saturday first. */
    public const DAYS = [7 => 'السبت', 1 => 'الأحد', 2 => 'الإثنين', 3 => 'الثلاثاء', 4 => 'الأربعاء', 5 => 'الخميس', 6 => 'الجمعة'];

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $service = app(ChannelPerformanceReportService::class);
        $filters = ChannelReportFilters::fromArray($this->pageFilters);
        $channels = array_intersect_key($service->channelOptions(), $service->channelRows($filters));
        $channel = isset($channels[$this->channel]) ? $this->channel : '';
        $metric = $this->metric === 'sales' ? 'sales' : 'orders';

        $grid = $service->heatmap($filters, $channel === '' ? null : $channel);
        $max = 0;
        foreach ($grid as $hours) {
            foreach ($hours as $cell) {
                $max = max($max, $cell[$metric]);
            }
        }

        // Only the hours that have any order, so a café open 8–24 doesn't show empty nights.
        $activeHours = array_values(array_filter(range(0, 23), function (int $hour) use ($grid) {
            foreach ($grid as $hours) {
                if ($hours[$hour]['orders'] > 0) {
                    return true;
                }
            }

            return false;
        }));

        return [
            'channels' => $channels,
            'grid' => $grid,
            'max' => $max,
            'metric' => $metric,
            'hours' => $activeHours,
            'days' => self::DAYS,
        ];
    }
}
