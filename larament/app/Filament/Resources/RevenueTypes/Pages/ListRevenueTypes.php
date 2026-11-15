<?php

namespace App\Filament\Resources\RevenueTypes\Pages;

use App\Filament\Resources\RevenueTypes\RevenueTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRevenueTypes extends ListRecords
{
    protected static string $resource = RevenueTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
