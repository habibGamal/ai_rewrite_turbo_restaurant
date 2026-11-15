<?php

namespace App\Filament\Widgets;

use App\Services\KitchenPerformanceReportService;
use Filament\Support\Enums\IconPosition;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class KitchenPerformanceStatsWidget extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected KitchenPerformanceReportService $reportService;

    public function boot(): void
    {
        $this->reportService = app(KitchenPerformanceReportService::class);
    }

    public function getHeading(): string
    {
        return 'ملخص أداء المطبخ';
    }

    protected function getStats(): array
    {
        $summary = $this->reportService->getSummary(
            $this->pageFilters['startDate'] ?? now()->subDays(29)->startOfDay()->toDateString(),
            $this->pageFilters['endDate'] ?? now()->endOfDay()->toDateString(),
            $this->stationId(),
        );

        return [
            Stat::make('عدد الطلبات في المطبخ', number_format($summary['tickets_count']))
                ->description('الطلبات غير الملغاة التي أرسلت للمطبخ خلال الفترة')
                ->descriptionIcon('heroicon-m-ticket', IconPosition::Before)
                ->color('primary'),

            Stat::make(
                'متوسط وقت المطبخ',
                KitchenPerformanceReportService::formatDuration($summary['avg_kitchen_seconds'])
            )
                ->description('مجموع الجولات المغلقة فقط، دون فترات الانتظار بينها')
                ->descriptionIcon('heroicon-m-clock', IconPosition::Before)
                ->color('info'),

            Stat::make('نسبة الجولات المتأخرة', number_format($summary['late_rounds_percent'], 1).'%')
                ->description('الجولات التي تجاوزت أكبر وقت مستهدف لبنودها')
                ->descriptionIcon('heroicon-m-exclamation-triangle', IconPosition::Before)
                ->color($this->lateColor($summary['late_rounds_percent']))
                ->extraAttributes([
                    'title' => 'الجولات التي استغرقت وقتاً أطول من أقصى وقت تحضير مستهدف لبنودها غير الملغاة',
                ]),

            Stat::make(
                'متوسط وقت تحضير البند',
                KitchenPerformanceReportService::formatDuration($summary['avg_item_prep_seconds'])
            )
                ->description('من وصول الصنف للمطبخ حتى الانتهاء، دون البنود المنتهية تلقائياً')
                ->descriptionIcon('heroicon-m-fire', IconPosition::Before)
                ->color('warning'),

            Stat::make('بنود مرشحة للهدر', number_format($summary['waste_candidates_count']))
                ->description('بنود أُنجزت ثم أُلغيت بعد التحضير')
                ->descriptionIcon('heroicon-m-trash', IconPosition::Before)
                ->color($summary['waste_candidates_count'] > 0 ? 'danger' : 'success'),
        ];
    }

    protected function getColumns(): int
    {
        return 5;
    }

    private function stationId(): ?int
    {
        $stationId = $this->pageFilters['kitchen_station_id'] ?? null;

        return $stationId === null || $stationId === '' ? null : (int) $stationId;
    }

    private function lateColor(float $percent): string
    {
        return match (true) {
            $percent >= 30 => 'danger',
            $percent >= 15 => 'warning',
            default => 'success',
        };
    }
}
