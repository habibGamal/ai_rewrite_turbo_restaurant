<?php

namespace App\Filament\Widgets;

use App\Services\Reports\Inventory\InventoryMovementsReportService;
use App\Services\Reports\Inventory\InventoryReportFilters;
use Filament\Support\Enums\IconPosition;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The inventory movements report's six headline figures, each with its
 * change against the comparison period.
 */
class InventoryMovementsKpisWidget extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $service = app(InventoryMovementsReportService::class);
        $filters = InventoryReportFilters::fromArray($this->pageFilters);
        $now = $service->summary($filters);
        $comparison = $filters->comparison();
        $before = $comparison ? $service->summary($comparison) : null;
        $word = $filters->comparisonWord();
        $change = fn (string $key) => $before ? InventoryMovementsReportService::change($now[$key], $before[$key]) : null;

        $net = (float) $now['net'];

        return [
            Stat::make('قيمة المخزون آخر المدة', self::money($now['closing']))
                ->description(($net >= 0 ? '+' : '').self::money($net).' عن أول المدة ('.self::money($now['opening']).')')
                ->descriptionIcon($net >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down', IconPosition::Before)
                ->color('gray'),

            $this->stat('المشتريات', self::money($now['purchases']), $change('purchases'), '%', null, $word,
                fallback: number_format((int) $now['purchase_documents']).' فاتورة · مرتجع '.self::money($now['purchase_returns'])),

            $this->stat($service->consumptionLabel(), self::money($now['consumption']), $change('consumption'), '%', null, $word,
                fallback: 'خروج الطلبات ناقص مرتجعها'),

            $this->stat('الفاقد', self::money($now['loss']), $change('loss'), '%', false, $word,
                fallback: 'هالك '.self::money($now['waste']).' + فروق جرد '.self::money($now['stocktaking_net'])),

            $this->stat('نسبة الفاقد', self::percent($now['loss_rate']),
                $before && $now['loss_rate'] !== null && $before['loss_rate'] !== null ? round($now['loss_rate'] - $before['loss_rate'], 1) : null,
                ' نقطة', false, $word,
                fallback: 'الفاقد ÷ (الاستهلاك + الصرف + الفاقد)'),

            Stat::make('المخزون يكفي', $now['cover_days'] === null ? '—' : number_format((float) $now['cover_days']).' يوم')
                ->description($now['turnover'] === null ? 'بمتوسط الخروج اليومي' : 'دوران '.number_format((float) $now['turnover'], 2).' مرة في الفترة')
                ->color('gray'),
        ];
    }

    /**
     * A stat whose description is the change against the comparison period.
     * $higherIsBetter null = neutral (no good or bad colour).
     */
    private function stat(string $label, string $value, ?float $change, string $unit, ?bool $higherIsBetter, string $word, string $fallback): Stat
    {
        $stat = Stat::make($label, $value);

        if ($change === null) {
            return $stat->description($fallback)->color('gray');
        }

        $good = ($change == 0 || $higherIsBetter === null) ? null : (($change > 0) === $higherIsBetter);

        return $stat
            ->description(($change > 0 ? '+' : '').number_format($change, 1).$unit.' عن '.$word)
            ->descriptionIcon($change >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down', IconPosition::Before)
            ->color($good === null ? 'gray' : ($good ? 'success' : 'danger'));
    }

    public static function money(?float $value): string
    {
        return number_format((float) $value, 2).' ج.م';
    }

    public static function percent(?float $value): string
    {
        return $value === null ? '—' : number_format($value, 1).'%';
    }
}
