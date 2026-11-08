<?php

namespace App\Filament\Widgets;

use App\Models\KitchenTicketItem;
use App\Services\KitchenPerformanceReportService;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Collection;

class KitchenPerformanceSlowestProductsWidget extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static ?string $heading = 'أبطأ المنتجات في التحضير';

    protected static ?string $description = 'مرتبة من الأبطأ إلى الأسرع حسب متوسط وقت التحضير، دون البنود المنتهية تلقائياً';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    protected KitchenPerformanceReportService $reportService;

    public function boot(): void
    {
        $this->reportService = app(KitchenPerformanceReportService::class);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(KitchenTicketItem::query()->whereRaw('1 = 0'))
            ->columns([
                TextColumn::make('product_name')
                    ->label('المنتج')
                    ->weight('bold'),

                TextColumn::make('count')
                    ->label('عدد البنود')
                    ->formatStateUsing(fn ($state): string => number_format((int) $state).' بند')
                    ->badge()
                    ->color('info'),

                TextColumn::make('avg_prep_seconds')
                    ->label('متوسط وقت التحضير')
                    ->formatStateUsing(fn ($state): string => KitchenPerformanceReportService::formatDuration((float) $state))
                    ->color('primary')
                    ->weight('semibold'),

                TextColumn::make('max_prep_seconds')
                    ->label('أطول وقت تحضير')
                    ->formatStateUsing(fn ($state): string => KitchenPerformanceReportService::formatDuration((float) $state))
                    ->color('warning'),

                TextColumn::make('late_items_percent')
                    ->label('نسبة البنود المتأخرة')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 1).'%')
                    ->badge()
                    ->color(fn ($state): string => $this->lateColor((float) $state)),
            ])
            ->paginated(false);
    }

    public function getTableRecords(): Collection
    {
        $rows = $this->reportService->getProductPerformance(
            $this->pageFilters['startDate'] ?? now()->subDays(29)->startOfDay()->toDateString(),
            $this->pageFilters['endDate'] ?? now()->endOfDay()->toDateString(),
            $this->stationId(),
        );

        $arrayKeyName = ArrayRecord::getKeyName();

        return collect($rows)
            ->values()
            ->map(fn (array $row, int $index): array => [
                $arrayKeyName => 'product-'.$index,
                'product_name' => $row['product_name'],
                'count' => $row['count'],
                'avg_prep_seconds' => $row['avg_prep_seconds'],
                'max_prep_seconds' => $row['max_prep_seconds'],
                'late_items_percent' => $row['late_items_percent'],
            ]);
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
