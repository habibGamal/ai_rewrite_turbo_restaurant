<?php

namespace App\Filament\Widgets\Returns;

use App\Services\Reports\ReturnsAggregates;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ReturnsReportStats extends BaseWidget
{
    use InteractsWithPageFilters;
    use InteractsWithReturnsScope;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $scope = $this->returnsScope();
        $stats = app(ReturnsAggregates::class)->forShifts($this->shiftScope());
        $exchanges = (clone $scope)->whereNotNull('order_returns.exchange_order_id')->count();

        return [
            Stat::make('عدد المرتجعات', number_format($stats['returnsCount']))
                ->description($exchanges.' منها استبدال')
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color('primary'),

            Stat::make('قيمة المرتجعات', number_format($stats['returnsAmount'], 2).' جنيه')
                ->description('أثر على الربح '.number_format($stats['returnsProfitImpact'], 2).' جنيه')
                ->descriptionIcon('heroicon-m-arrow-uturn-left')
                ->color('danger'),

            Stat::make('رد نقدي', number_format($stats['cashRefunds'], 2).' جنيه')
                ->description('يُخصم من النقدية المتاحة في الدرج')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('warning'),

            Stat::make('رد فيزا', number_format($stats['cardRefunds'] + $stats['talabatCardRefunds'], 2).' جنيه')
                ->descriptionIcon('heroicon-m-credit-card')
                ->color('info'),

            Stat::make('رجع للمخزن', $this->qty($stats['restockedQuantity']))
                ->description('كمية')
                ->descriptionIcon('heroicon-m-archive-box-arrow-down')
                ->color('success'),

            Stat::make('تالف', $this->qty($stats['damagedQuantity']))
                ->description('بقيمة '.number_format($stats['damagedAmount'], 2).' جنيه (لم يرجع للمخزن)')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color('danger'),
        ];
    }

    private function qty(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2), '0'), '.');
    }
}
