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

    protected static ?string $description = 'عدد البنود ومتوسط وقت التحضير لكل محطة من وصول الصنف للمطبخ حتى الانتهاء، والبنود بلا محطة تظهر باسم «عام»';

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
                    ->sortable()
                    ->color(fn (string $state): string => $state === 'عام' ? 'gray' : 'primary'),

                TextColumn::make('count')
                    ->label('عدد البنود')
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => number_format((int) $state).' بند')
                    ->badge()
                    ->color('info'),

                TextColumn::make('avg_prep_seconds')
                    ->label('متوسط وقت التحضير')
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => KitchenPerformanceReportService::formatDuration((float) $state))
                    ->color('success')
                    ->weight('semibold'),
            ])
            ->defaultSort('avg_prep_seconds', 'desc')
            ->paginated(false);
    }

    public function getTableRecords(): Collection
    {
        $rows = $this->reportService->getStationPerformance(
            $this->pageFilters['startDate'] ?? now()->subDays(29)->startOfDay()->toDateString(),
            $this->pageFilters['endDate'] ?? now()->endOfDay()->toDateString(),
            $this->stationId(),
        );

        $sortColumn = $this->getTableSortColumn() ?: 'avg_prep_seconds';
        $sortDirection = strtolower((string) ($this->getTableSortDirection() ?: 'desc'));

        usort($rows, function (array $a, array $b) use ($sortColumn, $sortDirection): int {
            $valA = $a[$sortColumn] ?? null;
            $valB = $b[$sortColumn] ?? null;

            if ($valA === $valB) {
                return 0;
            }

            $cmp = is_numeric($valA) && is_numeric($valB)
                ? ((float) $valA <=> (float) $valB)
                : strcmp((string) $valA, (string) $valB);

            return $sortDirection === 'desc' ? -$cmp : $cmp;
        });

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
