<?php

namespace App\Filament\Forms;

use App\Models\Order;
use App\Services\Orders\OrderReturnService;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * The fee and equipment lines of a return form (admin «إرجاع الطلب» action
 * and the OrderReturns create page). A fee is refunded by amount, in full or
 * in part; an equipment line by quantity, its refund defaulting to
 * qty × unit price and editable down. Shown only when the order has them.
 */
class ReturnChargeLines
{
    /**
     * @return array{return_fees: list<array<string, mixed>>, return_equipment: list<array<string, mixed>>}
     */
    public static function defaults(Order $order): array
    {
        $order->loadMissing(['fees', 'equipmentLines', 'returns.items']);
        $service = app(OrderReturnService::class);

        return [
            'return_fees' => $order->fees
                ->map(fn ($fee): array => [
                    'order_fee_id' => $fee->id,
                    'name' => $fee->name,
                    'amount' => (float) $fee->amount,
                    'refundable' => $service->getRefundableFeeAmount($order, $fee->id),
                    'refund_amount' => 0,
                ])
                ->values()
                ->all(),
            'return_equipment' => $order->equipmentLines
                ->map(fn ($line): array => [
                    'order_equipment_id' => $line->id,
                    'name' => $line->name,
                    'original_quantity' => (int) $line->quantity,
                    'available_quantity' => $service->getAvailableEquipmentQuantity($order, $line->id),
                    'unit_price' => (float) $line->unit_price,
                    'quantity' => 0,
                    'refund_amount' => 0,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  \Closure(): array<int, array<string, mixed>>|null  $default
     */
    public static function feesRepeater(?\Closure $default = null): Repeater
    {
        return Repeater::make('return_fees')
            ->label('الرسوم الإضافية')
            ->schema([
                Hidden::make('order_fee_id'),
                TextInput::make('name')->label('الرسوم')->disabled()->dehydrated(false)->columnSpan(2),
                TextInput::make('amount')->label('القيمة')->disabled()->dehydrated(false)->prefix('ج.م')->numeric(),
                TextInput::make('refundable')->label('المتاح للاسترجاع')->disabled()->dehydrated(false)->prefix('ج.م')->numeric(),
                TextInput::make('refund_amount')
                    ->label('مبلغ الاسترجاع')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(fn (Get $get): float => (float) $get('refundable'))
                    ->default(0)
                    ->prefix('ج.م')
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Get $get, Set $set, $state): void {
                        $max = (float) $get('refundable');
                        if ((float) $state > $max) {
                            $set('refund_amount', $max);
                        } elseif ((float) $state < 0) {
                            $set('refund_amount', 0);
                        }
                    }),
            ])
            ->default($default ?? [])
            ->visible(fn (Get $get): bool => filled($get('return_fees')))
            ->columns(5)
            ->addable(false)
            ->deletable(false)
            ->reorderable(false)
            ->columnSpanFull();
    }

    /**
     * @param  \Closure(): array<int, array<string, mixed>>|null  $default
     */
    public static function equipmentRepeater(?\Closure $default = null): Repeater
    {
        return Repeater::make('return_equipment')
            ->label('المعدات')
            ->schema([
                Hidden::make('order_equipment_id'),
                TextInput::make('name')->label('المعدة')->disabled()->dehydrated(false)->columnSpan(2),
                TextInput::make('original_quantity')->label('الكمية الأصلية')->disabled()->dehydrated(false)->numeric(),
                TextInput::make('available_quantity')->label('المتاح للإرجاع')->disabled()->dehydrated(false)->numeric(),
                TextInput::make('unit_price')->label('سعر الاستخدام')->disabled()->dehydrated(false)->prefix('ج.م')->numeric(),
                TextInput::make('quantity')
                    ->label('كمية الإرجاع')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->default(0)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Get $get, Set $set, $state): void {
                        $quantity = max(0, min((int) $state, (int) $get('available_quantity')));
                        $set('quantity', $quantity);
                        $set('refund_amount', round($quantity * (float) $get('unit_price'), 2));
                    }),
                TextInput::make('refund_amount')
                    ->label('مبلغ الاسترجاع')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(fn (Get $get): float => round((int) $get('quantity') * (float) $get('unit_price'), 2))
                    ->default(0)
                    ->prefix('ج.م')
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Get $get, Set $set, $state): void {
                        $max = round((int) $get('quantity') * (float) $get('unit_price'), 2);
                        if ((float) $state > $max) {
                            $set('refund_amount', $max);
                        } elseif ((float) $state < 0) {
                            $set('refund_amount', 0);
                        }
                    }),
            ])
            ->default($default ?? [])
            ->visible(fn (Get $get): bool => filled($get('return_equipment')))
            ->columns(8)
            ->addable(false)
            ->deletable(false)
            ->reorderable(false)
            ->columnSpanFull();
    }

    /**
     * Service lines from submitted form data, skipping untouched rows.
     *
     * @return list<array{order_fee_id?: int, order_equipment_id?: int, quantity?: int, refund_amount: float}>
     */
    public static function fromFormData(array $data): array
    {
        $lines = [];

        foreach ($data['return_fees'] ?? [] as $row) {
            $amount = round((float) ($row['refund_amount'] ?? 0), 2);
            if ($amount > 0 && ! empty($row['order_fee_id'])) {
                $lines[] = ['order_fee_id' => (int) $row['order_fee_id'], 'refund_amount' => $amount];
            }
        }

        foreach ($data['return_equipment'] ?? [] as $row) {
            $quantity = (int) ($row['quantity'] ?? 0);
            if ($quantity > 0 && ! empty($row['order_equipment_id'])) {
                $lines[] = [
                    'order_equipment_id' => (int) $row['order_equipment_id'],
                    'quantity' => $quantity,
                    'refund_amount' => round((float) ($row['refund_amount'] ?? 0), 2),
                ];
            }
        }

        return $lines;
    }
}
