<?php

namespace App\Modules\Manufacturing\Filament\Resources\RawMaterialProducts\Pages;

use App\Modules\Manufacturing\Filament\Resources\RawMaterialProducts\RawMaterialProductResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRawMaterialProducts extends ListRecords
{
    protected static string $resource = RawMaterialProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
