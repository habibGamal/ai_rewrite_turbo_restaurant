<?php

namespace App\Filament\Actions;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Services\Orders\OrderReturnService;
use Exception;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class ReturnOrderAction
{
    public static function make(?string $name = null): Action
    {
        return Action::make($name ?? 'return')
            ->label('إرجاع الطلب')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->visible(fn (Order $record): bool => $record->status === OrderStatus::COMPLETED)
            ->schema(fn (Order $record) => self::getFormSchema($record))
            ->action(function (Order $record, array $data) {
                try {
                    $returnService = app(OrderReturnService::class);

                    // Filter out items with zero quantity
                    $returnItems = collect($data['return_items'])
                        ->filter(fn ($item) => ($item['quantity'] ?? 0) > 0)
                        ->map(fn ($item) => [
                            'order_item_id' => $item['order_item_id'],
                            'quantity' => $item['quantity'],
                            'refund_amount' => $item['refund_amount'],
                        ])
                        ->values()
                        ->toArray();

                    if (empty($returnItems)) {
                        throw new Exception('يجب تحديد صنف واحد على الأقل للإرجاع');
                    }

                    // Filter out refunds with zero amount
                    $refundDistribution = collect($data['refund_distribution'])
                        ->filter(fn ($refund) => ($refund['amount'] ?? 0) > 0)
                        ->map(fn ($refund) => [
                            'method' => $refund['method'],
                            'amount' => $refund['amount'],
                        ])
                        ->values()
                        ->toArray();

                    if (empty($refundDistribution)) {
                        throw new Exception('يجب تحديد طريقة استرجاع واحدة على الأقل');
                    }

                    $orderReturn = $returnService->processReturn(
                        order: $record,
                        returnItems: $returnItems,
                        reason: $data['reason'],
                        refundDistribution: $refundDistribution,
                        shiftId: session('current_shift_id') ?? $record->shift_id,
                        reverseStock: $data['reverse_stock'] ?? true
                    );

                    Notification::make()
                        ->title('تم إرجاع الطلب بنجاح')
                        ->body("تم إرجاع أصناف بقيمة {$orderReturn->total_refund} ج.م")
                        ->success()
                        ->send();

                } catch (Exception $e) {
                    Notification::make()
                        ->title('فشل في إرجاع الطلب')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    throw $e;
                }
            })
            ->modalWidth('7xl')
            ->modalSubmitActionLabel('تأكيد الإرجاع')
            ->modalCancelActionLabel('إلغاء');
    }

    private static function getFormSchema(Order $order): array
    {
        $order->load(['items.product', 'returns.items', 'payments']);
        $returnService = app(OrderReturnService::class);

        // Calculate payment method totals
        $paymentsByMethod = $order->payments->groupBy('method')->map(function ($payments) {
            return $payments->sum('amount');
        });

        return [
            Repeater::make('return_items')
                ->label('أصناف الإرجاع')
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
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Get $get, Set $set, $state) {
                            $availableQty = floatval($get('available_quantity'));
                            $unitPrice = floatval($get('unit_price'));

                            // Cap quantity to available
                            if ($state > $availableQty) {
                                $state = $availableQty;
                                $set('quantity', $state);
                            }

                            // Auto-calculate refund amount
                            $refundAmount = $state * $unitPrice;
                            $set('refund_amount', round($refundAmount, 2));
                        })
                        ->required(),

                    TextInput::make('refund_amount')
                        ->label('مبلغ الاسترجاع')
                        ->numeric()
                        ->disabled()
                        ->dehydrated()
                        ->prefix('ج.م')
                        ->default(0),
                ])
                ->default(function () use ($order, $returnService) {
                    return $order->items->map(function ($item) use ($returnService, $order) {
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
                })
                ->columns(7)
                ->addable(false)
                ->deletable(false)
                ->reorderable(false)
                ->columnSpanFull(),

            Textarea::make('reason')
                ->label('سبب الإرجاع')
                ->required()
                ->rows(3)
                ->columnSpanFull(),

            Section::make('معلومات الدفع الأصلية')
                ->description('المبالغ التي تم دفعها من قبل العميل')
                ->schema([
                    Placeholder::make('payment_info')
                        ->label('')
                        ->content(function () use ($paymentsByMethod, $order) {
                            $info = 'إجمالي الطلب: '.number_format((float) $order->total, 2)." ج.م\n\n";
                            $info .= "طرق الدفع المستخدمة:\n";

                            foreach ($paymentsByMethod as $method => $amount) {
                                $methodLabel = PaymentMethod::from($method)->getLabel();
                                $info .= "• {$methodLabel}: ".number_format((float) $amount, 2)." ج.م\n";
                            }

                            return $info;
                        })
                        ->columnSpanFull(),
                ])
                ->collapsible()
                ->collapsed(false),

            Repeater::make('refund_distribution')
                ->label('توزيع الاسترجاع')
                ->schema([
                    Select::make('method')
                        ->label('طريقة الاسترجاع')
                        ->options(PaymentMethod::class)
                        ->required()
                        ->columnSpan(1),

                    TextInput::make('amount')
                        ->label('المبلغ')
                        ->numeric()
                        ->minValue(0.01)
                        ->prefix('ج.م')
                        ->required()
                        ->columnSpan(1),
                ])
                ->default(function () use ($paymentsByMethod) {
                    // Suggest distribution based on original payment methods
                    return $paymentsByMethod->map(function ($amount, $method) {
                        return [
                            'method' => $method,
                            'amount' => 0, // Admin will fill this
                        ];
                    })->values()->toArray();
                })
                ->columns(2)
                ->addActionLabel('إضافة طريقة استرجاع')
                ->columnSpanFull()
                ->minItems(1),

            Placeholder::make('refund_hint')
                ->label('')
                ->content('💡 تلميح: يجب أن يساوي مجموع مبالغ الاسترجاع إجمالي مبالغ الأصناف المرتجعة')
                ->columnSpanFull(),

            Toggle::make('reverse_stock')
                ->label('إعادة الأصناف للمخزون')
                ->helperText('تفعيل هذا الخيار سيعيد إضافة الأصناف المرتجعة للمخزون')
                ->default(true)
                ->inline(false)
                ->columnSpanFull(),

            Placeholder::make('warning')
                ->label('')
                ->content('تحذير: عملية الإرجاع لا يمكن التراجع عنها. يرجى التأكد من البيانات قبل التأكيد.')
                ->columnSpanFull(),
        ];
    }
}
