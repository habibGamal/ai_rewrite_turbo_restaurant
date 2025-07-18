<?php

namespace App\Filament\Resources\ConsumableProductResource\Pages;

use App\Filament\Resources\ConsumableProductResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListConsumableProducts extends ListRecords
{
    protected static string $resource = ConsumableProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
