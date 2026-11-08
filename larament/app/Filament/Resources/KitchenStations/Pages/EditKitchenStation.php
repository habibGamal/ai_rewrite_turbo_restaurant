<?php

namespace App\Filament\Resources\KitchenStations\Pages;

use App\Filament\Resources\KitchenStations\KitchenStationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditKitchenStation extends EditRecord
{
    protected static string $resource = KitchenStationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
