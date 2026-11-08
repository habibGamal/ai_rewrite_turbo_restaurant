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

class KitchenPerformanceStationsWidget extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static ?string $heading = 'أداء محطات المطبخ';

    protected static ?string $description = 'عدد البنود ومتوسط وقت التحضير لكل محطة، والبنود بلا محطة تظهر باسم «عام»';

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
                TextColumn::make('station_name')
                    ->label('المحطة')
                    ->weight('bold')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'عام' ? 'gray' : 'primary'),

                TextColumn::make('count')
                    ->label('عدد البنود')
                    ->formatStateUsing(fn ($state): string => number_format((int) $state).' بند')
                    ->badge()
                    ->color('info'),

                TextColumn::make('avg_prep_seconds')
                    ->label('متوسط وقت التحضير')
                    ->formatStateUsing(fn ($state): string => KitchenPerformanceReportService::formatDuration((float) $state))
                    ->color('success')
                    ->weight('semibold'),
            ])
            ->paginated(false);
    }

    public function getTableRecords(): Collection
    {
        $rows = $this->reportService->getStationPerformance(
            $this->pageFilters['startDate'] ?? now()->subDays(29)->startOfDay()->toDateString(),
            $this->pageFilters['endDate'] ?? now()->endOfDay()->toDateString(),
            $this->stationId(),
        );

        $arrayKeyName = ArrayRecord::getKeyName();

        return collect($rows)
            ->values()
            ->map(fn (array $row, int $index): array => [
                $arrayKeyName => 'station-'.$index,
                'station_name' => $row['station_name'],
                'count' => $row['count'],
                'avg_prep_seconds' => $row['avg_prep_seconds'],
            ]);
    }

    private function stationId(): ?int
    {
        $stationId = $this->pageFilters['kitchen_station_id'] ?? null;

        return $stationId === null || $stationId === '' ? null : (int) $stationId;
    }
}
