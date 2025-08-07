<?php

namespace App\Filament\Widgets;

use App\Services\ShiftsReportService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Carbon\Carbon;

class PeriodShiftInfoStats extends BaseWidget
{
    use InteractsWithPageFilters;

    protected ShiftsReportService $shiftsReportService;

    public function boot(): void
    {
        $this->shiftsReportService = app(ShiftsReportService::class);
    }

    public function getHeading(): string
    {
        $periodInfo = $this->getPeriodInfo();
        return $periodInfo['title'];
    }

    protected function getStats(): array
    {
        $shifts = $this->getShifts();

        if ($shifts->isEmpty()) {
            return [];
        }

        $periodInfo = $this->getPeriodInfo();
        $totalShifts = $shifts->count();
        $firstShift = $shifts->last(); // oldest shift (ordered by desc)
        $lastShift = $shifts->first(); // newest shift

        $totalDuration = 0;
        $totalStartCash = 0;
        $uniqueUsers = [];

        foreach ($shifts as $shift) {
            // Calculate duration for closed shifts
            if ($shift->closed && $shift->end_at) {
                $start = Carbon::parse($shift->start_at);
                $end = Carbon::parse($shift->end_at);
                $totalDuration += $start->diffInMinutes($end);
            }

            $totalStartCash += (float) $shift->start_cash;

            if ($shift->user) {
                $uniqueUsers[$shift->user->id] = $shift->user->name;
            }
        }

        $avgDuration = $totalShifts > 0 ? $totalDuration / $totalShifts : 0;
        $avgStartCash = $totalShifts > 0 ? $totalStartCash / $totalShifts : 0;

        return [
            Stat::make('عدد الشفتات', $totalShifts . ' شفت')
                ->description($periodInfo['description'])
                ->descriptionIcon('heroicon-m-clock')
                ->color('info'),

            Stat::make('متوسط مدة الشفت', $this->formatDuration($avgDuration))
                ->description('متوسط مدة الشفت الواحد')
                ->descriptionIcon('heroicon-m-play')
                ->color('warning'),

            Stat::make('متوسط النقدية البدائية', number_format($avgStartCash, 2) . ' جنيه')
                ->description('متوسط النقدية في بداية الشفتات')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),

            Stat::make('عدد الموظفين', count($uniqueUsers) . ' موظف')
                ->description('الموظفون المسؤولون عن الشفتات')
                ->descriptionIcon('heroicon-m-user-group')
                ->color('primary'),
        ];
    }

    private function getShifts()
    {
        $startDate = $this->filters['startDate'] ?? now()->subDays(7)->startOfDay()->toDateString();
        $endDate = $this->filters['endDate'] ?? now()->endOfDay()->toDateString();

        return $this->shiftsReportService->getShiftsInPeriod($startDate, $endDate);
    }

    private function getPeriodInfo(): array
    {
        $startDate = $this->filters['startDate'] ?? now()->subDays(7)->startOfDay()->toDateString();
        $endDate = $this->filters['endDate'] ?? now()->endOfDay()->toDateString();

        return $this->shiftsReportService->getPeriodInfo($startDate, $endDate);
    }

    private function formatDuration(float $minutes): string
    {
        if ($minutes <= 0) {
            return '0 دقيقة';
        }

        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d ساعة', $hours, $remainingMinutes);
        }

        return sprintf('%d دقيقة', $remainingMinutes);
    }
}
