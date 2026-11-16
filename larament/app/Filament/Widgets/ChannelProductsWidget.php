<?php

namespace App\Filament\Widgets;

use App\Services\ChannelPerformanceReportService;
use App\Services\Reports\Channels\ChannelReportFilters;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/**
 * «أكثر الأصناف مبيعاً في كل قناة»: a tab per channel with its top ten
 * products by value or quantity.
 */
class ChannelProductsWidget extends Widget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected string $view = 'filament.widgets.channel-products';

    protected int|string|array $columnSpan = 'full';

    /** Order type code; '' = the biggest channel. */
    public string $channel = '';

    /** 'sales' or 'quantity'. */
    public string $by = 'sales';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $service = app(ChannelPerformanceReportService::class);
        $filters = ChannelReportFilters::fromArray($this->pageFilters);
        $by = $this->by === 'quantity' ? 'quantity' : 'sales';
        $top = $service->topProducts($filters, 10, $by);
        $tabs = array_filter($service->channelRows($filters), fn (array $row) => isset($top[$row['code']]));
        $channel = isset($tabs[$this->channel]) ? $this->channel : (string) array_key_first($tabs);

        return [
            'tabs' => $tabs,
            'channel' => $channel,
            'by' => $by,
            'products' => $top[$channel] ?? [],
        ];
    }
}
