<?php

namespace App\Modules\Delivery\Filament\Resources\Drivers\Pages;

use App\Modules\Delivery\Filament\Resources\Drivers\DriverResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDrivers extends ListRecords
{
    protected static string $resource = DriverResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
