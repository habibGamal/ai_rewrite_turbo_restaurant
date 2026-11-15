<?php

namespace App\Filament\Components\Forms;

use App\Models\Product;
use App\Models\ProductBarcode;
use App\Services\Products\ProductBarcodeService;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Product form fields for barcodes: the primary barcode (products.barcode) and
 * any number of extra barcodes, each with a pack quantity.
 */
class ProductBarcodeFields
{
    public static function primary(): TextInput
    {
        return TextInput::make('barcode')
            ->label('الباركود')
            ->maxLength(64)
            ->placeholder('اختياري')
            ->rules([
                fn ($livewire): Closure => self::availableRule(self::productId($livewire)),
            ]);
    }

    public static function extra(): Repeater
    {
        return Repeater::make('extraBarcodes')
            ->label('باركودات إضافية')
            ->helperText('باركود آخر لنفس المنتج، مثل باركود الكرتونة. الكمية هي عدد الوحدات التي يضيفها مسح هذا الباركود.')
            ->relationship('extraBarcodes')
            ->schema([
                TextInput::make('barcode')
                    ->label('الباركود')
                    ->required()
                    ->maxLength(64)
                    ->distinct()
                    ->rules([
                        fn ($livewire, Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($livewire, $get) {
                            $value = ProductBarcode::normalize(is_string($value) ? $value : null);
                            if ($value !== null && $value === ProductBarcode::normalize($get('../../barcode'))) {
                                $fail('هذا هو الباركود الأساسي للمنتج.');

                                return;
                            }
                            self::availableRule(self::productId($livewire))($attribute, $value, $fail);
                        },
                    ]),
                TextInput::make('pack_quantity')
                    ->label('الكمية لكل مسح')
                    ->numeric()
                    ->required()
                    ->default(1)
                    ->minValue(0.001)
                    ->step(0.001),
            ])
            ->columns(2)
            ->defaultItems(0)
            ->addActionLabel('إضافة باركود')
            ->columnSpanFull();
    }

    private static function availableRule(?int $productId): Closure
    {
        return function (string $attribute, $value, Closure $fail) use ($productId) {
            $owner = app(ProductBarcodeService::class)->ownerOf(is_string($value) ? $value : null, $productId);
            if ($owner !== null) {
                $fail("الباركود مستخدم بالفعل للمنتج: {$owner->name}");
            }
        };
    }

    private static function productId(mixed $livewire): ?int
    {
        $record = is_object($livewire) && property_exists($livewire, 'record') ? $livewire->record : null;

        return $record instanceof Product ? $record->id : null;
    }
}
