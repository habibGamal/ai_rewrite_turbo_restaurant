<?php

namespace App\Filament\Widgets\Returns;

use App\Models\OrderReturn;
use App\Models\OrderReturnItem;
use App\Models\Refund;
use App\Models\User;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * One row per returned line: when, which receipt, who, why, how much, and
 * whether it went back to stock or was recorded as damaged.
 */
class ReturnLinesTable extends BaseWidget
{
    use InteractsWithPageFilters;
    use InteractsWithReturnsScope;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'أصناف المرتجعات';

    public function table(Table $table): Table
    {
        $returnIds = $this->returnsScope()->select('order_returns.id');

        return $table
            ->query(
                OrderReturnItem::query()
                    ->whereIn('order_return_id', $returnIds)
                    ->with(['orderReturn.user', 'orderReturn.order', 'orderReturn.refunds', ...OrderReturnItem::LINE_NAME_RELATIONS])
                    ->latest('id')
            )
            ->columns([
                TextColumn::make('orderReturn.created_at')
                    ->label('التاريخ')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('orderReturn.order_id')
                    ->label('الطلب')
                    ->formatStateUsing(fn ($state, OrderReturnItem $record) => '#'.$state.' (فاتورة '.(self::header($record)?->order->order_number ?? '-').')')
                    ->color('primary'),

                TextColumn::make('orderReturn.shift_id')
                    ->label('الشفت')
                    ->formatStateUsing(fn ($state) => "شفت #{$state}"),

                TextColumn::make('orderReturn.user.name')
                    ->label('الكاشير')
                    ->default('-'),

                TextColumn::make('line_name')
                    ->label('الصنف')
                    ->state(fn (OrderReturnItem $record): string => $record->lineName())
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $inner) => $inner
                        ->whereHas('orderItem.product', fn (Builder $product) => $product->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('orderFee', fn (Builder $fee) => $fee->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('orderEquipment', fn (Builder $line) => $line->where('name', 'like', "%{$search}%"))))
                    ->weight('medium'),

                TextColumn::make('quantity')
                    ->label('الكمية')
                    ->numeric(),

                TextColumn::make('refund_amount')
                    ->label('قيمة الرد')
                    ->money('EGP')
                    ->weight('bold'),

                IconColumn::make('restocked')
                    ->label('رجع للمخزن')
                    ->boolean()
                    ->state(fn (OrderReturnItem $record): ?bool => $record->order_item_id !== null ? (bool) $record->restocked : null)
                    ->tooltip(fn (OrderReturnItem $record) => $record->order_item_id === null ? 'رسوم أو معدات: لا تمس المخزن' : ($record->restocked ? 'رجع للمخزن' : 'تالف')),

                TextColumn::make('refund_method')
                    ->label('طريقة الرد')
                    ->state(fn (OrderReturnItem $record) => self::header($record)?->refunds
                        ->map(fn (Refund $refund) => $refund->method->getLabel())
                        ->unique()
                        ->implode('، ') ?: '-'),

                TextColumn::make('exchange')
                    ->label('استبدال')
                    ->badge()
                    ->state(fn (OrderReturnItem $record) => self::header($record)?->exchange_order_id ? 'استبدال #'.self::header($record)->exchange_order_id : null)
                    ->color('info'),

                TextColumn::make('orderReturn.reason')
                    ->label('السبب')
                    ->limit(40)
                    ->placeholder('-'),
            ])
            ->filters([
                TernaryFilter::make('restocked')
                    ->label('الحالة')
                    ->trueLabel('رجع للمخزن')
                    ->falseLabel('تالف')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('order_item_id')->where('restocked', true),
                        false: fn (Builder $query) => $query->whereNotNull('order_item_id')->where('restocked', false),
                        blank: fn (Builder $query) => $query,
                    ),

                SelectFilter::make('kind')
                    ->label('النوع')
                    ->options(['item' => 'أصناف', 'fee' => 'رسوم إضافية', 'equipment' => 'معدات'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'item' => $query->whereNotNull('order_item_id'),
                        'fee' => $query->whereNotNull('order_fee_id'),
                        'equipment' => $query->whereNotNull('order_equipment_id'),
                        default => $query,
                    }),

                SelectFilter::make('cashier')
                    ->label('الكاشير')
                    ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereHas('orderReturn', fn (Builder $inner) => $inner->where('user_id', $data['value']))
                        : $query),
            ]);
    }

    private static function header(OrderReturnItem $record): ?OrderReturn
    {
        $header = $record->orderReturn;

        return $header instanceof OrderReturn ? $header : null;
    }
}
