<?php

namespace App\Filament\Widgets;

use App\Models\KitchenStation;
use App\Models\KitchenTicketItem;
use App\Services\KitchenPerformanceReportService;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class KitchenPerformanceWasteCandidatesWidget extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static ?string $heading = 'بنود مرشحة للهدر';

    protected static ?string $description = 'بنود أُنجزت ثم أُلغيت بعد التحضير، حسب تاريخ إرسال الطلب للمطبخ';

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

                TextColumn::make('quantity')
                    ->label('الكمية')
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 3))
                    ->badge()
                    ->color('info'),

                TextColumn::make('station_name')
                    ->label('المحطة')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color(fn (string $state): string => $state === 'عام' ? 'gray' : 'primary'),

                TextColumn::make('target_minutes')
                    ->label('الوقت المستهدف')
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => number_format((int) $state).' دقيقة')
                    ->color('gray'),

                TextColumn::make('voided_at')
                    ->label('وقت الإلغاء')
                    ->sortable()
                    ->color('danger'),
            ])
            ->filters([
                SelectFilter::make('station_name')
                    ->label('المحطة')
                    ->options(fn (): array => KitchenStation::query()
                        ->orderBy('name')
                        ->pluck('name', 'name')
                        ->all()),
            ])
            ->defaultSort('voided_at', 'desc')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50, 100]);
    }

    public function getTableRecords(): Collection|Paginator|CursorPaginator
    {
        $rows = $this->reportService->getWasteCandidates(
            $this->pageFilters['startDate'] ?? now()->subDays(29)->startOfDay()->toDateString(),
            $this->pageFilters['endDate'] ?? now()->endOfDay()->toDateString(),
            $this->stationId(),
        );

        // Global search
        $search = trim((string) ($this->getTableSearch() ?? ''));
        if ($search !== '') {
            $searchLower = mb_strtolower($search);
            $rows = array_values(array_filter($rows, function (array $row) use ($searchLower): bool {
                return str_contains(mb_strtolower((string) $row['product_name']), $searchLower)
                    || str_contains(mb_strtolower((string) $row['station_name']), $searchLower);
            }));
        }

        // Table Filter: station_name
        $stationFilter = $this->getTableFilterState('station_name')['value'] ?? null;
        if ($stationFilter !== null && $stationFilter !== '') {
            $rows = array_values(array_filter($rows, fn (array $row): bool => (string) $row['station_name'] === (string) $stationFilter));
        }

        // Sorting
        $sortColumn = $this->getTableSortColumn() ?: 'voided_at';
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
                $arrayKeyName => 'waste-'.(($page - 1) * $perPage + $index),
                'product_name' => $row['product_name'],
                'quantity' => $row['quantity'],
                'station_name' => $row['station_name'],
                'target_minutes' => $row['target_minutes'],
                'voided_at' => $row['voided_at'],
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
}
