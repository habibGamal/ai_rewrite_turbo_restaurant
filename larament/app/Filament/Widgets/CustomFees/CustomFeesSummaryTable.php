<?php

namespace App\Filament\Widgets\CustomFees;

use App\Services\CustomFeesReportService;
use App\Services\FeatureService;
use App\Services\ShiftsReportService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;

class CustomFeesSummaryTable extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'الرسوم حسب الاسم';

    public function table(Table $table): Table
    {
        $scope = app(ShiftsReportService::class)->shiftIdsQueryFromFilters($this->pageFilters);

        return $table
            ->records(fn (): array => app(CustomFeesReportService::class)->summaryByName($scope))
            ->columns([
                TextColumn::make('name')->label('اسم الرسوم')->weight('medium')->color('primary'),
                TextColumn::make('count')->label('عدد المرات')->alignCenter()->color('info'),
                TextColumn::make('amount')->label('الإجمالي')->money('EGP')->alignCenter()->weight('bold'),
                TextColumn::make('average')->label('متوسط القيمة')->money('EGP')->alignCenter(),
                TextColumn::make('refunded')->label('المرتجع')->money('EGP')->alignCenter()->color('danger')->visible(fn (): bool => app(FeatureService::class)->returnsEnabled()),
                TextColumn::make('net')->label('الصافي')->money('EGP')->alignCenter()->weight('bold')->color('success'),
                TextColumn::make('share')->label('النسبة')->suffix('%')->alignCenter(),
            ])
            ->paginated(false)
            ->emptyStateHeading('لا توجد رسوم إضافية')
            ->emptyStateDescription('لم تُسجل رسوم إضافية على طلبات مكتملة في الفترة المحددة.')
            ->emptyStateIcon('heroicon-o-plus-circle');
    }
}
