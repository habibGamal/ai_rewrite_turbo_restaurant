<?php

namespace App\Modules\Manufacturing\Filament\Resources\ManufacturingRecipes\Pages;

use App\Modules\Manufacturing\Filament\Resources\ManufacturingRecipes\ManufacturingRecipeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListManufacturingRecipes extends ListRecords
{
    protected static string $resource = ManufacturingRecipeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
