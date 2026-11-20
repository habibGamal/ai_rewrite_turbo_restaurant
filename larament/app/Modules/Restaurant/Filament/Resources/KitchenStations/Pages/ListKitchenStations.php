<?php

namespace App\Modules\Restaurant\Filament\Resources\KitchenStations\Pages;

use App\Modules\Restaurant\Filament\Resources\KitchenStations\KitchenStationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListKitchenStations extends ListRecords
{
    protected static string $resource = KitchenStationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
