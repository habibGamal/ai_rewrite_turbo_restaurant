<?php

namespace App\Filament\Resources\StocktakingResource\Pages;

use App\Filament\Actions\CloseStocktakingAction;
use App\Filament\Resources\StocktakingResource;
use App\Filament\Resources\StocktakingResource\RelationManagers\ItemsRelationManager;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewStocktaking extends ViewRecord
{
    protected static string $resource = StocktakingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CloseStocktakingAction::make(),
            Actions\EditAction::make(),
        ];
    }

    public function getRelationManagers(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }
}
