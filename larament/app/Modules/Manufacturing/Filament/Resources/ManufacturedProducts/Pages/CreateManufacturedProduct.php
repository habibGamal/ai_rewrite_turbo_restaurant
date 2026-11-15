<?php

namespace App\Modules\Manufacturing\Filament\Resources\ManufacturedProducts\Pages;

use App\Modules\Manufacturing\Filament\Resources\ManufacturedProducts\ManufacturedProductResource;
use App\Services\Products\RecipeCycleDetectionService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateManufacturedProduct extends CreateRecord
{
    protected static string $resource = ManufacturedProductResource::class;

    protected function beforeCreate(): void
    {
        $components = $this->data['productComponents'] ?? [];
        $componentIds = collect($components)
            ->pluck('component_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        $cycle = app(RecipeCycleDetectionService::class)->detectCycleForProduct(
            null,
            $componentIds
        );

        if ($cycle) {
            Notification::make()
                ->title('خطأ في مكونات المنتج')
                ->body('تم اكتشاف تبعية دائرية في المكونات: '.implode(' ← ', $cycle))
                ->danger()
                ->persistent()
                ->send();

            $this->halt();
        }
    }
}
