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
                    ->with(['orderReturn.user', 'orderReturn.order', 'orderReturn.refunds', 'orderItem.product'])
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

                TextColumn::make('orderItem.product.name')
                    ->label('الصنف')
                    ->searchable()
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
                    ->tooltip(fn (OrderReturnItem $record) => $record->restocked ? 'رجع للمخزن' : 'تالف'),

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
                    ->falseLabel('تالف'),

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
