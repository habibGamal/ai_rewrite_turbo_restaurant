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
                    ->weight('bold'),

                TextColumn::make('quantity')
                    ->label('الكمية')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 3))
                    ->badge()
                    ->color('info'),

                TextColumn::make('station_name')
                    ->label('المحطة')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'عام' ? 'gray' : 'primary'),

                TextColumn::make('target_minutes')
                    ->label('الوقت المستهدف')
                    ->formatStateUsing(fn ($state): string => number_format((int) $state).' دقيقة')
                    ->color('gray'),

                TextColumn::make('voided_at')
                    ->label('وقت الإلغاء')
                    ->color('danger'),
            ])
            ->paginated(false);
    }

    public function getTableRecords(): Collection
    {
        $rows = $this->reportService->getWasteCandidates(
            $this->pageFilters['startDate'] ?? now()->subDays(29)->startOfDay()->toDateString(),
            $this->pageFilters['endDate'] ?? now()->endOfDay()->toDateString(),
            $this->stationId(),
        );

        $arrayKeyName = ArrayRecord::getKeyName();

        return collect($rows)
            ->values()
            ->map(fn (array $row, int $index): array => [
                $arrayKeyName => 'waste-'.$index,
                'product_name' => $row['product_name'],
                'quantity' => $row['quantity'],
                'station_name' => $row['station_name'],
                'target_minutes' => $row['target_minutes'],
                'voided_at' => $row['voided_at'],
            ]);
    }

    private function stationId(): ?int
    {
        $stationId = $this->pageFilters['kitchen_station_id'] ?? null;

        return $stationId === null || $stationId === '' ? null : (int) $stationId;
    }
}
