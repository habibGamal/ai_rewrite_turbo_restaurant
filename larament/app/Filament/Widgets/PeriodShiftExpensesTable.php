<?php

namespace App\Filament\Widgets;

use App\Services\ShiftsReportService;
use App\Models\ExpenceType;
use App\Models\Expense;
use App\Filament\Exports\PeriodShiftExpensesExporter;
use App\Filament\Exports\PeriodShiftExpensesDetailedExporter;
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

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'اجمالي المصاريف';

    protected ShiftsReportService $shiftsReportService;

    public function boot(): void
    {
        $this->shiftsReportService = app(ShiftsReportService::class);
    }

    public function table(Table $table): Table
    {
        $shifts = $this->getShifts();
        $query = $this->shiftsReportService->getExpensesQueryForShifts($shifts);

        return $table
            ->query($query)
            ->headerActions([
                ExportAction::make()
                    ->label('تصدير ملخص المصروفات')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->exporter(PeriodShiftExpensesExporter::class)
                    ->modifyQueryUsing(function (Builder $query) {
                        $shifts = $this->getShifts();
                        return $this->shiftsReportService->getExpensesQueryForShifts($shifts);
                    })
                    ->fileName(fn () => 'period-shift-expenses-summary-' . now()->format('Y-m-d-H-i-s') . '.xlsx')
                    ->visible(fn () => !$this->getShifts()->isEmpty()),

                ExportAction::make('detailed_export')
                    ->label('تصدير تفاصيل المصروفات')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('info')
                    ->exporter(PeriodShiftExpensesDetailedExporter::class)
                    ->modifyQueryUsing(function (Builder $query) {
                        $shifts = $this->getShifts();
                        if ($shifts->isEmpty()) {
                            return Expense::query()->where('id', 0);
                        }

                        $shiftIds = $shifts->pluck('id')->toArray();
                        return Expense::query()
                            ->whereIn('shift_id', $shiftIds)
                            ->with(['expenceType', 'shift'])
                            ->orderBy('created_at', 'desc');
                    })
                    ->fileName(fn () => 'period-shift-expenses-detailed-' . now()->format('Y-m-d-H-i-s') . '.xlsx')
                    ->visible(fn () => !$this->getShifts()->isEmpty()),
            ])
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('نوع المصروف')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->color('primary'),

                Tables\Columns\TextColumn::make('expense_count')
                    ->label('عدد المصروفات')
                    ->alignCenter()
                    ->sortable()
                    ->color('info'),

                Tables\Columns\TextColumn::make('total_amount')
                    ->label('الإجمالي')
                    ->money('EGP')
                    ->alignCenter()
                    ->sortable()
                    ->weight('bold')
                    ->color('danger'),

                Tables\Columns\TextColumn::make('average_amount')
                    ->label('متوسط المبلغ')
                    ->state(function ($record) {
                        $count = $record->expense_count ?? 0;
                        $total = $record->total_amount ?? 0;
                        $average = $count > 0 ? $total / $count : 0;
                        return number_format($average, 2) . ' جنيه';
                    })
                    ->alignCenter()
                    ->color('warning'),

                Tables\Columns\TextColumn::make('percentage')
                    ->label('النسبة المئوية')
                    ->state(function ($record) use ($query) {
                        // Calculate total expenses for percentage
                        $totalExpenses = $this->getTotalExpenses();
                        $currentAmount = $record->total_amount ?? 0;
                        $percentage = $totalExpenses > 0 ? ($currentAmount / $totalExpenses) * 100 : 0;
                        return number_format($percentage, 1) . '%';
                    })
                    ->alignCenter()
                    ->color('primary'),
            ])
            ->defaultSort('total_amount', 'desc')
            ->striped()
            ->paginated([10, 25, 50])
            ->emptyStateHeading('لا توجد مصروفات')
            ->emptyStateDescription('لم يتم العثور على أي مصروفات في الفترة المحددة.')
            ->emptyStateIcon('heroicon-o-banknotes')
            ->recordAction(null)
            ->recordUrl(null)
            ->bulkActions([])
            ->description(new HtmlString($this->getTableDescription()));
    }

    private function getShifts()
    {
        $startDate = $this->filters['startDate'] ?? now()->subDays(7)->startOfDay()->toDateString();
        $endDate = $this->filters['endDate'] ?? now()->endOfDay()->toDateString();

        return $this->shiftsReportService->getShiftsInPeriod($startDate, $endDate);
    }

    private function getTotalExpenses(): float
    {
        $shifts = $this->getShifts();

        if ($shifts->isEmpty()) {
            return 0;
        }

        $shiftIds = $shifts->pluck('id')->toArray();

        return \App\Models\Expense::whereIn('shift_id', $shiftIds)->sum('amount');
    }

    protected function getTableDescription(): string
    {
        $shifts = $this->getShifts();

        if ($shifts->isEmpty()) {
            return 'لا توجد شفتات في الفترة المحددة';
        }

        $totalExpenses = $this->getTotalExpenses();
        $periodInfo = $this->shiftsReportService->getPeriodInfo(
            $this->filters['startDate'] ?? now()->subDays(7)->startOfDay()->toDateString(),
            $this->filters['endDate'] ?? now()->endOfDay()->toDateString()
        );

        return sprintf(
            '<div class="text-sm text-gray-600 dark:text-gray-400 mb-2">%s - إجمالي المصروفات: <span class="font-semibold text-red-600">%s جنيه</span> | عدد الشفتات: <span class="font-semibold">%d شفت</span></div>',
            $periodInfo['description'],
            number_format($totalExpenses, 2),
            $shifts->count()
        );
    }
}
