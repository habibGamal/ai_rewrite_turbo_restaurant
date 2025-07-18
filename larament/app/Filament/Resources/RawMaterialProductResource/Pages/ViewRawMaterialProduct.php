<?php

namespace App\Filament\Resources\RawMaterialProductResource\Pages;

use App\Filament\Resources\RawMaterialProductResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewRawMaterialProduct extends ViewRecord
{
    protected static string $resource = RawMaterialProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
