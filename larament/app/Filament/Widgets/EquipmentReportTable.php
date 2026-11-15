<?php

namespace App\Filament\Widgets;

use App\Services\Reports\EquipmentReportService;
use App\Services\ShiftsReportService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;

class EquipmentReportTable extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'المعدات';

    public function table(Table $table): Table
    {
        $scope = app(ShiftsReportService::class)->shiftIdsQueryFromFilters($this->pageFilters);
        $query = app(EquipmentReportService::class)->perEquipmentQuery($scope, $this->pageFilters['order_type'] ?? null);

        return $table
            ->query($query)
            ->columns([
                TextColumn::make('name')
                    ->label('المعدة')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('orders_count')
                    ->label('عدد الطلبات')
                    ->alignCenter()
                    ->sortable(),
                TextColumn::make('uses')
                    ->label('مرات الاستخدام')
                    ->state(fn ($record): int => (int) ($record->uses ?? 0))
                    ->alignCenter()
                    ->sortable(),
                TextColumn::make('revenue')
                    ->label('الإيراد')
                    ->state(fn ($record): float => (float) ($record->revenue ?? 0))
                    ->money('EGP')
                    ->alignCenter()
                    ->sortable(),
                TextColumn::make('refunded')
                    ->label('المرتجع')
                    ->state(fn ($record): float => (float) ($record->refunded ?? 0))
                    ->description(fn ($record): string => ((int) ($record->refunded_uses ?? 0)).' استخدام')
                    ->money('EGP')
                    ->alignCenter()
                    ->color('danger')
                    ->sortable(),
                TextColumn::make('net_revenue')
                    ->label('صافي الإيراد')
                    ->state(fn ($record): float => (float) ($record->revenue ?? 0) - (float) ($record->refunded ?? 0))
                    ->money('EGP')
                    ->alignCenter(),
                TextColumn::make('cost')
                    ->label('التكلفة')
                    ->state(fn ($record): float => (float) ($record->cost ?? 0))
                    ->money('EGP')
                    ->alignCenter()
                    ->sortable(),
                TextColumn::make('profit')
                    ->label('الربح')
                    ->state(fn ($record): float => (float) ($record->revenue ?? 0) - (float) ($record->refunded ?? 0) - (float) ($record->cost ?? 0))
                    ->money('EGP')
                    ->alignCenter()
                    ->weight('bold'),
            ])
            ->defaultSort('sort_order')
            ->striped()
            ->paginated([10, 25, 50])
            ->emptyStateHeading('لا توجد معدات')
            ->recordAction(null)
            ->recordUrl(null);
    }
}
