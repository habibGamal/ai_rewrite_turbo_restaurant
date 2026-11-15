<?php

namespace App\Modules\Restaurant\Filament\Widgets;

use App\Models\KitchenTicket;
use App\Orders\OrderTypeCatalog;
use App\Modules\Restaurant\Services\KitchenPerformanceReportService;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Collection;

class KitchenPerformanceOrderTypesWidget extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static ?string $heading = 'أداء المطبخ حسب نوع الطلب';

    protected static ?string $description = 'متوسط وقت المطبخ ونسبة الجولات المتأخرة لكل نوع طلب';

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
            ->query(KitchenTicket::query()->whereRaw('1 = 0'))
            ->columns([
                TextColumn::make('label')
                    ->label('نوع الطلب')
                    ->weight('bold')
                    ->badge()
                    ->sortable()
                    ->color(fn (string $state): string => $this->typeColor($state)),

                TextColumn::make('count')
                    ->label('عدد الطلبات')
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => number_format((int) $state).' طلب')
                    ->badge()
                    ->color('info'),

                TextColumn::make('avg_kitchen_seconds')
                    ->label('متوسط وقت المطبخ')
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => KitchenPerformanceReportService::formatDuration((float) $state))
                    ->color('primary')
                    ->weight('semibold'),

                TextColumn::make('late_rounds_percent')
                    ->label('نسبة الجولات المتأخرة')
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 1).'%')
                    ->badge()
                    ->color(fn ($state): string => $this->lateColor((float) $state))
                    ->tooltip('نسبة الجولات التي تجاوزت أكبر وقت تحضير مستهدف لبنودها غير الملغاة'),
            ])
            ->defaultSort('count', 'desc')
            ->paginated(false);
    }

    public function getTableRecords(): Collection
    {
        $rows = $this->reportService->getOrderTypePerformance(
            $this->pageFilters['startDate'] ?? now()->subDays(29)->startOfDay()->toDateString(),
            $this->pageFilters['endDate'] ?? now()->endOfDay()->toDateString(),
            $this->stationId(),
        );

        $sortColumn = $this->getTableSortColumn() ?: 'count';
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
                $arrayKeyName => 'order-type-'.$index,
                'label' => $row['label'],
                'count' => $row['count'],
                'avg_kitchen_seconds' => $row['avg_kitchen_seconds'],
                'late_rounds_percent' => $row['late_rounds_percent'],
            ]);
    }

    private function stationId(): ?int
    {
        $stationId = $this->pageFilters['kitchen_station_id'] ?? null;

        return $stationId === null || $stationId === '' ? null : (int) $stationId;
    }

    private function typeColor(string $label): string
    {
        foreach (app(OrderTypeCatalog::class)->all() as $type) {
            if ($type->label === $label) {
                return $type->color;
            }
        }

        return 'gray';
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
