<?php

namespace App\Modules\Delivery\Filament\Resources\Regions\Pages;

use App\Modules\Delivery\Filament\Resources\Regions\RegionResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewRegion extends ViewRecord
{
    protected static string $resource = RegionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
