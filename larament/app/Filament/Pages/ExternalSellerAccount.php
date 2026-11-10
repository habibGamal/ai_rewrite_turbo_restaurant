<?php

namespace App\Filament\Pages;

use App\Exceptions\OrderException;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\ExternalOrder;
use App\Models\ExternalOrderCollection;
use App\Models\ExternalOrderItem;
use App\Models\ExternalSeller;
use App\Models\Shift;
use App\Services\FeatureService;
use App\Services\Orders\ExternalSellerSettlementService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class ExternalSellerAccount extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة المطعم';

    protected static ?string $navigationLabel = 'حساب البائع';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.external-seller-account';

    public ExternalSeller $seller;

    public string $activeTab = 'orders';

    public static function canAccess(): bool
    {
        return app(FeatureService::class)->outsourcedOrdersEnabled()
            && (auth()->user()?->can('view_external_sellers') ?? false);
    }

    public static function getRoutePath(Panel $panel): string
    {
        return '/external-seller-accounts/{record}';
    }

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'external-seller-account';
    }

    public function mount(int|string|ExternalSeller $record): void
    {
        abort_unless(static::canAccess(), 403);

        if ($record instanceof ExternalSeller) {
            $this->seller = $record;
        } else {
            $this->seller = ExternalSeller::query()->findOrFail((int) $record);
        }
    }

    public function getTitle(): string|Htmlable
    {
        return $this->seller->name;
    }

    public function getHeading(): string|Htmlable
    {
        return $this->seller->name;
    }

    public function getSubheading(): ?string
    {
        return $this->seller->phone ? "الهاتف: {$this->seller->phone}" : null;
    }

    /**
     * @return array<string|int, string>
     */
    public function getBreadcrumbs(): array
    {
        return [
            ExternalSellerAccounts::getUrl() => ExternalSellerAccounts::getNavigationLabel(),
            $this->seller->name,
        ];
    }

    /**
     * @return Builder<ExternalOrder>
     */
    public function getFilteredOrdersQuery(): Builder
    {
        try {
            $query = $this->getFilteredTableQuery();
            if ($query !== null) {
                /** @var Builder<ExternalOrder> $query */
                return $query;
            }
        } catch (\Throwable) {
        }

        return ExternalOrder::query()->where('external_seller_id', $this->seller->id);
    }

    /**
     * @return array{orders_count: int, orders_total: float, collected: float, paid: float, payable: float}
     */
    public function getStats(): array
    {
        $filteredOrdersQuery = $this->getFilteredOrdersQuery();

        $ordersCount = (clone $filteredOrdersQuery)->count();
        $ordersTotal = (float) (clone $filteredOrdersQuery)->sum('total');

        $orderIds = (clone $filteredOrdersQuery)->pluck('external_orders.id');
        $collected = (float) ExternalOrderCollection::query()
            ->whereIn('external_order_id', $orderIds)
            ->sum('amount');

        // Extract active date range / shift filter
        /** @var array<string, mixed> $dateFilter */
        $dateFilter = $this->tableFilters['date'] ?? $this->tableFilters['date_range'] ?? $this->tableFilters['created_at'] ?? [];
        $from = $dateFilter['from'] ?? $dateFilter['created_from'] ?? null;
        $to = $dateFilter['to'] ?? $dateFilter['until'] ?? $dateFilter['created_until'] ?? null;
        $shiftId = $this->tableFilters['shift']['value'] ?? $this->tableFilters['shift_id']['value'] ?? null;

        $settlementsQuery = $this->seller->settlements();
        if ($from) {
            $settlementsQuery->whereDate('created_at', '>=', (string) $from);
        }
        if ($to) {
            $settlementsQuery->whereDate('created_at', '<=', (string) $to);
        }
        if ($shiftId) {
            $settlementsQuery->where('shift_id', $shiftId);
        }
        $paid = (float) $settlementsQuery->sum('amount');

        // Payable is always the all-time figure
        $payable = app(ExternalSellerSettlementService::class)->payable($this->seller);

        return [
            'orders_count' => $ordersCount,
            'orders_total' => round($ordersTotal, 2),
            'collected' => round($collected, 2),
            'paid' => round($paid, 2),
            'payable' => round($payable, 2),
        ];
    }

    /**
     * @return array{orders_count: int, orders_total: float, collected: float, paid: float, payable: float}
     */
    public function getStatsProperty(): array
    {
        return $this->getStats();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'stats' => $this->getStats(),
        ];
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('pay')
                ->label('دفع المستحقات')
                ->visible(fn (): bool => app(FeatureService::class)->outsourcedOrdersEnabled() && (auth()->user()?->can('update_external_sellers') ?? false))
                ->schema([
                    TextInput::make('amount')->label('المبلغ')->required()->numeric()->minValue(0.01),
                    Textarea::make('note')->label('ملاحظة'),
                ])
                ->action(function (array $data): void {
                    try {
                        app(ExternalSellerSettlementService::class)->pay(
                            $this->seller->id,
                            (float) $data['amount'],
                            $data['note'] ?? null
                        );
                        $this->dispatch('settlement-recorded');
                        Notification::make()->success()->title('تم دفع المستحقات')->send();
                    } catch (OrderException $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->send();
                    }
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                ExternalOrder::query()
                    ->where('external_seller_id', $this->seller->id)
                    ->with(['host.shift', 'collections', 'items'])
                    ->latest('id')
            )
            ->heading('طلبات البائع')
            ->columns([
                TextColumn::make('created_at')
                    ->label('التاريخ')
                    ->dateTime('Y/m/d H:i')
                    ->sortable(),

                TextColumn::make('host.order_number')
                    ->label('رقم الطلب')
                    ->formatStateUsing(fn ($state): string => $state ? "#{$state}" : '-')
                    ->url(fn (ExternalOrder $record): ?string => $record->host_order_id ? OrderResource::getUrl('view', ['record' => $record->host_order_id]) : null)
                    ->openUrlInNewTab()
                    ->searchable(),

                TextColumn::make('host.shift_id')
                    ->label('الوردية')
                    ->formatStateUsing(fn ($state): string => $state ? "وردية #{$state}" : '-'),

                TextColumn::make('items_summary')
                    ->label('الأصناف')
                    ->state(function (ExternalOrder $record): string {
                        $items = $record->items;
                        if ($items->isEmpty()) {
                            return '-';
                        }

                        return $items->map(function (ExternalOrderItem $item): string {
                            $qty = (float) $item->quantity;
                            $qtyStr = (floor($qty) == $qty) ? (string) (int) $qty : (string) $qty;

                            return "{$item->name} × {$qtyStr}";
                        })->join('، ');
                    }),

                TextColumn::make('total')
                    ->label('الإجمالي')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 2))
                    ->summarize(
                        Sum::make()
                            ->label('الإجمالي')
                            ->formatStateUsing(fn ($state): string => number_format((float) $state, 2))
                    ),

                TextColumn::make('collected')
                    ->label('المحصل')
                    ->state(fn (ExternalOrder $record): float => (float) $record->collections->sum('amount'))
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 2))
                    ->summarize(
                        Summarizer::make('total_collected')
                            ->label('إجمالي المحصل')
                            ->using(function (QueryBuilder $query): string {
                                $orderIds = (clone $query)->pluck('id');
                                $sum = ExternalOrderCollection::query()->whereIn('external_order_id', $orderIds)->sum('amount');

                                return number_format((float) $sum, 2);
                            })
                    ),

                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'fulfilled' => 'تم التنفيذ',
                        'cancelled' => 'ملغي',
                        'pending' => 'معلق',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'fulfilled' => 'success',
                        'cancelled' => 'danger',
                        'pending' => 'warning',
                        default => 'gray',
                    }),
            ])
            ->filters([
                Filter::make('date')
                    ->label('الفترة الزمنية')
                    ->schema([
                        DatePicker::make('from')->label('من تاريخ'),
                        DatePicker::make('to')->label('إلى تاريخ'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $from = $data['from'] ?? $data['created_from'] ?? null;
                        $to = $data['to'] ?? $data['until'] ?? $data['created_until'] ?? null;

                        return $query
                            ->when($from, fn (Builder $q, $d): Builder => $q->whereDate('created_at', '>=', (string) $d))
                            ->when($to, fn (Builder $q, $d): Builder => $q->whereDate('created_at', '<=', (string) $d));
                    }),

                Filter::make('date_range')
                    ->hidden()
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('to'),
                        DatePicker::make('created_from'),
                        DatePicker::make('created_until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $from = $data['from'] ?? $data['created_from'] ?? null;
                        $to = $data['to'] ?? $data['until'] ?? $data['created_until'] ?? null;

                        return $query
                            ->when($from, fn (Builder $q, $d): Builder => $q->whereDate('created_at', '>=', (string) $d))
                            ->when($to, fn (Builder $q, $d): Builder => $q->whereDate('created_at', '<=', (string) $d));
                    }),

                SelectFilter::make('shift')
                    ->label('الوردية')
                    ->options(fn (): array => Shift::query()->orderByDesc('id')->pluck('id', 'id')->mapWithKeys(fn ($id) => [$id => "وردية #{$id}"])->all())
                    ->query(function (Builder $query, array $data): Builder {
                        $shiftId = $data['value'] ?? null;

                        return $query->when($shiftId, fn (Builder $q, $id): Builder => $q->whereHas('host', fn (Builder $hq): Builder => $hq->where('shift_id', $id)));
                    }),

                SelectFilter::make('shift_id')
                    ->hidden()
                    ->options(fn (): array => Shift::query()->orderByDesc('id')->pluck('id', 'id')->mapWithKeys(fn ($id) => [$id => "وردية #{$id}"])->all())
                    ->query(function (Builder $query, array $data): Builder {
                        $shiftId = $data['value'] ?? null;

                        return $query->when($shiftId, fn (Builder $q, $id): Builder => $q->whereHas('host', fn (Builder $hq): Builder => $hq->where('shift_id', $id)));
                    }),

                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options([
                        'pending' => 'معلق',
                        'fulfilled' => 'تم التنفيذ',
                        'cancelled' => 'ملغي',
                    ]),

                Filter::make('uncollected')
                    ->label('غير محصل بالكامل')
                    ->query(function (Builder $query): Builder {
                        return $query->where(function (Builder $query): void {
                            $query->whereRaw('total > (select coalesce(sum(amount), 0) from external_order_collections where external_order_collections.external_order_id = external_orders.id)');
                        });
                    }),

                Filter::make('not_fully_collected')
                    ->hidden()
                    ->query(function (Builder $query): Builder {
                        return $query->where(function (Builder $query): void {
                            $query->whereRaw('total > (select coalesce(sum(amount), 0) from external_order_collections where external_order_collections.external_order_id = external_orders.id)');
                        });
                    }),
            ])
            ->deferFilters(false)
            ->emptyStateHeading('لا توجد طلبات خارجية لهذا البائع');
    }
}
