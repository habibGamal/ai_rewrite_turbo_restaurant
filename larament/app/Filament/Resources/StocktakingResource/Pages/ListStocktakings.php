<?php

namespace App\Filament\Resources\StocktakingResource\Pages;

use App\Filament\Resources\StocktakingResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListStocktakings extends ListRecords
{
    protected static string $resource = StocktakingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
