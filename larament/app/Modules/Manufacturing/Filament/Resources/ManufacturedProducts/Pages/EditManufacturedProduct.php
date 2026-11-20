<?php

namespace App\Modules\Manufacturing\Filament\Resources\ManufacturedProducts\Pages;

use App\Modules\Manufacturing\Filament\Resources\ManufacturedProducts\ManufacturedProductResource;
use App\Models\Product;
use App\Services\Products\RecipeCycleDetectionService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditManufacturedProduct extends EditRecord
{
    protected static string $resource = ManufacturedProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function beforeSave(): void
    {
        $components = $this->data['productComponents'] ?? [];
        $componentIds = collect($components)
            ->pluck('component_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        /** @var Product $record */
        $record = $this->getRecord();

        $cycle = app(RecipeCycleDetectionService::class)->detectCycleForProduct(
            $record->id,
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
