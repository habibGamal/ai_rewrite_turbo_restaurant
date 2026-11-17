<?php

namespace App\Filament\Components\Forms;

use App\Models\Product;
use App\Services\FeatureService;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Read-only «الباركود» of a document line's product (purchase invoices,
 * purchase returns, stock issues, stock transfers), shown while the vendor
 * has switched document barcodes on.
 *
 * Add it to a schema only when enabled() (spread it in, never ->visible()):
 * a table repeater still gives an invisible field a cell, shifting the
 * columns after it.
 */
final class DocumentBarcodeField
{
    public static function enabled(): bool
    {
        return app(FeatureService::class)->documentBarcodesEnabled();
    }

    public static function make(): TextInput
    {
        return TextInput::make('barcode')
            ->label('الباركود')
            ->formatStateUsing(fn ($record, $state) => $state ?? $record?->product?->barcode)
            ->placeholder('بدون باركود')
            ->extraInputAttributes(['dir' => 'ltr'])
            ->disabled()
            ->dehydrated(false);
    }

    /**
     * @return list<TextInput>
     */
    public static function whenEnabled(): array
    {
        return self::enabled() ? [self::make()] : [];
    }

    /** For a product select's afterStateUpdated. */
    public static function fill(Set $set, mixed $productId): void
    {
        if (self::enabled()) {
            $set('barcode', $productId ? Product::whereKey($productId)->value('barcode') : null);
        }
    }
}
