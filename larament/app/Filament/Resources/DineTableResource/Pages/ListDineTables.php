<?php

namespace App\Filament\Resources\DineTableResource\Pages;

use App\Filament\Resources\DineTableResource;
use Filament\Resources\Pages\ListRecords;

class ListDineTables extends ListRecords
{
    protected static string $resource = DineTableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // No create action for read-only resource
        ];
    }
}
