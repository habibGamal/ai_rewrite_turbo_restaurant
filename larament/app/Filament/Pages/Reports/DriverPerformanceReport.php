<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Traits\AdminAccess;
use App\Models\Shift;
use App\Services\ShiftsReportService;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\DatePicker;

class DriverPerformanceReport extends BaseDashboard
{
    use HasFiltersForm, AdminAccess;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static string $routePath = 'driver-performance-report';

    protected static ?string $navigationGroup = 'التقارير';

    protected static ?string $navigationLabel = 'تقرير أداء السائقين';

    protected static ?string $title = 'تقرير أداء السائقين';

    protected static ?int $navigationSort = 5;

    protected ShiftsReportService $shiftsReportService;

    public function boot(): void
    {
        $this->shiftsReportService = app(ShiftsReportService::class);
    }

    public function filtersForm(Form $form): Form
    {
        return $form
            ->schema([
                Radio::make('filterType')
                    ->label('نوع التصفية')
                    ->options([
                        'period' => 'فترة زمنية',
                        'shifts' => 'شفتات محددة',
                    ])
                    ->default('period')
                    ->inline()
                    ->reactive(),

                Section::make('اختيار الشفتات')
                    ->description('اختر الشفتات المحددة')
                    ->schema([
                        Select::make('shifts')
                            ->label('الشفتات')
                            ->options(function () {
                                return Shift::with('user')
                                    ->orderBy('start_at', 'desc')
                                    ->get()
                                    ->mapWithKeys(function ($shift) {
                                        $userLabel = $shift->user ? $shift->user->name : 'غير محدد';
                                        $startDate = $shift->start_at ? $shift->start_at->format('d/m/Y H:i') : 'غير محدد';
                                        $endDate = $shift->end_at ? $shift->end_at->format('d/m/Y H:i') : 'لم ينته';

                                        return [
                                            $shift->id => "شفت #{$shift->id} - {$userLabel} ({$startDate} - {$endDate})"
                                        ];
                                    });
                            })
                            ->searchable()
                            ->placeholder('اختر الشفتات')
                            ->multiple()
                            ->preload(),
                    ])
                    ->visible(fn (callable $get) => $get('filterType') === 'shifts'),

                Section::make('فترة التقرير')
                    ->description('اختر الفترة الزمنية لعرض تقارير أداء السائقين')
                    ->schema([
                        Select::make('presetPeriod')
                            ->label('فترات محددة مسبقاً')
                            ->options([
                                'today' => 'اليوم',
                                'yesterday' => 'أمس',
                                'last_7_days' => 'آخر 7 أيام',
                                'last_30_days' => 'آخر 30 يوم',
                                'this_week' => 'هذا الأسبوع',
                                'last_week' => 'الأسبوع الماضي',
                                'this_month' => 'هذا الشهر',
                                'last_month' => 'الشهر الماضي',
                                'this_year' => 'هذا العام',
                                'custom' => 'فترة مخصصة',
                            ])
                            ->default('last_7_days')
                            ->live()
                            ->afterStateUpdated(function (callable $set, $state) {
                                match ($state) {
                                    'today' => [
                                        $set('startDate', now()->startOfDay()->toDateString()),
                                        $set('endDate', now()->endOfDay()->toDateString())
                                    ],
                                    'yesterday' => [
                                        $set('startDate', now()->subDay()->startOfDay()->toDateString()),
                                        $set('endDate', now()->subDay()->endOfDay()->toDateString())
                                    ],
                                    'last_7_days' => [
                                        $set('startDate', now()->subDays(6)->startOfDay()->toDateString()),
                                        $set('endDate', now()->endOfDay()->toDateString())
                                    ],
                                    'last_30_days' => [
                                        $set('startDate', now()->subDays(29)->startOfDay()->toDateString()),
                                        $set('endDate', now()->endOfDay()->toDateString())
                                    ],
                                    'this_week' => [
                                        $set('startDate', now()->startOfWeek()->toDateString()),
                                        $set('endDate', now()->endOfWeek()->toDateString())
                                    ],
                                    'last_week' => [
                                        $set('startDate', now()->subWeek()->startOfWeek()->toDateString()),
                                        $set('endDate', now()->subWeek()->endOfWeek()->toDateString())
                                    ],
                                    'this_month' => [
                                        $set('startDate', now()->startOfMonth()->toDateString()),
                                        $set('endDate', now()->endOfMonth()->toDateString())
                                    ],
                                    'last_month' => [
                                        $set('startDate', now()->subMonth()->startOfMonth()->toDateString()),
                                        $set('endDate', now()->subMonth()->endOfMonth()->toDateString())
                                    ],
                                    'this_year' => [
                                        $set('startDate', now()->startOfYear()->toDateString()),
                                        $set('endDate', now()->endOfYear()->toDateString())
                                    ],
                                    default => null
                                };
                            }),
                        DatePicker::make('startDate')
                            ->label('تاريخ البداية')
                            ->default(now()->subDays(6)->startOfDay())
                            ->maxDate(now())
                            ->disabled(fn (callable $get) => $get('presetPeriod') !== 'custom')
                            ->live(),
                        DatePicker::make('endDate')
                            ->label('تاريخ النهاية')
                            ->default(now()->endOfDay())
                            ->maxDate(now())
                            ->disabled(fn (callable $get) => $get('presetPeriod') !== 'custom')
                            ->live(),
                    ])
                    ->columns(3)
                    ->visible(fn (callable $get) => $get('filterType') === 'period'),
            ]);
    }

    public function getWidgets(): array
    {
        $filterType = $this->filters['filterType'] ?? 'period';
        $shiftsCount = 0;

        if ($filterType === 'shifts') {
            $shiftIds = $this->filters['shifts'] ?? [];
            $shiftsCount = $this->shiftsReportService->getShiftsCountInPeriod(null, null, $shiftIds);
        } else {
            $startDate = $this->filters['startDate'] ?? now()->subDays(6)->startOfDay()->toDateString();
            $endDate = $this->filters['endDate'] ?? now()->endOfDay()->toDateString();
            $shiftsCount = $this->shiftsReportService->getShiftsCountInPeriod($startDate, $endDate, null);
        }

        if ($shiftsCount === 0) {
            return [
                \App\Filament\Widgets\NoShiftsInPeriodWidget::class,
            ];
        }

        return [
            \App\Filament\Widgets\DriverPerformanceStatsWidget::class,
            \App\Filament\Widgets\DriverPerformanceTable::class,
        ];
    }
}
