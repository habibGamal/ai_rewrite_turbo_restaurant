<?php

namespace App\Filament\Resources\OrderReturns;

use App\Filament\Resources\OrderReturns\Pages\ListOrderReturns;
use App\Filament\Resources\OrderReturns\Pages\ViewOrderReturn;
use App\Filament\Resources\OrderReturns\RelationManagers\ReturnedItemsRelationManager;
use App\Filament\Traits\BelongsToModule;
use App\Models\OrderReturn;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrderReturnResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = OrderReturn::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static ?string $navigationLabel = 'مرتجعات الطلبات';

    protected static ?string $modelLabel = 'مرتجع طلب';

    protected static ?string $pluralModelLabel = 'مرتجعات الطلبات';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة المبيعات';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        // Returns are made only from the POS, against the current shift and drawer.
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('رقم المرتجع')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('order.order_number')
                    ->label('رقم الطلب')
                    ->searchable()
                    ->sortable()
                    ->url(fn ($record) => route('filament.admin.resources.orders.view', ['record' => $record->order_id]))
                    ->color('primary'),

                TextColumn::make('order.customer.name')
                    ->label('العميل')
                    ->searchable()
                    ->placeholder('غير محدد'),

                TextColumn::make('total_refund')
                    ->label('إجمالي الاسترجاع')
                    ->money('EGP')
                    ->sortable(),

                IconColumn::make('reverse_stock')
                    ->label('إعادة للمخزون')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('user.name')
                    ->label('المستخدم')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('shift.id')
                    ->label('رقم الوردية')
                    ->sortable()
                    ->prefix('#'),

                TextColumn::make('created_at')
                    ->label('تاريخ الإرجاع')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                Filter::make('created_at')
                    ->label('تاريخ الإرجاع')
                    ->schema([
                        DatePicker::make('created_from')
                            ->label('من تاريخ'),
                        DatePicker::make('created_until')
                            ->label('إلى تاريخ'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),

                SelectFilter::make('order_id')
                    ->label('الطلب')
                    ->relationship('order', 'order_number')
                    ->searchable()
                    ->preload(),

                TernaryFilter::make('reverse_stock')
                    ->label('إعادة للمخزون')
                    ->placeholder('الكل')
                    ->trueLabel('نعم')
                    ->falseLabel('لا'),
            ])
            ->recordActions([
                ViewAction::make()->label('عرض'),
            ])
            ->toolbarActions([
                // No bulk actions for view-only resource
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('معلومات المرتجع')
                    ->schema([
                        TextEntry::make('id')
                            ->label('رقم المرتجع'),

                        TextEntry::make('order.order_number')
                            ->label('رقم الطلب')
                            ->url(fn ($record) => route('filament.admin.resources.orders.view', ['record' => $record->order_id]))
                            ->color('primary'),

                        TextEntry::make('order.customer.name')
                            ->label('العميل')
                            ->placeholder('غير محدد'),

                        TextEntry::make('total_refund')
                            ->label('إجمالي الاسترجاع')
                            ->money('EGP'),

                        IconEntry::make('reverse_stock')
                            ->label('إعادة للمخزون')
                            ->boolean(),

                        TextEntry::make('user.name')
                            ->label('المستخدم'),

                        TextEntry::make('shift.id')
                            ->label('رقم الوردية')
                            ->prefix('#'),

                        TextEntry::make('created_at')
                            ->label('تاريخ الإرجاع')
                            ->dateTime('Y-m-d H:i:s'),
                    ])
                    ->columns(2),

                Section::make('سبب الإرجاع')
                    ->schema([
                        TextEntry::make('reason')
                            ->label('')
                            ->placeholder('لم يتم تحديد سبب'),
                    ])
                    ->collapsed()
                    ->collapsible(),

                Section::make('تفاصيل الاسترجاع')
                    ->schema([
                        RepeatableEntry::make('refunds')
                            ->label('توزيع الاسترجاع')
                            ->schema([
                                TextEntry::make('method')
                                    ->label('طريقة الدفع')
                                    ->badge(),

                                TextEntry::make('amount')
                                    ->label('المبلغ')
                                    ->money('EGP'),
                            ])
                            ->columns(2),
                    ]),

                Section::make('إحصائيات')
                    ->schema([
                        TextEntry::make('items_count')
                            ->label('عدد الأصناف المرتجعة')
                            ->getStateUsing(fn ($record) => $record->items->count()),

                        TextEntry::make('total_quantity')
                            ->label('إجمالي الكمية المرتجعة')
                            ->getStateUsing(fn ($record) => $record->items->sum('quantity')),

                        TextEntry::make('refunds_count')
                            ->label('عدد طرق الاسترجاع')
                            ->getStateUsing(fn ($record) => $record->refunds->count()),
                    ])
                    ->columns(3),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ReturnedItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrderReturns::route('/'),
            'view' => ViewOrderReturn::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['order.customer', 'user', 'shift', 'items', 'refunds']);
    }
}
