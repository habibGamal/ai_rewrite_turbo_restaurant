<?php

namespace App\Modules\Manufacturing\Filament\Resources\ManufacturingRecipes\Pages;

use App\Modules\Manufacturing\Filament\Resources\ManufacturingRecipes\ManufacturingRecipeResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewManufacturingRecipe extends ViewRecord
{
    protected static string $resource = ManufacturingRecipeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
