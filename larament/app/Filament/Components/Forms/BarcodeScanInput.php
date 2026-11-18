<?php

namespace App\Filament\Components\Forms;

use App\Enums\ProductType;
use App\Services\Products\ProductLookupResult;
use App\Services\Products\ProductLookupService;
use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * A text box a barcode scanner types into. Enter (sent by the scanner) resolves
 * the code through ProductLookupService and hands the result to onScanned(),
 * then clears the box and keeps focus for the next scan.
 */
class BarcodeScanInput extends TextInput
{
    protected ?Closure $onScannedCallback = null;

    /** @var list<ProductType> */
    protected array $productTypes = [ProductType::RawMaterial, ProductType::Consumable];

    /**
     * @param  Closure(ProductLookupResult, Set, Get): void  $callback
     */
    public function onScanned(Closure $callback): static
    {
        $this->onScannedCallback = $callback;

        return $this;
    }

    /**
     * @param  list<ProductType>  $types
     */
    public function productTypes(array $types): static
    {
        $this->productTypes = $types;

        return $this;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('مسح باركود')
            ->placeholder('امسح الباركود أو اكتبه ثم اضغط Enter')
            ->prefixIcon('heroicon-o-qr-code')
            ->autocomplete(false)
            ->maxLength(64)
            ->live(onBlur: true)
            ->extraInputAttributes([
                // Scanners end with Enter: resolve the code instead of submitting the form.
                'x-on:keydown.enter.prevent.stop' => '$el.blur(); $nextTick(() => $el.focus())',
            ])
            ->afterStateUpdated(function (?string $state, Set $set, Get $get, BarcodeScanInput $component) {
                if ($state === null || trim($state) === '') {
                    return;
                }

                $result = app(ProductLookupService::class)->lookup($state, $component->productTypes, includeInactive: true);
                $set($component->getName(), null);

                if ($result === null) {
                    Notification::make()
                        ->title('باركود غير معروف')
                        ->body('لا يوجد منتج بالباركود '.trim($state))
                        ->warning()
                        ->send();

                    return;
                }

                if ($component->onScannedCallback !== null) {
                    ($component->onScannedCallback)($result, $set, $get);
                }
            })
            ->dehydrated(false);
    }

    public static function make(?string $name = 'barcode_scan'): static
    {
        return parent::make($name);
    }
}
