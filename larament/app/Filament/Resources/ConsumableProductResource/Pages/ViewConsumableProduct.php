<?php

namespace App\Filament\Resources\ConsumableProductResource\Pages;

use App\Filament\Resources\ConsumableProductResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewConsumableProduct extends ViewRecord
{
    protected static string $resource = ConsumableProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
