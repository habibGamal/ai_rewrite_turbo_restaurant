<?php

namespace App\Filament\Resources\ManufacturedProductResource\Pages;

use App\Filament\Resources\ManufacturedProductResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewManufacturedProduct extends ViewRecord
{
    protected static string $resource = ManufacturedProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
