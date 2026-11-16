<?php

namespace App\Filament\Widgets;

use App\Filament\Exports\CashierItemChangesTableExporter;
use App\Models\OrderItemChange;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class CashierItemChangesTableWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'تعديلات وحركات الأصناف للكاشير';

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
        $query = OrderItemChange::query()
            ->where('user_id', $this->userId ?? 0)
            ->with(['order']);

        if ($this->hasFilter) {
            if (! empty($this->shiftIds)) {
                $query->whereHas('order', function (Builder $q) {
                    $q->whereIn('shift_id', $this->shiftIds);
                });
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        return $table
            ->query($query)
            ->recordTitleAttribute('product_name')
            ->columns([
                TextColumn::make('order.order_number')
                    ->label('رقم الطلب')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->formatStateUsing(fn ($record) => '#'.($record->order->order_number ?? $record->order_id)),

                TextColumn::make('product_name')
                    ->label('اسم المنتج')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('change_type')
                    ->label('نوع الحركة')
                    ->badge()
                    ->formatStateUsing(fn ($state, $record) => match ($state) {
                        'added' => 'إضافة',
                        'removed' => 'حذف',
                        default => (float) $record->delta > 0 ? 'زيادة' : 'نقص',
                    })
                    ->color(fn ($state, $record) => match ($state) {
                        'added' => 'success',
                        'removed' => 'danger',
                        default => (float) $record->delta > 0 ? 'info' : 'warning',
                    }),

                TextColumn::make('old_quantity')
                    ->label('الكمية السابقة')
                    ->numeric(decimalPlaces: 2)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('new_quantity')
                    ->label('الكمية الجديدة')
                    ->numeric(decimalPlaces: 2)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('delta')
                    ->label('الكمية')
                    ->formatStateUsing(fn ($state) => ((float) $state > 0 ? '+' : '').(float) $state)
                    ->color(fn ($state) => (float) $state >= 0 ? 'success' : 'danger')
                    ->weight('bold')
                    ->sortable(),

                TextColumn::make('unit_price')
                    ->label('سعر الوحدة')
                    ->money('EGP')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('total_price')
                    ->label('إجمالي القيمة')
                    ->formatStateUsing(fn ($state, $record) => ((float) $record->delta > 0 ? '+' : '-').number_format((float) $state, 2).' ج.م')
                    ->color(fn ($record) => (float) $record->delta >= 0 ? 'success' : 'danger')
                    ->weight('bold')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('التاريخ والوقت')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('change_type')
                    ->label('نوع الحركة')
                    ->options([
                        'added' => 'إضافة',
                        'removed' => 'حذف',
                        'quantity_changed' => 'تعديل كمية',
                    ]),

                Filter::make('created_at')
                    ->label('تاريخ التعديل')
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
                    ->label('تصدير تعديلات الأصناف')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->exporter(CashierItemChangesTableExporter::class)
                    ->formats([ExportFormat::Xlsx, ExportFormat::Csv])
                    ->fileName(fn () => 'cashier-item-changes-'.now()->format('Y-m-d-H-i-s').'.xlsx'),
            ])
            ->recordActions([
                Action::make('view_order')
                    ->label('عرض الطلب')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->url(fn (OrderItemChange $record): string => url("/admin/orders/{$record->order_id}")),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc')
            ->striped();
    }
}
