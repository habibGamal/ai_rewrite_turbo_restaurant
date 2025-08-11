<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Filament\Resources\OrderResource\RelationManagers;
use App\Models\Order;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationLabel = 'الطلبات';

    protected static ?string $modelLabel = 'طلب';

    protected static ?string $pluralModelLabel = 'الطلبات';

    protected static ?string $navigationGroup = 'إدارة المطعم';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // This is a view-only resource, no form fields needed
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('الرقم المرجعي')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('order_number')
                    ->label('رقم الطلب')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('customer.name')
                    ->label('العميل')
                    ->searchable()
                    ->placeholder('غير محدد'),

                Tables\Columns\TextColumn::make('customer.phone')
                    ->label('رقم الهاتف')
                    ->searchable()
                    ->placeholder('غير محدد'),

                Tables\Columns\TextColumn::make('type')
                    ->label('نوع الطلب')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('حالة الطلب')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('payment_status')
                    ->label('حالة الدفع')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('total')
                    ->label('الإجمالي')
                    ->money('EGP')
                    ->sortable(),

                Tables\Columns\TextColumn::make('payments_count')
                    ->label('عدد المدفوعات')
                    ->counts('payments')
                    ->sortable()
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('الكاشير')
                    ->placeholder('غير محدد'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('نوع الطلب')
                    ->options(OrderType::class),

                Tables\Filters\SelectFilter::make('status')
                    ->label('حالة الطلب')
                    ->options(OrderStatus::class),

                Tables\Filters\SelectFilter::make('payment_status')
                    ->label('حالة الدفع')
                    ->options(PaymentStatus::class),

                Tables\Filters\Filter::make('created_at')
                    ->label('تاريخ الإنشاء')
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
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
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
                Infolists\Components\Section::make('معلومات الطلب')
                    ->schema([
                        Infolists\Components\TextEntry::make('order_number')
                            ->label('رقم الطلب'),

                        Infolists\Components\TextEntry::make('type')
                            ->label('نوع الطلب')
                            ->badge(),

                        Infolists\Components\TextEntry::make('status')
                            ->label('حالة الطلب')
                            ->badge(),

                        Infolists\Components\TextEntry::make('payment_status')
                            ->label('حالة الدفع')
                            ->badge(),

                        Infolists\Components\TextEntry::make('dine_table_number')
                            ->label('رقم الطاولة')
                            ->placeholder('غير محدد'),

                        Infolists\Components\TextEntry::make('created_at')
                            ->label('تاريخ الإنشاء')
                            ->dateTime('Y-m-d H:i:s'),
                    ])
                    ->columns(2),

                Infolists\Components\Section::make('معلومات العميل')
                    ->schema([
                        Infolists\Components\TextEntry::make('customer.name')
                            ->label('اسم العميل')
                            ->placeholder('غير محدد'),

                        Infolists\Components\TextEntry::make('customer.phone')
                            ->label('رقم الهاتف')
                            ->placeholder('غير محدد'),

                        Infolists\Components\TextEntry::make('customer.address')
                            ->label('العنوان')
                            ->placeholder('غير محدد'),

                        Infolists\Components\TextEntry::make('customer.region')
                            ->label('المنطقة')
                            ->placeholder('غير محدد'),

                        Infolists\Components\IconEntry::make('customer.has_whatsapp')
                            ->label('واتساب')
                            ->boolean()
                            ->placeholder('غير محدد'),

                        Infolists\Components\TextEntry::make('customer.delivery_cost')
                            ->label('تكلفة التوصيل')
                            ->money('EGP')
                            ->placeholder('غير محدد'),
                    ])
                    ->columns(2)
                    ->collapsed()
                    ->collapsible(),

                Infolists\Components\Section::make('التفاصيل المالية')
                    ->schema([
                        Infolists\Components\TextEntry::make('sub_total')
                            ->label('المجموع الفرعي')
                            ->money('EGP'),

                        Infolists\Components\TextEntry::make('tax')
                            ->label('الضرائب')
                            ->money('EGP'),

                        Infolists\Components\TextEntry::make('service')
                            ->label('رسوم الخدمة')
                            ->money('EGP'),

                        Infolists\Components\TextEntry::make('discount')
                            ->label('الخصم')
                            ->money('EGP'),

                        Infolists\Components\TextEntry::make('total')
                            ->label('الإجمالي')
                            ->money('EGP')
                            ->weight('bold'),

                        Infolists\Components\TextEntry::make('total_paid')
                            ->label('المبلغ المدفوع')
                            ->money('EGP')
                            ->color('success'),

                        Infolists\Components\TextEntry::make('remaining_amount')
                            ->label('المبلغ المتبقي')
                            ->money('EGP')
                            ->color(fn ($record) => $record->remaining_amount > 0 ? 'danger' : 'success'),

                        Infolists\Components\TextEntry::make('profit')
                            ->label('الربح')
                            ->money('EGP'),
                    ])
                    ->columns(3),

                Infolists\Components\Section::make('ملاحظات')
                    ->schema([
                        Infolists\Components\TextEntry::make('kitchen_notes')
                            ->label('ملاحظات المطبخ')
                            ->placeholder('لا توجد ملاحظات'),

                        Infolists\Components\TextEntry::make('order_notes')
                            ->label('ملاحظات الطلب')
                            ->placeholder('لا توجد ملاحظات'),
                    ])
                    ->columns(1)
                    ->collapsed()
                    ->collapsible(),

                Infolists\Components\Section::make('معلومات إضافية')
                    ->schema([
                        Infolists\Components\TextEntry::make('user.name')
                            ->label('الكاشير'),

                        Infolists\Components\TextEntry::make('driver.name')
                            ->label('السائق')
                            ->placeholder('غير محدد'),

                        Infolists\Components\TextEntry::make('shift.id')
                            ->label('رقم الوردية')
                            ->placeholder('غير محدد'),
                    ])
                    ->columns(3)
                    ->collapsed()
                    ->collapsible(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ItemsRelationManager::class,
            RelationManagers\PaymentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'view' => Pages\ViewOrder::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['customer', 'user', 'driver', 'shift', 'payments', 'items']);
    }
}
