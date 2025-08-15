<?php

namespace App\Filament\Widgets;

use App\Services\ShiftsReportService;
use App\Models\Expense;
use App\Filament\Exports\CurrentShiftExpensesDetailedExporter;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Actions\ExportAction;
use Filament\Widgets\TableWidget as BaseWidget;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Builder;

class CurrentShiftExpensesDetailsTable extends BaseWidget
{
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'تفاصيل مصاريف الشفت الحالي';

    protected ShiftsReportService $shiftsReportService;

    public function boot(): void
    {
        $this->shiftsReportService = app(ShiftsReportService::class);
    }

    public function table(Table $table): Table
    {
        $currentShift = $this->getCurrentShift();

        if (!$currentShift) {
            $expenseQuery = Expense::query()->where('id', 0); // Empty query
        } else {
            $expenseQuery = Expense::query()
                ->where('shift_id', $currentShift->id)
                ->with(['expenceType', 'shift.user'])
                ->orderBy('created_at', 'desc');
        }

        return $table
            ->query($expenseQuery)
            ->headerActions([
                ExportAction::make()
                    ->label('تصدير تفاصيل المصروفات')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->extraAttributes([
                        'id' => 'expenses_table',
                    ])
                    ->exporter(CurrentShiftExpensesDetailedExporter::class)
                    ->fileName(fn() => 'current-shift-expenses-detailed-' . now()->format('Y-m-d-H-i-s') . '.xlsx')
                    ->visible(fn() => $this->getCurrentShift() !== null),
            ])
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('رقم المصروف')
                    ->sortable()
                    ->searchable()
                    ->weight('medium')
                    ->color('primary'),

                Tables\Columns\TextColumn::make('expenceType.name')
                    ->label('نوع المصروف')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->color('info'),

                Tables\Columns\TextColumn::make('amount')
                    ->label('المبلغ')
                    ->money('EGP')
                    ->alignCenter()
                    ->sortable()
                    ->weight('bold')
                    ->color('danger'),

                Tables\Columns\TextColumn::make('notes')
                    ->label('ملاحظات')
                    ->searchable()
                    ->limit(50)
                    ->tooltip(function ($record) {
                        return $record->notes;
                    })
                    ->placeholder('لا توجد ملاحظات')
                    ->wrap(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('وقت الإنشاء')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->alignCenter()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('آخر تحديث')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->alignCenter()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->striped()
            ->paginated([10, 25, 50])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('لا توجد مصروفات')
            ->emptyStateDescription('لم يتم تسجيل أي مصروفات في الشفت الحالي.')
            ->emptyStateIcon('heroicon-o-banknotes')
            ->recordAction(null)
            ->recordUrl(null)
            ->bulkActions([])
            ->filters([
                Tables\Filters\SelectFilter::make('expenceType')
                    ->label('نوع المصروف')
                    ->relationship('expenceType', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\Filter::make('amount_range')
                    ->label('نطاق المبلغ')
                    ->form([
                        TextInput::make('min_amount')
                            ->label('أقل مبلغ')
                            ->numeric()
                            ->placeholder('0.00'),
                        TextInput::make('max_amount')
                            ->label('أعلى مبلغ')
                            ->numeric()
                            ->placeholder('1000.00'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['min_amount'],
                                fn (Builder $query, $minAmount): Builder => $query->where('amount', '>=', $minAmount),
                            )
                            ->when(
                                $data['max_amount'],
                                fn (Builder $query, $maxAmount): Builder => $query->where('amount', '<=', $maxAmount),
                            );
                    }),

                Tables\Filters\Filter::make('today')
                    ->label('اليوم فقط')
                    ->query(fn (Builder $query): Builder => $query->whereDate('created_at', today()))
                    ->toggle(),
            ])
            ->poll('30s'); // Auto-refresh every 30 seconds for live updates
    }

    private function getCurrentShift()
    {
        return $this->shiftsReportService->getCurrentShift();
    }
}
