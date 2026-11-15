<?php

namespace App\Filament\Components\Forms;

use App\Enums\ProductType;
use App\Models\Product;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class ProductSelector extends Select
{
    protected $additionalPropsCallback;

    public function additionalProps(callable $additional): static
    {
        $this->additionalPropsCallback = $additional;

        return $this;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('إضافة منتج')
            ->placeholder('اختر منتج لإضافته...')
            ->searchable()
            ->allowHtml()
            // ->extraAttributes([
            //     'x-on:change' => <<<JS

            //         const items = [];
            //         items.push({
            //             product_id: \$event.target.value,
            //             product_name: \$event.target.selectedOptions[0].text,
            //             quantity: 1,
            //             price: \$event.target.dataset.price,
            //             total: \$event.target.dataset.price
            //         });
            //         // \$wire.data.items.set(items);
            //         \$wire.set('data.items', items,false);
            //         console.log(\$wire.data);
            //     JS
            // ])
            ->options(function () {
                $products = Product::whereIn('type', [
                    ProductType::RawMaterial,
                    ProductType::Consumable,
                ])
                    ->with('category')
                    ->get();

                return $products->mapWithKeys(function ($product) {
                    $price = $product->cost ?? $product->price;
                    $categoryName = $product->category ? $product->category->name : 'بدون فئة';
                    $unit = $product->unit ? ' ('.Product::formatUnit($product->unit).')' : '';

                    $label = $product->name.$unit.' - '.$price.' ج.م'.' ('.$categoryName.')';

                    return [$product->id => $label];
                });
            })
            ->live()
            ->afterStateUpdated(function ($state, Set $set, Get $get) {
                if (! $state) {
                    return;
                }

                $product = Product::find($state);
                if (! $product) {
                    return;
                }

                $currentItems = $get('items') ?? [];
                // Check if product already exists in the list
                $existingProductIds = collect($currentItems)->pluck('product_id')->filter()->toArray();

                if (in_array($product->id, $existingProductIds)) {
                    Notification::make()
                        ->title('تحذير')
                        ->body('هذا المنتج موجود بالفعل في القائمة')
                        ->warning()
                        ->send();

                    // Reset the select
                    $set('product_selector', null);

                    return;
                }

                // Prepare new item data
                $price = $product->cost ?? $product->price;
                $quantity = 1;
                $total = $quantity * $price;

                $newItem = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'unit' => Product::formatUnit($product->unit),
                    'quantity' => $quantity,
                    'price' => $price,
                    'total' => $total,
                ];

                // Add additional props if callback is provided
                if ($this->additionalPropsCallback) {
                    $additionalProps = ($this->additionalPropsCallback)($product);
                    $newItem = array_merge($newItem, $additionalProps);
                }

                // Add the new item
                $currentItems[] = $newItem;
                $set('items', $currentItems);

                // Recalculate total
                $invoiceTotal = 0;
                foreach ($currentItems as $item) {
                    $invoiceTotal += $item['total'] ?? 0;
                }
                $set('total', $invoiceTotal);

                // Reset the select
                $set('product_selector', null);

            })
            ->dehydrated(false); // Don't save this field's value
    }

    /**
     * Add a scanned product to the items list: a new row with the scanned quantity,
     * or more quantity on the row that already holds this product.
     */
    public static function addScannedProduct(Product $product, float $quantity, Set $set, Get $get, ?callable $additionalProps = null): void
    {
        $items = $get('items') ?? [];
        $found = false;

        foreach ($items as $key => $item) {
            if ((int) ($item['product_id'] ?? 0) === $product->id) {
                $newQuantity = (float) ($item['quantity'] ?? 0) + $quantity;
                $items[$key]['quantity'] = $newQuantity;
                $items[$key]['total'] = $newQuantity * (float) ($item['price'] ?? 0);
                $found = true;
                break;
            }
        }

        if (! $found) {
            $price = (float) ($product->cost ?? $product->price);
            $newItem = [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'unit' => Product::formatUnit($product->unit),
                'quantity' => $quantity,
                'price' => $price,
                'total' => $quantity * $price,
            ];
            if ($additionalProps) {
                $newItem = array_merge($newItem, $additionalProps($product));
            }
            $items[] = $newItem;
        }

        $set('items', $items);
        $set('total', collect($items)->sum(fn ($item) => (float) ($item['total'] ?? 0)));
    }

    public static function make(?string $name = 'product_selector'): static
    {
        return parent::make($name);
    }
}
