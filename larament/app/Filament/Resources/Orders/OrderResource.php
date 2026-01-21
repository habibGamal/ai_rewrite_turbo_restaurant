<?php

namespace App\Filament\Resources\Orders;

use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Forms\Components\DatePicker;
use Filament\Actions\ViewAction;
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\IconEntry;
use App\Filament\Resources\Orders\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\Orders\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Orders\RelationManagers\OrderReturnsRelationManager;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\OrderResource\Pages;
use App\Filament\Resources\OrderResource\RelationManagers;
use App\Filament\Traits\AdminAccess;
use App\Models\Order;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\ReturnStatus;
use Filament\Forms;
use Filament\Infolists;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class OrderResource extends Resource
{
    use AdminAccess;

    protected static ?string $model = Order::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationLabel = 'الطلبات';

    protected static ?string $modelLabel = 'طلب';

    protected static ?string $pluralModelLabel = 'الطلبات';

    protected static string | \UnitEnum | null $navigationGroup = 'إدارة المطعم';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                // This is a view-only resource, no form fields needed
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('الرقم المرجعي')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('order_number')
                    ->label('رقم الطلب')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('customer.name')
                    ->label('العميل')
                    ->searchable()
                    ->placeholder('غير محدد'),

                TextColumn::make('customer.phone')
                    ->label('رقم الهاتف')
                    ->searchable()
                    ->placeholder('غير محدد'),

                TextColumn::make('type')
                    ->label('نوع الطلب')
                    ->badge()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('حالة الطلب')
                    ->badge()
                    ->sortable(),

                TextColumn::make('payment_status')
                    ->label('حالة الدفع')
                    ->badge()
                    ->sortable(),

                TextColumn::make('return_status')
                    ->label('حالة الإرجاع')
                    ->badge()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('total')
                    ->label('الإجمالي')
                    ->money('EGP')
                    ->sortable(),

                TextColumn::make('payments_count')
                    ->label('عدد المدفوعات')
                    ->counts('payments')
                    ->sortable()
                    ->badge()
                    ->color('info'),

                TextColumn::make('user.name')
                    ->label('الكاشير')
                    ->placeholder('غير محدد'),

                TextColumn::make('transaction_id')
                    ->label('رقم المعاملة')
                    ->state(fn ($record) => $record->web_preferences['transaction_id'] ?? 'غير محدد')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where('web_preferences->transaction_id', 'like', "%{$search}%");
                    })
                    ->copyable()
                    ->copyMessage('تم نسخ رقم المعاملة')
                    ->copyMessageDuration(1500)
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('نوع الطلب')
                    ->options(OrderType::class),

                SelectFilter::make('status')
                    ->label('حالة الطلب')
                    ->options(OrderStatus::class),

                SelectFilter::make('payment_status')
                    ->label('حالة الدفع')
                    ->options(PaymentStatus::class),

                SelectFilter::make('return_status')
                    ->label('حالة الإرجاع')
                    ->options(ReturnStatus::class),

                Filter::make('created_at')
                    ->label('تاريخ الإنشاء')
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
                Section::make('معلومات الطلب')
                    ->schema([
                        TextEntry::make('order_number')
                            ->label('رقم الطلب'),

                        TextEntry::make('type')
                            ->label('نوع الطلب')
                            ->badge(),

                        TextEntry::make('status')
                            ->label('حالة الطلب')
                            ->badge(),

                        TextEntry::make('payment_status')
                            ->label('حالة الدفع')
                            ->badge(),

                        TextEntry::make('return_status')
                            ->label('حالة الإرجاع')
                            ->badge(),

                        TextEntry::make('dine_table_number')
                            ->label('رقم الطاولة')
                            ->placeholder('غير محدد'),

                        TextEntry::make('created_at')
                            ->label('تاريخ الإنشاء')
                            ->dateTime('Y-m-d H:i:s'),
                    ])
                    ->columns(2),

                Section::make('معلومات العميل')
                    ->schema([
                        TextEntry::make('customer.name')
                            ->label('اسم العميل')
                            ->placeholder('غير محدد'),

                        TextEntry::make('customer.phone')
                            ->label('رقم الهاتف')
                            ->placeholder('غير محدد'),

                        TextEntry::make('customer.address')
                            ->label('العنوان')
                            ->placeholder('غير محدد'),

                        TextEntry::make('customer.region')
                            ->label('المنطقة')
                            ->placeholder('غير محدد'),

                        IconEntry::make('customer.has_whatsapp')
                            ->label('واتساب')
                            ->boolean()
                            ->placeholder('غير محدد'),

                        TextEntry::make('customer.delivery_cost')
                            ->label('تكلفة التوصيل')
                            ->money('EGP')
                            ->placeholder('غير محدد'),
                    ])
                    ->columns(2)
                    ->collapsed()
                    ->collapsible(),

                Section::make('التفاصيل المالية')
                    ->schema([
                        TextEntry::make('sub_total')
                            ->label('المجموع الفرعي')
                            ->money('EGP'),

                        TextEntry::make('tax')
                            ->label('الضرائب')
                            ->money('EGP'),

                        TextEntry::make('service')
                            ->label('رسوم الخدمة')
                            ->money('EGP'),

                        TextEntry::make('discount')
                            ->label('الخصم')
                            ->money('EGP'),

                        TextEntry::make('total')
                            ->label('الإجمالي')
                            ->money('EGP')
                            ->weight('bold'),

                        TextEntry::make('total_paid')
                            ->label('المبلغ المدفوع')
                            ->money('EGP')
                            ->color('success'),

                        TextEntry::make('remaining_amount')
                            ->label('المبلغ المتبقي')
                            ->money('EGP')
                            ->color(fn ($record) => $record->remaining_amount > 0 ? 'danger' : 'success'),

                        TextEntry::make('profit')
                            ->label('الربح')
                            ->money('EGP'),

                        TextEntry::make('total_refunded')
                            ->label('إجمالي المرتجع')
                            ->money('EGP')
                            ->color('danger')
                            ->visible(fn($record) => $record->total_refunded > 0),
                    ])
                    ->columns(3),

                Section::make('ملاحظات')
                    ->schema([
                        TextEntry::make('kitchen_notes')
                            ->label('ملاحظات المطبخ')
                            ->placeholder('لا توجد ملاحظات'),

                        TextEntry::make('order_notes')
                            ->label('ملاحظات الطلب')
                            ->placeholder('لا توجد ملاحظات'),
                    ])
                    ->columns(1)
                    ->collapsed()
                    ->collapsible(),

                Section::make('معلومات إضافية')
                    ->schema([
                        TextEntry::make('user.name')
                            ->label('الكاشير'),

                        TextEntry::make('driver.name')
                            ->label('السائق')
                            ->placeholder('غير محدد'),

                        TextEntry::make('shift.id')
                            ->label('رقم الوردية')
                            ->placeholder('غير محدد'),
                    ])
                    ->columns(3)
                    ->collapsed()
                    ->collapsible(),

                Section::make('معلومات الدفع عبر الويب')
                    ->schema([
                        TextEntry::make('web_payment_method')
                            ->label('طريقة الدفع')
                            ->state(function ($record) {
                                $method = $record->web_preferences['payment_method'] ?? null;
                                return match($method) {
                                    'cash' => 'كاش',
                                    'card' => 'فيزا',
                                    'talabat_card' => 'بطاقة طلبات',
                                    default => 'غير محدد'
                                };
                            })
                            ->badge()
                            ->color(fn ($state) => match($state) {
                                'كاش' => 'success',
                                'فيزا' => 'info',
                                'بطاقة طلبات' => 'warning',
                                default => 'gray'
                            }),

                        TextEntry::make('transaction_id')
                            ->label('رقم المعاملة')
                            ->state(fn ($record) => $record->web_preferences['transaction_id'] ?? 'غير محدد')
                            ->copyable()
                            ->copyMessage('تم نسخ رقم المعاملة')
                            ->copyMessageDuration(1500),
                    ])
                    ->columns(2)
                    ->collapsed()
                    ->collapsible()
                    ->visible(fn ($record) => !empty($record->web_preferences)),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
            PaymentsRelationManager::class,
            OrderReturnsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['customer', 'user', 'driver', 'shift', 'payments', 'items', 'returns']);
    }
}
