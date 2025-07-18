<?php

namespace App\Filament\Resources\DineTableResource\Pages;

use App\Filament\Resources\DineTableResource;
use Filament\Resources\Pages\ViewRecord;

class ViewDineTable extends ViewRecord
{
    protected static string $resource = DineTableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // No edit or delete actions for read-only resource
        ];
    }
}
