<?php

namespace App\Filament\Widgets;

use App\Services\ChannelPerformanceReportService;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class ChannelPerformanceTableWidget extends BaseWidget
{
    protected static ?string $heading = 'أداء القنوات التفصيلي';
    protected static ?string $description = 'تحليل شامل لأداء جميع قنوات البيع والمقارنة بينها';

    protected int | string | array $columnSpan = 'full';
    protected static bool $isLazy = false;

    use InteractsWithPageFilters;

    protected ChannelPerformanceReportService $channelReportService;

    public function boot(): void
    {
        $this->channelReportService = app(ChannelPerformanceReportService::class);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(\App\Models\Order::query()->whereRaw('1 = 0')) // Empty query, we'll override getTableRecords
            ->columns([
                Tables\Columns\TextColumn::make('type_label')
                    ->label('قناة البيع')
                    ->sortable()
                    ->weight('bold')
                    ->badge()
                    ->color(fn ($record) => match ($record->type_label) {
                        'تناول في المكان' => 'success',
                        'إستلام' => 'info',
                        'توصيل' => 'warning',
                        'توصيل ويب' => 'primary',
                        'إستلام ويب' => 'secondary',
                        'طلبات' => 'danger',
                        'شركات' => 'gray',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('total_orders')
                    ->label('إجمالي الطلبات')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => number_format($state))
                    ->color('primary'),

                Tables\Columns\TextColumn::make('total_sales')
                    ->label('إجمالي المبيعات')
                    ->sortable()
                    ->money('EGP')
                    ->color('success'),

                Tables\Columns\TextColumn::make('total_profit')
                    ->label('إجمالي الأرباح')
                    ->sortable()
                    ->money('EGP')
                    ->color('warning'),

                Tables\Columns\TextColumn::make('unique_customers')
                    ->label('عدد العملاء')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => number_format($state))
                    ->color('info'),

                Tables\Columns\TextColumn::make('avg_order_value')
                    ->label('متوسط قيمة الطلب')
                    ->sortable()
                    ->money('EGP'),

                Tables\Columns\TextColumn::make('market_share')
                    ->label('الحصة السوقية')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => number_format($state, 1) . '%')
                    ->badge()
                    ->color(fn ($record) => match (true) {
                        $record->market_share >= 30 => 'success',
                        $record->market_share >= 20 => 'warning',
                        $record->market_share >= 10 => 'info',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('profit_margin_percentage')
                    ->label('هامش الربح')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => number_format($state, 1) . '%')
                    ->badge()
                    ->color(fn ($record) => match (true) {
                        $record->profit_margin_percentage >= 25 => 'success',
                        $record->profit_margin_percentage >= 15 => 'warning',
                        $record->profit_margin_percentage >= 10 => 'info',
                        default => 'danger',
                    }),

                Tables\Columns\TextColumn::make('efficiency_score')
                    ->label('نقاط الكفاءة')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => number_format($state, 1))
                    ->badge()
                    ->color(fn ($record) => match (true) {
                        $record->efficiency_score >= 120 => 'success',
                        $record->efficiency_score >= 100 => 'warning',
                        $record->efficiency_score >= 80 => 'info',
                        default => 'danger',
                    }),

                Tables\Columns\TextColumn::make('avg_revenue_per_customer')
                    ->label('متوسط الإيراد/عميل')
                    ->sortable()
                    ->money('EGP'),

                Tables\Columns\TextColumn::make('avg_orders_per_customer')
                    ->label('متوسط الطلبات/عميل')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => number_format($state, 1)),
            ])
            ->defaultSort('total_sales', 'desc')
            ->filters([
                Tables\Filters\Filter::make('high_performance')
                    ->label('أداء عالي (حصة سوقية >= 20%)')
                    ->query(fn ($query) => $query->where('market_share', '>=', 20)),

                Tables\Filters\Filter::make('high_profit_margin')
                    ->label('هامش ربح عالي (>= 20%)')
                    ->query(fn ($query) => $query->where('profit_margin_percentage', '>=', 20)),

                Tables\Filters\Filter::make('high_efficiency')
                    ->label('كفاءة عالية (>= 110)')
                    ->query(fn ($query) => $query->where('efficiency_score', '>=', 110)),
            ])
            ->actions([
                Tables\Actions\Action::make('analyze')
                    ->label('تحليل تفصيلي')
                    ->icon('heroicon-o-chart-bar')
                    ->color('info')
                    ->action(function ($record) {
                        $this->dispatch('notify', [
                            'type' => 'info',
                            'message' => "تحليل تفصيلي لقناة: {$record->type_label}"
                        ]);
                    }),
            ]);
    }

    public function getTableRecords(): \Illuminate\Database\Eloquent\Collection
    {
        $startDate = $this->filters['startDate'] ?? now()->subDays(29)->startOfDay()->toDateString();
        $endDate = $this->filters['endDate'] ?? now()->endOfDay()->toDateString();

        $metrics = $this->channelReportService->getChannelEfficiencyMetrics($startDate, $endDate);

        return collect($metrics['channel_summary']);
    }
}
