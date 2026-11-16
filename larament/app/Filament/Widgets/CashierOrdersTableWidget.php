<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Filament\Exports\CashierOrdersTableExporter;
use App\Models\Order;
use App\Models\User;
use App\Orders\OrderTypeCatalog;
use Filament\Actions\ExportAction;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class CashierOrdersTableWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'الطلبات المنشأة بواسطة الكاشير';

    public ?int $userId = null;

    public ?User $record = null;

    public array $shiftIds = [];

    public ?string $startDate = null;

    public ?string $endDate = null;

    public string $filterType = 'period';

    public bool $hasFilter = false;

    public function mount(
        ?int $userId = null,
        ?User $record = null,
        array $shiftIds = [],
        ?string $startDate = null,
        ?string $endDate = null,
        string $filterType = 'period',
        bool $hasFilter = false
    ): void {
        $this->userId = $userId ?? $record?->id;
        $this->shiftIds = $shiftIds;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->filterType = $filterType;
        $this->hasFilter = $hasFilter;
    }

    public function table(Table $table): Table
    {
        $query = Order::query()
            ->where('user_id', $this->userId ?? 0)
            ->with(['customer']);

        if ($this->hasFilter) {
            if (! empty($this->shiftIds)) {
                $query->whereIn('shift_id', $this->shiftIds);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        return $table
            ->query($query)
            ->recordTitleAttribute('order_number')
            ->columns([
                TextColumn::make('order_number')
                    ->label('رقم الطلب')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('type')
                    ->label('نوع الطلب')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => app(OrderTypeCatalog::class)->display((string) $state)->label)
                    ->color(fn (mixed $state): string => app(OrderTypeCatalog::class)->display((string) $state)->color)
                    ->icon(fn (mixed $state): string => app(OrderTypeCatalog::class)->display((string) $state)->icon)
                    ->sortable(),

                TextColumn::make('status')
                    ->label('حالة الطلب')
                    ->badge()
                    ->sortable(),

                TextColumn::make('payment_status')
                    ->label('حالة الدفع')
                    ->badge()
                    ->sortable(),

                TextColumn::make('sub_total')
                    ->label('المجموع الفرعي')
                    ->money('EGP')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('discount')
                    ->label('الخصم')
                    ->money('EGP')
                    ->sortable()
                    ->color(fn ($state) => (float) $state > 0 ? 'danger' : 'gray')
                    ->toggleable(),

                TextColumn::make('total')
                    ->label('الإجمالي')
                    ->money('EGP')
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('dine_table_number')
                    ->label('الطاولة')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('customer.name')
                    ->label('العميل')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('نوع الطلب')
                    ->options(fn (): array => app(OrderTypeCatalog::class)->options()),

                SelectFilter::make('status')
                    ->label('حالة الطلب')
                    ->options(OrderStatus::class),

                SelectFilter::make('payment_status')
                    ->label('حالة الدفع')
                    ->options(PaymentStatus::class),

                Filter::make('has_discount')
                    ->label('الطلبات بخصم فقط')
                    ->query(fn (Builder $query): Builder => $query->where('discount', '>', 0)),

                Filter::make('no_discount')
                    ->label('الطلبات بدون خصم فقط')
                    ->query(fn (Builder $query): Builder => $query->where(fn (Builder $q) => $q->where('discount', '=', 0)->orWhereNull('discount'))),

                Filter::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->schema([
                        DatePicker::make('created_from')->label('من تاريخ'),
                        DatePicker::make('created_until')->label('إلى تاريخ'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['created_from'], fn (Builder $q, $d) => $q->whereDate('created_at', '>=', $d))
                            ->when($data['created_until'], fn (Builder $q, $d) => $q->whereDate('created_at', '<=', $d));
                    }),
            ])
            ->headerActions([
                ExportAction::make()
                    ->label('تصدير الطلبات')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->exporter(CashierOrdersTableExporter::class)
                    ->formats([ExportFormat::Xlsx, ExportFormat::Csv])
                    ->fileName(fn () => 'cashier-orders-'.now()->format('Y-m-d-H-i-s').'.xlsx'),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn (Order $record): string => url("/admin/orders/{$record->id}")),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc')
            ->striped();
    }
}
