<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderReturnResource\Pages;
use App\Filament\Resources\OrderReturnResource\RelationManagers;
use App\Filament\Traits\AdminAccess;
use App\Models\OrderReturn;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrderReturnResource extends Resource
{
    use AdminAccess;

    protected static ?string $model = OrderReturn::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static ?string $navigationLabel = 'مرتجعات الطلبات';

    protected static ?string $modelLabel = 'مرتجع طلب';

    protected static ?string $pluralModelLabel = 'مرتجعات الطلبات';

    protected static ?string $navigationGroup = 'إدارة المطعم';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // This is a view-only resource
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('رقم المرتجع')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('order.order_number')
                    ->label('رقم الطلب')
                    ->searchable()
                    ->sortable()
                    ->url(fn($record) => route('filament.admin.resources.orders.view', ['record' => $record->order_id]))
                    ->color('primary'),

                Tables\Columns\TextColumn::make('order.customer.name')
                    ->label('العميل')
                    ->searchable()
                    ->placeholder('غير محدد'),

                Tables\Columns\TextColumn::make('total_refund')
                    ->label('إجمالي الاسترجاع')
                    ->money('EGP')
                    ->sortable(),

                Tables\Columns\IconColumn::make('reverse_stock')
                    ->label('إعادة للمخزون')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('المستخدم')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('shift.id')
                    ->label('رقم الوردية')
                    ->sortable()
                    ->prefix('#'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الإرجاع')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('created_at')
                    ->label('تاريخ الإرجاع')
                    ->form([
                        Forms\Components\DatePicker::make('created_from')
                            ->label('من تاريخ'),
                        Forms\Components\DatePicker::make('created_until')
                            ->label('إلى تاريخ'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'],
                                fn(Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn(Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),

                Tables\Filters\SelectFilter::make('order_id')
                    ->label('الطلب')
                    ->relationship('order', 'order_number')
                    ->searchable()
                    ->preload(),

                Tables\Filters\TernaryFilter::make('reverse_stock')
                    ->label('إعادة للمخزون')
                    ->placeholder('الكل')
                    ->trueLabel('نعم')
                    ->falseLabel('لا'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('عرض'),
            ])
            ->bulkActions([
                // No bulk actions for view-only resource
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('معلومات المرتجع')
                    ->schema([
                        Infolists\Components\TextEntry::make('id')
                            ->label('رقم المرتجع'),

                        Infolists\Components\TextEntry::make('order.order_number')
                            ->label('رقم الطلب')
                            ->url(fn($record) => route('filament.admin.resources.orders.view', ['record' => $record->order_id]))
                            ->color('primary'),

                        Infolists\Components\TextEntry::make('order.customer.name')
                            ->label('العميل')
                            ->placeholder('غير محدد'),

                        Infolists\Components\TextEntry::make('total_refund')
                            ->label('إجمالي الاسترجاع')
                            ->money('EGP'),

                        Infolists\Components\IconEntry::make('reverse_stock')
                            ->label('إعادة للمخزون')
                            ->boolean(),

                        Infolists\Components\TextEntry::make('user.name')
                            ->label('المستخدم'),

                        Infolists\Components\TextEntry::make('shift.id')
                            ->label('رقم الوردية')
                            ->prefix('#'),

                        Infolists\Components\TextEntry::make('created_at')
                            ->label('تاريخ الإرجاع')
                            ->dateTime('Y-m-d H:i:s'),
                    ])
                    ->columns(2),

                Infolists\Components\Section::make('سبب الإرجاع')
                    ->schema([
                        Infolists\Components\TextEntry::make('reason')
                            ->label('')
                            ->placeholder('لم يتم تحديد سبب'),
                    ])
                    ->collapsed()
                    ->collapsible(),

                Infolists\Components\Section::make('تفاصيل الاسترجاع')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('refunds')
                            ->label('توزيع الاسترجاع')
                            ->schema([
                                Infolists\Components\TextEntry::make('method')
                                    ->label('طريقة الدفع')
                                    ->badge(),

                                Infolists\Components\TextEntry::make('amount')
                                    ->label('المبلغ')
                                    ->money('EGP'),
                            ])
                            ->columns(2),
                    ]),

                Infolists\Components\Section::make('إحصائيات')
                    ->schema([
                        Infolists\Components\TextEntry::make('items_count')
                            ->label('عدد الأصناف المرتجعة')
                            ->getStateUsing(fn($record) => $record->items->count()),

                        Infolists\Components\TextEntry::make('total_quantity')
                            ->label('إجمالي الكمية المرتجعة')
                            ->getStateUsing(fn($record) => $record->items->sum('quantity')),

                        Infolists\Components\TextEntry::make('refunds_count')
                            ->label('عدد طرق الاسترجاع')
                            ->getStateUsing(fn($record) => $record->refunds->count()),
                    ])
                    ->columns(3),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ReturnedItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrderReturns::route('/'),
            'view' => Pages\ViewOrderReturn::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['order.customer', 'user', 'shift', 'items', 'refunds']);
    }
}
