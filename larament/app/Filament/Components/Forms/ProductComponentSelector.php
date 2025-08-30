<?php

namespace App\Filament\Components\Forms;

use Filament\Forms\Components\Select;
use Filament\Forms\Get;
use Filament\Forms\Set;
use App\Models\Product;
use App\Models\Category;
use App\Enums\ProductType;
use Filament\Notifications\Notification;

class ProductComponentSelector extends Select
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->label('إضافة مكون')
            ->placeholder('اختر مكوناً لإضافته للوصفة...')
            ->searchable()
            ->allowHtml()
            ->options(function () {
                $products = Product::whereIn('type', [
                    ProductType::RawMaterial,
                    ProductType::Consumable,
                ])
                    ->with('category')
                    ->get();

                return $products->mapWithKeys(function ($product) {
                    $cost = $product->cost ?? 0;
                    $categoryName = $product->category ? $product->category->name : 'بدون فئة';
                    $typeLabel = match($product->type->value) {
                        'raw_material' => 'مادة خام',
                        'consumable' => 'استهلاكي',
                        default => $product->type->value
                    };

                    $label = $product->name . ' - ' . $cost . ' ج.م' . ' (' . $categoryName . ') - ' . $typeLabel;

                    return [$product->id => $label];
                });
            })
            ->live()
            ->afterStateUpdated(function ($state, Set $set, Get $get) {
                if (!$state) {
                    return;
                }

                $product = Product::find($state);
                if (!$product) {
                    return;
                }

                $currentComponents = $get('productComponents') ?? [];
                // Check if component already exists in the list
                $existingComponentIds = collect($currentComponents)->pluck('component_id')->filter()->toArray();

                if (in_array($product->id, $existingComponentIds)) {
                    Notification::make()
                        ->title('تحذير')
                        ->body('هذا المكون موجود بالفعل في الوصفة')
                        ->warning()
                        ->send();

                    // Reset the select
                    $set('component_selector', null);
                    return;
                }

                // Prepare new component data
                $cost = $product->cost ?? 0;
                $quantity = 1;
                $total = $quantity * $cost;

                $newComponent = [
                    'component_id' => $product->id,
                    'component_name' => $product->name,
                    'quantity' => $quantity,
                    'unit' => $product->unit,
                    'cost' => $cost,
                    'total' => $total,
                    'category_name' => $product->category->name ?? 'بدون فئة'
                ];

                // Add the new component
                $currentComponents[] = $newComponent;
                $set('productComponents', $currentComponents);

                // Recalculate total cost
                $totalCost = 0;
                foreach ($currentComponents as $component) {
                    $totalCost += $component['total'] ?? 0;
                }
                $set('cost', $totalCost);

                // Reset the select
                $set('component_selector', null);

                // Show success notification
                Notification::make()
                    ->title('تم إضافة المكون')
                    ->body("تم إضافة '{$product->name}' إلى الوصفة بنجاح")
                    ->success()
                    ->send();
            })
            ->dehydrated(false); // Don't save this field's value
    }

    public static function make(string $name = 'component_selector'): static
    {
        return parent::make($name);
    }
}
