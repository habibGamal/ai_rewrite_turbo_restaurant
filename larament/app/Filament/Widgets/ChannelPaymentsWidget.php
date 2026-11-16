<?php

namespace App\Filament\Widgets;

use App\Enums\PaymentMethod;
use App\Services\ChannelPerformanceReportService;
use App\Services\Reports\Channels\ChannelReportFilters;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/**
 * «طرق الدفع لكل قناة»: how much of each channel was paid cash, by card or
 * by Talabat card.
 */
class ChannelPaymentsWidget extends Widget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected string $view = 'filament.widgets.channel-payments';

    protected int|string|array $columnSpan = 'full';

    public const METHOD_COLORS = ['cash' => '#16a34a', 'card' => '#2563eb', 'talabat_card' => '#f97316'];

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $service = app(ChannelPerformanceReportService::class);
        $filters = ChannelReportFilters::fromArray($this->pageFilters);
        $mix = $service->paymentMix($filters);

        $rows = [];
        foreach ($service->channelRows($filters) as $code => $row) {
            if (isset($mix[$code])) {
                $rows[] = ['label' => $row['label'], 'chart' => $row['chart'], ...$mix[$code]];
            }
        }

        $methods = [];
        foreach (PaymentMethod::cases() as $method) {
            if (collect($rows)->contains(fn (array $row) => ($row['methods'][$method->value] ?? 0) != 0)) {
                $methods[$method->value] = $method->getLabel();
            }
        }

        return ['rows' => $rows, 'methods' => $methods, 'colors' => self::METHOD_COLORS];
    }
}
