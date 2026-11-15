<?php

namespace App\Filament\Widgets;

use App\Services\CustomersPerformanceReportService;
use App\Services\Reports\CustomerReportThresholds;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class CustomersAtRiskTableWidget extends BaseWidget
{
    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        $t = app(CustomerReportThresholds::class)->all();

        return $table
            ->heading('عملاء توقفوا عن الطلب')
            ->description(sprintf(
                'عملاء لهم طلبان أو أكثر ولم يطلبوا منذ %s ضعف المدة المعتادة بين طلباتهم (و %s يوم على الأقل). محسوبة على كل التاريخ وليس الفترة المختارة.',
                rtrim(rtrim(number_format($t['churn_gap_multiplier'], 1), '0'), '.'),
                number_format($t['churn_min_days']),
            ))
            ->query(app(CustomersPerformanceReportService::class)->getAtRiskCustomersQuery())
            ->columns([
                TextColumn::make('name')
                    ->label('اسم العميل')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('customers.name', 'like', "%{$search}%")),
                TextColumn::make('phone')
                    ->label('رقم الهاتف')
                    ->copyable()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('customers.phone', 'like', "%{$search}%")),
                TextColumn::make('last_at')
                    ->label('آخر طلب')
                    ->date('Y-m-d')
                    ->sortable(),
                TextColumn::make('days_since_last')
                    ->label('أيام منذ آخر طلب')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state))
                    ->color('danger')
                    ->sortable(),
                TextColumn::make('usual_gap')
                    ->label('المعتاد بين الطلبات (يوم)')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 1))
                    ->sortable(),
                TextColumn::make('orders_count')
                    ->label('عدد الطلبات')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('lifetime_sales')
                    ->label('إجمالي مشترياته')
                    ->money('EGP')
                    ->sortable(),
            ])
            ->defaultSort('lifetime_sales', 'desc')
            ->paginated([5, 10, 25, 50])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading('لا يوجد عملاء متوقفون حالياً')
            ->recordActions([
                ViewAction::make()
                    ->label('عرض العميل')
                    ->url(fn ($record): string => route('filament.admin.resources.customers.view', $record->id))
                    ->openUrlInNewTab(),
            ]);
    }
}
