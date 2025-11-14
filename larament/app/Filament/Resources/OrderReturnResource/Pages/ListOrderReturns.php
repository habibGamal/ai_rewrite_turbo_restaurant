<?php

namespace App\Filament\Resources\OrderReturnResource\Pages;

use App\Filament\Resources\OrderReturnResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListOrderReturns extends ListRecords
{
    protected static string $resource = OrderReturnResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // No create action - returns are created via OrderResource
        ];
    }
}
