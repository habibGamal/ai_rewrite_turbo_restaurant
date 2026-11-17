<?php

namespace App\Filament\Widgets\Returns;

use App\Filament\Resources\OrderReturns\OrderReturnResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Widgets\Concerns\ScopesReportToTerminal;
use App\Models\OrderReturn;
use App\Models\OrderReturnItem;
use App\Models\Refund;
use App\Models\User;
use App\Services\FeatureService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\HtmlString;

/**
 * The returns of the shifts a shift report covers, one row per return:
 * when, which receipt, who, what came back, how much and how it was paid.
 * The «المرتجعات» card of the report scrolls here.
 */
class ShiftReturnsTable extends BaseWidget
{
    use InteractsWithPageFilters;
    use InteractsWithReturnsScope;
    use ScopesReportToTerminal;

    /** Element id the returns card scrolls to. */
    public const ANCHOR = 'shift_returns_table';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'المرتجعات';

    public static function canView(): bool
    {
        return app(FeatureService::class)->returnsEnabled() && parent::canView();
    }

    public function table(Table $table): Table
    {
        $this->reportTerminal();

        return $table
            ->query(
                $this->reportTerminal()->money(OrderReturn::query(), 'order_returns.terminal_session_id')
                    ->whereIn('shift_id', $this->shiftScope())
                    ->with(['user', 'order', 'refunds', 'items', ...array_map(fn (string $relation) => 'items.'.$relation, OrderReturnItem::LINE_NAME_RELATIONS)])
                    ->latest('id')
            )
            ->extraAttributes(['id' => self::ANCHOR, 'class' => 'scroll-mt-24'])
            ->emptyStateHeading('لا توجد مرتجعات')
            ->paginated([10, 25, 50])
            ->columns([
                TextColumn::make('created_at')
                    ->label('الوقت')
                    ->dateTime('d/m H:i')
                    ->sortable(),

                TextColumn::make('order_id')
                    ->label('الطلب')
                    ->formatStateUsing(fn ($state, OrderReturn $record) => '#'.$state.' (فاتورة '.($record->order->order_number ?? '-').')')
                    ->url(fn (OrderReturn $record) => OrderResource::getUrl('view', ['record' => $record->order_id]))
                    ->color('primary'),

                TextColumn::make('lines')
                    ->label('الأصناف')
                    ->state(fn (OrderReturn $record) => new HtmlString($record->items
                        ->map(function ($line): string {
                            if (! $line instanceof OrderReturnItem) {
                                return '';
                            }
                            $text = e($line->lineName());
                            if ($line->order_fee_id === null) {
                                $text .= ' × '.rtrim(rtrim(number_format((float) $line->quantity, 3, '.', ''), '0'), '.');
                            }
                            if ($line->order_item_id !== null && ! $line->restocked) {
                                $text .= ' <span class="text-danger-600">(تالف)</span>';
                            }

                            return $text.' — '.number_format((float) $line->refund_amount, 2);
                        })
                        ->implode('<br>')))
                    ->html()
                    ->wrap(),

                TextColumn::make('quantity')
                    ->label('الكمية')
                    ->state(fn (OrderReturn $record) => rtrim(rtrim(number_format((float) $record->items->whereNotNull('order_item_id')->sum('quantity'), 3, '.', ''), '0'), '.') ?: '0'),

                TextColumn::make('total_refund')
                    ->label('قيمة الرد')
                    ->money('EGP')
                    ->weight('bold')
                    ->sortable(),

                TextColumn::make('refund_method')
                    ->label('طريقة الرد')
                    ->state(fn (OrderReturn $record) => $record->refunds
                        ->map(fn ($refund) => ! $refund instanceof Refund ? '' : $refund->method->getLabel().' '.number_format((float) $refund->amount, 2))
                        ->implode('، ') ?: '-'),

                TextColumn::make('user.name')
                    ->label('الكاشير')
                    ->default('-'),

                TextColumn::make('exchange_order_id')
                    ->label('استبدال')
                    ->badge()
                    ->formatStateUsing(fn ($state) => 'طلب #'.$state)
                    ->url(fn (OrderReturn $record) => $record->exchange_order_id ? OrderResource::getUrl('view', ['record' => $record->exchange_order_id]) : null)
                    ->placeholder('-')
                    ->color('info'),

                TextColumn::make('reason')
                    ->label('السبب')
                    ->limit(40)
                    ->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('user_id')
                    ->label('الكاشير')
                    ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->recordUrl(fn (OrderReturn $record) => OrderReturnResource::canView($record) ? OrderReturnResource::getUrl('view', ['record' => $record]) : null);
    }
}
