<?php

namespace App\Filament\Widgets;

use App\Filament\Exports\StockReportExporter;
use App\Services\Reports\StockReportService;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class StockReportTable extends BaseWidget
{
    protected static bool $isLazy = false;

    protected static ?string $pollingInterval = null;

    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'تقرير المخزون';

    public function table(Table $table): Table
    {
        $resolved = StockReportService::resolveDates(
            $this->pageFilters['startDate'] ?? null,
            $this->pageFilters['endDate'] ?? null,
        );

        $startDate = $resolved['start'];
        $endDate = $resolved['end'];
        $useLive = $resolved['useLive'];

        $rawWarehouseId = $this->pageFilters['warehouse_id'] ?? $this->pageFilters['warehouseId'] ?? null;
        $warehouseId = is_numeric($rawWarehouseId) && (int) $rawWarehouseId > 0 ? (int) $rawWarehouseId : null;

        return $table
            ->query(StockReportService::query($startDate, $endDate, $useLive, $warehouseId))
            ->columns([
                TextColumn::make('name')
                    ->label('المنتج')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where('products.name', 'like', "%{$search}%");
                    })
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderBy('products.name', $direction);
                    })
                    ->weight('medium')
                    ->color('primary'),

                TextColumn::make('category.name')
                    ->label('التصنيف')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->toggleable()
                    ->color('gray'),

                TextColumn::make('start_quantity')
                    ->label('الكمية البدائية')
                    ->numeric()
                    ->sortable()
                    ->default(0)
                    ->color(function ($state) {
                        return $state > 0 ? 'success' : 'gray';
                    })
                    ->alignCenter(),

                TextColumn::make('incoming')
                    ->label('كمية الوارد')
                    ->numeric()
                    ->sortable()
                    ->default(0)
                    ->toggleable()
                    ->color(function ($state) {
                        return $state > 0 ? 'info' : 'gray';
                    })
                    ->alignCenter(),

                TextColumn::make('transfer_in')
                    ->label('تحويل وارد')
                    ->numeric()
                    ->sortable()
                    ->default(0)
                    ->toggleable()
                    ->color(function ($state) {
                        return $state > 0 ? 'info' : 'gray';
                    })
                    ->alignCenter(),

                TextColumn::make('manufacturing_in')
                    ->label('تصنيع وارد')
                    ->numeric()
                    ->sortable()
                    ->default(0)
                    ->toggleable()
                    ->color(function ($state) {
                        return $state > 0 ? 'info' : 'gray';
                    })
                    ->alignCenter(),

                TextColumn::make('total_quantity')
                    ->label('الكمية الكلية')
                    ->numeric()
                    ->toggleable()
                    ->getStateUsing(fn ($record) => StockReportService::calculate($record)['total'])
                    ->color('success')
                    ->weight('bold')
                    ->alignCenter(),

                TextColumn::make('sales')
                    ->label('كمية المبيعات')
                    ->numeric()
                    ->sortable()
                    ->default(0)
                    ->toggleable()
                    ->color(function ($state) {
                        return $state > 0 ? 'danger' : 'gray';
                    })
                    ->alignCenter(),

                TextColumn::make('return_waste')
                    ->label('كمية الفاقد والمرتجع')
                    ->numeric()
                    ->sortable()
                    ->default(0)
                    ->toggleable()
                    ->color(function ($state) {
                        return $state > 0 ? 'danger' : 'gray';
                    })
                    ->alignCenter(),

                TextColumn::make('transfer_out')
                    ->label('تحويل صادر')
                    ->numeric()
                    ->sortable()
                    ->default(0)
                    ->toggleable()
                    ->color(function ($state) {
                        return $state > 0 ? 'danger' : 'gray';
                    })
                    ->alignCenter(),

                TextColumn::make('manufacturing_out')
                    ->label('تصنيع صادر')
                    ->numeric()
                    ->sortable()
                    ->default(0)
                    ->toggleable()
                    ->color(function ($state) {
                        return $state > 0 ? 'danger' : 'gray';
                    })
                    ->alignCenter(),

                TextColumn::make('issue')
                    ->label('صرف')
                    ->numeric()
                    ->sortable()
                    ->default(0)
                    ->toggleable()
                    ->color(function ($state) {
                        return $state > 0 ? 'danger' : 'gray';
                    })
                    ->alignCenter(),

                TextColumn::make('total_consumed')
                    ->label('الكمية الكلية المنصرفة')
                    ->numeric()
                    ->toggleable()
                    ->getStateUsing(fn ($record) => StockReportService::calculate($record)['consumed'])
                    ->color('danger')
                    ->weight('medium')
                    ->alignCenter(),

                TextColumn::make('ideal_remaining')
                    ->label('الكمية المتبقية المثالية')
                    ->numeric()
                    ->toggleable()
                    ->getStateUsing(fn ($record) => StockReportService::calculate($record)['ideal'])
                    ->color('primary')
                    ->weight('medium')
                    ->alignCenter(),

                TextColumn::make('actual_remaining_quantity')
                    ->label('الكمية المتبقية الفعلية')
                    ->numeric()
                    ->sortable()
                    ->toggleable()
                    ->default(0)
                    ->color(function ($state, $record) {
                        $calc = StockReportService::calculate($record);
                        if ($calc['actual'] == $calc['ideal']) {
                            return 'success';
                        }

                        return $calc['actual'] < $calc['ideal'] ? 'danger' : 'warning';
                    })
                    ->weight('bold')
                    ->alignCenter(),

                TextColumn::make('cost')
                    ->label('التكلفة الحالية')
                    ->numeric(decimalPlaces: 2)
                    ->sortable()
                    ->color('info')
                    ->toggleable()
                    ->suffix(' جنيه')
                    ->alignCenter(),

                TextColumn::make('deviation')
                    ->label('الانحراف')
                    ->numeric()
                    ->toggleable()
                    ->tooltip('يشمل تسويات الجرد')
                    ->getStateUsing(fn ($record) => StockReportService::calculate($record)['deviation'])
                    ->color(function ($record) {
                        $calc = StockReportService::calculate($record);
                        if ($calc['deviation'] == 0.0) {
                            return 'success';
                        }

                        return $calc['deviation'] < 0 ? 'danger' : 'warning';
                    })
                    ->weight('medium')
                    ->alignCenter(),

                TextColumn::make('deviation_value')
                    ->label('قيمة الانحراف')
                    ->numeric(decimalPlaces: 2)
                    ->toggleable()
                    ->getStateUsing(fn ($record) => StockReportService::calculate($record)['deviation_value'])
                    ->color(function ($record) {
                        $calc = StockReportService::calculate($record);
                        if ($calc['deviation_value'] == 0.0) {
                            return 'success';
                        }

                        return $calc['deviation_value'] < 0 ? 'danger' : 'warning';
                    })
                    ->suffix(' جنيه')
                    ->alignCenter(),

                TextColumn::make('deviation_percentage')
                    ->label('نسبة الانحراف')
                    ->numeric(decimalPlaces: 1)
                    ->placeholder('—')
                    ->toggleable()
                    ->getStateUsing(fn ($record) => StockReportService::calculate($record)['deviation_percentage'])
                    ->color(function ($record) {
                        $calc = StockReportService::calculate($record);
                        $pct = $calc['deviation_percentage'];
                        if ($pct === null || abs($pct) <= 5) {
                            return 'success';
                        }
                        if (abs($pct) <= 15) {
                            return 'warning';
                        }

                        return 'danger';
                    })
                    ->suffix(fn ($state) => $state !== null ? '%' : null)
                    ->weight('medium')
                    ->alignCenter(),
            ])
            ->filters([
                SelectFilter::make('category_id')
                    ->label('التصنيف')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload(),

                TernaryFilter::make('has_stock')
                    ->label('المنتجات المتوفرة في المخزن')
                    ->queries(
                        true: function (Builder $query) {
                            return $query->whereHas('inventoryItem', function ($q) {
                                $q->where('quantity', '>', 0);
                            });
                        },
                        false: function (Builder $query) {
                            return $query->where(function (Builder $q) {
                                $q->whereDoesntHave('inventoryItem')
                                    ->orWhereHas('inventoryItem', function ($sub) {
                                        $sub->where('quantity', '<=', 0);
                                    });
                            });
                        },
                        blank: function (Builder $query) {
                            return $query;
                        },
                    ),

                TernaryFilter::make('has_movements')
                    ->label('المنتجات التي لها حركة في الفترة')
                    ->queries(
                        true: function (Builder $query) use ($startDate, $endDate) {
                            return $query->whereHas('dailyMovements', function ($q) use ($startDate, $endDate) {
                                $q->whereRaw('DATE(date) BETWEEN ? AND ?', [$startDate, $endDate])
                                    ->whereRaw('(incoming_quantity + sales_quantity + return_sales_quantity + return_waste_quantity) > 0');
                            });
                        },
                        false: function (Builder $query) use ($startDate, $endDate) {
                            return $query->whereDoesntHave('dailyMovements', function ($q) use ($startDate, $endDate) {
                                $q->whereRaw('DATE(date) BETWEEN ? AND ?', [$startDate, $endDate])
                                    ->whereRaw('(incoming_quantity + sales_quantity + return_sales_quantity + return_waste_quantity) > 0');
                            });
                        },
                        blank: function (Builder $query) {
                            return $query;
                        },
                    ),

                SelectFilter::make('cost_range')
                    ->label('نطاق التكلفة')
                    ->options([
                        'low' => 'منخفضة (أقل من 10 جنيه)',
                        'medium' => 'متوسطة (10 - 50 جنيه)',
                        'high' => 'عالية (أكثر من 50 جنيه)',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['value'] === 'low',
                            function (Builder $query): Builder {
                                return $query->where('products.cost', '<', 10);
                            },
                        )->when(
                            $data['value'] === 'medium',
                            function (Builder $query): Builder {
                                return $query->whereBetween('products.cost', [10, 50]);
                            },
                        )->when(
                            $data['value'] === 'high',
                            function (Builder $query): Builder {
                                return $query->where('products.cost', '>', 50);
                            },
                        );
                    }),

                Filter::make('zero_cost')
                    ->label('المنتجات بدون تكلفة محددة')
                    ->query(function (Builder $query): Builder {
                        return $query->where(function (Builder $q) {
                            $q->whereNull('products.cost')->orWhere('products.cost', 0);
                        });
                    }),
            ])
            ->defaultSort('name')
            ->striped()
            ->paginated([10, 25, 50, 100])
            ->poll('30s')
            ->emptyStateHeading('لا توجد منتجات')
            ->emptyStateDescription('لم يتم العثور على أي منتجات لعرضها في التقرير.')
            ->emptyStateIcon('heroicon-o-cube')
            ->recordActions([
                Action::make('view_product')
                    ->label('عرض المنتج')
                    ->icon('heroicon-o-eye')
                    ->visible(fn ($record) => filled($record->inventory_item_id))
                    ->url(function ($record) {
                        return route('filament.admin.resources.inventory-items.view', [
                            'record' => $record->inventory_item_id,
                            'tableFilters' => [
                                'created_at' => [
                                    'created_from' => $record->start_created_at,
                                    'created_until' => $record->last_closed_at,
                                ],
                            ],
                        ]);
                    })
                    ->openUrlInNewTab()
                    ->color('primary'),
            ])
            ->headerActions([
                ExportAction::make()
                    ->label('تصدير التقرير')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->exporter(StockReportExporter::class)
                    ->fileName(fn () => 'stock-report-'.now()->format('Y-m-d-H-i-s').'.xlsx'),
            ])
            ->recordAction(null)
            ->recordUrl(null)
            ->toolbarActions([]);
    }
}
