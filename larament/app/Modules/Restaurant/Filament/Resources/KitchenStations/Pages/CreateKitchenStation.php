<?php

namespace App\Modules\Restaurant\Filament\Resources\KitchenStations\Pages;

use App\Modules\Restaurant\Filament\Resources\KitchenStations\KitchenStationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateKitchenStation extends CreateRecord
{
    protected static string $resource = KitchenStationResource::class;
}
