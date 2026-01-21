<?php

namespace App\Filament\Resources\OrderReturns;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\Filter;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use App\Filament\Resources\OrderReturns\RelationManagers\ReturnedItemsRelationManager;
use App\Filament\Resources\OrderReturns\Pages\ListOrderReturns;
use App\Filament\Resources\OrderReturns\Pages\CreateOrderReturn;
use App\Filament\Resources\OrderReturns\Pages\ViewOrderReturn;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Filament\Resources\OrderReturnResource\Pages;
use App\Filament\Resources\OrderReturnResource\RelationManagers;
use App\Filament\Traits\AdminAccess;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Services\Orders\OrderReturnService;
use App\Services\Resources\OrderReturnCalculatorService;
use Filament\Forms;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrderReturnResource extends Resource
{
    use AdminAccess;

    protected static ?string $model = OrderReturn::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static ?string $navigationLabel = 'مرتجعات الطلبات';

    protected static ?string $modelLabel = 'مرتجع طلب';

    protected static ?string $pluralModelLabel = 'مرتجعات الطلبات';

    protected static string | \UnitEnum | null $navigationGroup = 'إدارة المطعم';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('معلومات الطلب')
                    ->schema([
                        Select::make('order_id')
                            ->label('رقم الطلب')
                            ->options(function () {
                                return Order::where('status', OrderStatus::COMPLETED)
                                    ->with('customer')
                                    ->get()
                                    ->mapWithKeys(function ($order) {
                                        $label = "#{$order->order_number}";
                                        if ($order->customer) {
                                            $label .= " - {$order->customer->name}";
                                        }
                                        $label .= " ({$order->total} ج.م)";
                                        return [$order->id => $label];
                                    });
                            })
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, $state) {
                                if (!$state) {
                                    $set('return_items', []);
                                    $set('refund_distribution', []);
                                    return;
                                }

                                $order = Order::with(['items.product', 'returns.items', 'payments'])->find($state);
                                if (!$order) return;

                                $returnService = app(OrderReturnService::class);

                                // Set return items
                                $returnItems = $order->items->map(function ($item) use ($returnService, $order) {
                                    $availableQty = $returnService->getAvailableQuantityForReturn($order, $item->id);
                                    return [
                                        'order_item_id' => $item->id,
                                        'product_name' => $item->product->name,
                                        'original_quantity' => $item->quantity,
                                        'available_quantity' => $availableQty,
                                        'unit_price' => $item->price,
                                        'quantity' => 0,
                                        'refund_amount' => 0,
                                    ];
                                })->toArray();

                                $set('return_items', $returnItems);

                                // Set refund distribution based on payment methods
                                $paymentsByMethod = $order->payments->groupBy('method')->map(function ($payments) {
                                    return $payments->sum('amount');
                                });

                                $refundDistribution = $paymentsByMethod->map(function ($amount, $method) {
                                    return [
                                        'method' => $method,
                                        'amount' => 0,
                                    ];
                                })->values()->toArray();

                                $set('refund_distribution', $refundDistribution);
                            })
                            ->columnSpanFull(),

                        Placeholder::make('order_info')
                            ->label('معلومات الطلب')
                            ->content(function (Get $get) {
                                $orderId = $get('order_id');
                                if (!$orderId) {
                                    return 'الرجاء اختيار طلب';
                                }

                                $order = Order::with(['customer', 'payments'])->find($orderId);
                                if (!$order) return 'طلب غير موجود';

                                $info = "رقم الطلب: #{$order->order_number}\n";
                                if ($order->customer) {
                                    $info .= "العميل: {$order->customer->name}\n";
                                }
                                $info .= "إجمالي الطلب: " . number_format((float)$order->total, 2) . " ج.م\n\n";
                                $info .= "طرق الدفع المستخدمة:\n";

                                $paymentsByMethod = $order->payments->groupBy('method')->map(function ($payments) {
                                    return $payments->sum('amount');
                                });

                                foreach ($paymentsByMethod as $method => $amount) {
                                    $methodLabel = PaymentMethod::from($method)->getLabel();
                                    $info .= "• {$methodLabel}: " . number_format((float)$amount, 2) . " ج.م\n";
                                }

                                return $info;
                            })
                            ->columnSpanFull(),
                    ]),

                Section::make('أصناف الإرجاع')
                    ->extraAttributes([
                        'x-init' => OrderReturnCalculatorService::getJavaScriptCalculation(),
                    ])
                    ->schema([
                        Repeater::make('return_items')
                            ->label('')
                            ->schema([
                                Hidden::make('order_item_id'),

                                TextInput::make('product_name')
                                    ->label('المنتج')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->columnSpan(2),

                                TextInput::make('original_quantity')
                                    ->label('الكمية الأصلية')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->numeric(),

                                TextInput::make('available_quantity')
                                    ->label('المتاح للإرجاع')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->numeric(),

                                TextInput::make('unit_price')
                                    ->label('سعر الوحدة')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->prefix('ج.م')
                                    ->numeric(),

                                TextInput::make('quantity')
                                    ->label('كمية الإرجاع')
                                    ->numeric()
                                    ->minValue(0)
                                    ->default(0)
                                    ->required(),

                                TextInput::make('refund_amount')
                                    ->label('مبلغ الاسترجاع')
                                    ->numeric()
                                    ->disabled()
                                    ->dehydrated()
                                    ->prefix('ج.م')
                                    ->default(0),
                            ])
                            ->columns(7)
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->columnSpanFull()
                            ->default([]),

                        Placeholder::make('total_refund_info')
                            ->label('إجمالي الاسترجاع من الأصناف')
                            ->content(fn(Get $get) => number_format((float)($get('total_refund_display') ?? 0), 2) . ' ج.م')
                            ->columnSpanFull(),
                    ]),

                Textarea::make('reason')
                    ->label('سبب الإرجاع')
                    ->required()
                    ->rows(3)
                    ->columnSpanFull(),

                Section::make('توزيع الاسترجاع')
                    ->schema([
                        Repeater::make('refund_distribution')
                            ->label('')
                            ->schema([
                                Select::make('method')
                                    ->label('طريقة الاسترجاع')
                                    ->options(PaymentMethod::class)
                                    ->required()
                                    ->columnSpan(1),

                                TextInput::make('amount')
                                    ->label('المبلغ')
                                    ->numeric()
                                    ->minValue(0)
                                    ->prefix('ج.م')
                                    ->required()
                                    ->columnSpan(1),
                            ])
                            ->columns(2)
                            ->addActionLabel('إضافة طريقة استرجاع')
                            ->columnSpanFull()
                            ->minItems(1)
                            ->default([]),

                        Placeholder::make('distribution_total_info')
                            ->label('إجمالي توزيع الاسترجاع')
                            ->content(function (Get $get) {
                                $total = number_format((float)($get('distribution_total_display') ?? 0), 2);
                                $matches = $get('distribution_matches') ?? false;
                                $color = $matches ? 'text-green-600' : 'text-red-600';
                                $icon = $matches ? '✓' : '✗';
                                return "<span class='{$color} font-bold'>{$icon} {$total} ج.م</span>";
                            })
                            ->columnSpanFull(),

                        Placeholder::make('refund_hint')
                            ->label('')
                            ->content('💡 تلميح: يجب أن يساوي مجموع مبالغ الاسترجاع إجمالي مبالغ الأصناف المرتجعة')
                            ->columnSpanFull(),
                    ]),

                Toggle::make('reverse_stock')
                    ->label('إعادة الأصناف للمخزون')
                    ->helperText('تفعيل هذا الخيار سيعيد إضافة الأصناف المرتجعة للمخزون')
                    ->default(true)
                    ->inline(false)
                    ->columnSpanFull(),

                Hidden::make('total_refund_display'),
                Hidden::make('distribution_total_display'),
                Hidden::make('distribution_matches'),

                Placeholder::make('warning')
                    ->label('')
                    ->content('⚠️ تحذير: عملية الإرجاع لا يمكن التراجع عنها. يرجى التأكد من البيانات قبل الحفظ.')
                    ->columnSpanFull(),
            ])->columns(1);
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
                    ->url(fn($record) => route('filament.admin.resources.orders.view', ['record' => $record->order_id]))
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
                                fn(Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn(Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
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
                            ->url(fn($record) => route('filament.admin.resources.orders.view', ['record' => $record->order_id]))
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
                            ->getStateUsing(fn($record) => $record->items->count()),

                        TextEntry::make('total_quantity')
                            ->label('إجمالي الكمية المرتجعة')
                            ->getStateUsing(fn($record) => $record->items->sum('quantity')),

                        TextEntry::make('refunds_count')
                            ->label('عدد طرق الاسترجاع')
                            ->getStateUsing(fn($record) => $record->refunds->count()),
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
            'create' => CreateOrderReturn::route('/create'),
            'view' => ViewOrderReturn::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['order.customer', 'user', 'shift', 'items', 'refunds']);
    }
}
