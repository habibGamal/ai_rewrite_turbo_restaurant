<?php

namespace App\Modules\Manufacturing\Filament\Resources\RawMaterialProducts\Pages;

use App\Modules\Manufacturing\Filament\Resources\RawMaterialProducts\RawMaterialProductResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewRawMaterialProduct extends ViewRecord
{
    protected static string $resource = RawMaterialProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
