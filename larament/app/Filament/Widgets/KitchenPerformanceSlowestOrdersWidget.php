<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\KitchenTicket;
use App\Orders\OrderTypeCatalog;
use App\Services\KitchenPerformanceReportService;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class KitchenPerformanceSlowestOrdersWidget extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static ?string $heading = 'أبطأ الطلبات في التحضير';

    protected static ?string $description = 'الطلبات التي استغرقت أطول وقت تشغيل في المطبخ (مجموع الجولات المغلقة)';

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
                TextColumn::make('order_number')
                    ->label('رقم الطلب')
                    ->weight('bold')
                    ->searchable()
                    ->sortable()
                    ->url(fn (array $record): ?string => ! empty($record['order_id'])
                        ? OrderResource::getUrl('view', ['record' => $record['order_id']])
                        : null)
                    ->openUrlInNewTab()
                    ->color('primary'),

                TextColumn::make('order_type_label')
                    ->label('نوع الطلب')
                    ->searchable()
                    ->sortable()
                    ->badge(),

                TextColumn::make('customer_name')
                    ->label('العميل')
                    ->searchable()
                    ->sortable()
                    ->placeholder('غير محدد'),

                TextColumn::make('items_count')
                    ->label('عدد الأصناف')
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => number_format((int) $state).' صنف')
                    ->badge()
                    ->color('info'),

                TextColumn::make('rounds_count')
                    ->label('الجولات')
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => number_format((int) $state).' جولة')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('kitchen_seconds')
                    ->label('إجمالي وقت المطبخ')
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => KitchenPerformanceReportService::formatDuration((float) $state))
                    ->color('danger')
                    ->weight('bold'),

                TextColumn::make('late_rounds')
                    ->label('الجولات المتأخرة')
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => (int) $state > 0 ? "{$state} متأخرة" : 'في الموعد')
                    ->badge()
                    ->color(fn ($state): string => (int) $state > 0 ? 'danger' : 'success')
                    ->tooltip('عدد الجولات التي تجاوزت الوقت المستهدف لأطول صنف فيها'),

                TextColumn::make('first_sent_at')
                    ->label('وقت الإرسال')
                    ->sortable()
                    ->color('gray'),
            ])
            ->filters([
                Filter::make('late_only')
                    ->label('الطلبات المتأخرة فقط'),

                SelectFilter::make('order_type')
                    ->label('نوع الطلب')
                    ->options(fn (): array => app(OrderTypeCatalog::class)->options()),
            ])
            ->defaultSort('kitchen_seconds', 'desc')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50, 100]);
    }

    public function getTableRecords(): Collection|Paginator|CursorPaginator
    {
        $rows = $this->reportService->getSlowestOrders(
            $this->pageFilters['startDate'] ?? now()->subDays(29)->startOfDay()->toDateString(),
            $this->pageFilters['endDate'] ?? now()->endOfDay()->toDateString(),
            $this->stationId(),
        );

        // Global search
        $search = trim((string) ($this->getTableSearch() ?? ''));
        if ($search !== '') {
            $searchLower = mb_strtolower($search);
            $rows = array_values(array_filter($rows, function (array $row) use ($searchLower): bool {
                return str_contains(mb_strtolower((string) $row['order_number']), $searchLower)
                    || str_contains(mb_strtolower((string) ($row['customer_name'] ?? '')), $searchLower)
                    || str_contains(mb_strtolower((string) $row['order_type_label']), $searchLower);
            }));
        }

        // Filters
        $lateOnly = (bool) ($this->getTableFilterState('late_only')['isActive'] ?? false);
        if ($lateOnly) {
            $rows = array_values(array_filter($rows, fn (array $row): bool => (bool) $row['is_late']));
        }

        $orderType = $this->getTableFilterState('order_type')['value'] ?? null;
        if ($orderType !== null && $orderType !== '') {
            $rows = array_values(array_filter($rows, fn (array $row): bool => (string) $row['order_type'] === (string) $orderType));
        }

        // Sorting
        $sortColumn = $this->getTableSortColumn() ?: 'kitchen_seconds';
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
                $arrayKeyName => 'slowest-order-'.(($page - 1) * $perPage + $index),
                'ticket_id' => $row['ticket_id'],
                'order_id' => $row['order_id'],
                'order_number' => $row['order_number'],
                'order_type' => $row['order_type'],
                'order_type_label' => $row['order_type_label'],
                'customer_name' => $row['customer_name'],
                'items_count' => $row['items_count'],
                'rounds_count' => $row['rounds_count'],
                'kitchen_seconds' => $row['kitchen_seconds'],
                'late_rounds' => $row['late_rounds'],
                'is_late' => $row['is_late'],
                'first_sent_at' => $row['first_sent_at'],
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
