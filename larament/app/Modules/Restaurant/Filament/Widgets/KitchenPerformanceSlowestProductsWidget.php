<?php

namespace App\Modules\Restaurant\Filament\Widgets;

use App\Models\KitchenTicketItem;
use App\Modules\Restaurant\Services\KitchenPerformanceReportService;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class KitchenPerformanceSlowestProductsWidget extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static ?string $heading = 'أبطأ المنتجات في التحضير';

    protected static ?string $description = 'مرتبة من الأبطأ إلى الأسرع حسب متوسط وقت التحضير من وصول الصنف للمطبخ حتى الانتهاء، دون البنود المنتهية تلقائياً';

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
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),

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
                    ->color('primary')
                    ->weight('semibold'),

                TextColumn::make('max_prep_seconds')
                    ->label('أطول وقت تحضير')
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => KitchenPerformanceReportService::formatDuration((float) $state))
                    ->color('warning'),

                TextColumn::make('late_items_percent')
                    ->label('نسبة البنود المتأخرة')
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 1).'%')
                    ->badge()
                    ->color(fn ($state): string => $this->lateColor((float) $state))
                    ->tooltip('نسبة البنود التي تجاوز وقت تحضيرها الوقت المستهدف المحدد لها'),
            ])
            ->filters([
                Filter::make('late_only')
                    ->label('المنتجات التي بها تأخير فقط'),
            ])
            ->defaultSort('avg_prep_seconds', 'desc')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50, 100]);
    }

    public function getTableRecords(): Collection|Paginator|CursorPaginator
    {
        $rows = $this->reportService->getProductPerformance(
            $this->pageFilters['startDate'] ?? now()->subDays(29)->startOfDay()->toDateString(),
            $this->pageFilters['endDate'] ?? now()->endOfDay()->toDateString(),
            $this->stationId(),
        );

        // Global search
        $search = trim((string) ($this->getTableSearch() ?? ''));
        if ($search !== '') {
            $searchLower = mb_strtolower($search);
            $rows = array_values(array_filter($rows, function (array $row) use ($searchLower): bool {
                return str_contains(mb_strtolower((string) $row['product_name']), $searchLower);
            }));
        }

        // Filter: late only
        $lateOnly = (bool) ($this->getTableFilterState('late_only')['isActive'] ?? false);
        if ($lateOnly) {
            $rows = array_values(array_filter($rows, fn (array $row): bool => (float) $row['late_items_percent'] > 0));
        }

        // Sorting
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

        $perPage = (int) ($this->getTableRecordsPerPage() ?: 10);
        $page = (int) $this->getTablePage();
        $total = count($rows);

        $slice = array_slice($rows, max(0, ($page - 1) * $perPage), $perPage);
        $arrayKeyName = ArrayRecord::getKeyName();

        $items = collect($slice)
            ->values()
            ->map(fn (array $row, int $index): array => [
                $arrayKeyName => 'product-'.(($page - 1) * $perPage + $index),
                'product_name' => $row['product_name'],
                'count' => $row['count'],
                'avg_prep_seconds' => $row['avg_prep_seconds'],
                'max_prep_seconds' => $row['max_prep_seconds'],
                'late_items_percent' => $row['late_items_percent'],
            ]);

        return new LengthAwarePaginator(
            items: $items,
            total: $total,
            perPage: $perPage,
            currentPage: $page,
            options: [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'pageName' => $this->getTablePaginationPageName(),
            ]
        );
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
