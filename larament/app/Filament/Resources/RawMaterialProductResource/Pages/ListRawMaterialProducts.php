<?php

namespace App\Filament\Resources\RawMaterialProductResource\Pages;

use App\Filament\Resources\RawMaterialProductResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRawMaterialProducts extends ListRecords
{
    protected static string $resource = RawMaterialProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
