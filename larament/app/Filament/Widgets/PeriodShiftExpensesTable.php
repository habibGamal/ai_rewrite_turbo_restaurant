<?php

namespace App\Filament\Widgets;

use App\Services\ShiftsReportService;
use App\Models\ExpenceType;
use App\Models\Expense;
use App\Filament\Exports\PeriodShiftExpensesExporter;
use App\Filament\Exports\PeriodShiftExpensesDetailedExporter;
use Carbon\Carbon;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Actions\ExportAction;
use Filament\Widgets\TableWidget as BaseWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class PeriodShiftExpensesTable extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'اجمالي المصاريف';

    protected ShiftsReportService $shiftsReportService;

    public function boot(): void
    {
        $this->shiftsReportService = app(ShiftsReportService::class);
    }

    public function table(Table $table): Table
    {
        $filterType = $this->filters['filterType'] ?? 'period';

        if ($filterType === 'shifts') {
            $shiftIds = $this->filters['shifts'] ?? [];
            $totalExpensesOverPeriod = Expense::query()
                ->when(!empty($shiftIds), function (Builder $query) use ($shiftIds) {
                    return $query->whereIn('shift_id', $shiftIds);
                })
                ->sum('amount');

            $expenseTypeQuery = ExpenceType::query()
                ->withCount([
                    'expenses' => function ($query) use ($shiftIds) {
                        $query->when(!empty($shiftIds), function (Builder $query) use ($shiftIds) {
                            return $query->whereIn('shift_id', $shiftIds);
                        });
                    }
                ])
                ->withSum([
                    'expenses' => function ($query) use ($shiftIds) {
                        $query->when(!empty($shiftIds), function (Builder $query) use ($shiftIds) {
                            return $query->whereIn('shift_id', $shiftIds);
                        });
                    }
                ], 'amount');

            $detailedExportQuery = function (Builder $query) use ($shiftIds) {
                return Expense::query()
                    ->when(!empty($shiftIds), function (Builder $query) use ($shiftIds) {
                        return $query->whereIn('shift_id', $shiftIds);
                    })
                    ->with(['expenceType', 'shift'])
                    ->orderBy('created_at', 'desc');
            };
        } else {
            $startDate = $this->filters['startDate'];
            $endDate = $this->filters['endDate'];
            $totalExpensesOverPeriod = Expense::query()
                ->whereHas('shift', function (Builder $query) use ($startDate, $endDate) {
                    $query->whereBetween('created_at', [
                        Carbon::parse($startDate)->startOfDay(),
                        Carbon::parse($endDate)->endOfDay()
                    ]);
                })->sum('amount');

            $expenseTypeQuery = ExpenceType::query()
                ->withCount([
                    'expenses' => function ($query) use ($startDate, $endDate) {
                        $query->whereHas('shift', function (Builder $query) use ($startDate, $endDate) {
                            $query->whereBetween('created_at', [
                                Carbon::parse($startDate)->startOfDay(),
                                Carbon::parse($endDate)->endOfDay()
                            ]);
                        });
                    }
                ])
                ->withSum([
                    'expenses' => function ($query) use ($startDate, $endDate) {
                        $query->whereHas('shift', function (Builder $query) use ($startDate, $endDate) {
                            $query->whereBetween('created_at', [
                                Carbon::parse($startDate)->startOfDay(),
                                Carbon::parse($endDate)->endOfDay()
                            ]);
                        });
                    }
                ], 'amount');

            $detailedExportQuery = function (Builder $query) use ($startDate, $endDate) {
                return Expense::query()
                    ->whereHas('shift', function (Builder $query) use ($startDate, $endDate) {
                        $query->whereBetween('created_at', [
                            Carbon::parse($startDate)->startOfDay(),
                            Carbon::parse($endDate)->endOfDay()
                        ]);
                    })->with(['expenceType', 'shift'])
                    ->orderBy('created_at', 'desc');
            };
        }

        return $table
            ->query($expenseTypeQuery)
            ->headerActions([
                ExportAction::make()
                    ->label('تصدير ملخص المصروفات')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->exporter(PeriodShiftExpensesExporter::class)
                    ->fileName(fn() => 'period-shift-expenses-summary-' . now()->format('Y-m-d-H-i-s') . '.xlsx'),

                ExportAction::make('detailed_export')
                    ->label('تصدير تفاصيل المصروفات')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('info')
                    ->exporter(PeriodShiftExpensesDetailedExporter::class)
                    ->modifyQueryUsing($detailedExportQuery)
                    ->fileName(fn() => 'period-shift-expenses-detailed-' . now()->format('Y-m-d-H-i-s') . '.xlsx'),
            ])
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('نوع المصروف')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->color('primary'),

                Tables\Columns\TextColumn::make('expenses_count')
                    ->label('عدد المصروفات')
                    ->alignCenter()
                    ->sortable()
                    ->color('info'),

                Tables\Columns\TextColumn::make('expenses_sum_amount')
                    ->label('الإجمالي')
                    ->money('EGP')
                    ->alignCenter()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('average_amount')
                    ->label('متوسط المبلغ')
                    ->state(function ($record) {
                        $count = $record->expenses_count ?? 0;
                        $total = $record->expenses_sum_amount ?? 0;
                        $average = $count > 0 ? $total / $count : 0;
                        return number_format($average, 2) . ' جنيه';
                    })
                    ->alignCenter()
                    ->color('warning'),

                Tables\Columns\TextColumn::make('percentage')
                    ->label('النسبة المئوية')
                    ->state(function ($record) use ($totalExpensesOverPeriod) {
                        // Calculate total expenses for percentage
                        $currentAmount = $record->expenses_sum_amount ?? 0;
                        $percentage = $totalExpensesOverPeriod > 0 ? ($currentAmount / $totalExpensesOverPeriod) * 100 : 0;
                        return number_format($percentage, 1) . '%';
                    })
                    ->alignCenter()
                    ->color('primary'),
            ])
            ->striped()
            ->paginated([10, 25, 50])
            ->emptyStateHeading('لا توجد مصروفات')
            ->emptyStateDescription('لم يتم العثور على أي مصروفات في الفترة المحددة.')
            ->emptyStateIcon('heroicon-o-banknotes')
            ->recordAction(null)
            ->recordUrl(null)
            ->bulkActions([])
        ;
    }


}
