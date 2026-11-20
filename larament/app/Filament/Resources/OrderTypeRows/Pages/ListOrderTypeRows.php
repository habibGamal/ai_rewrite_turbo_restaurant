<?php

namespace App\Filament\Resources\OrderTypeRows\Pages;

use App\Filament\Resources\OrderTypeRows\OrderTypeRowResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOrderTypeRows extends ListRecords
{
    protected static string $resource = OrderTypeRowResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
