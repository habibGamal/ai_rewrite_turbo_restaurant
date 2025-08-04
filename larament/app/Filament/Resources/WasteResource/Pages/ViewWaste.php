<?php

namespace App\Filament\Resources\WasteResource\Pages;

use App\Filament\Actions\CloseWasteAction;
use App\Filament\Resources\WasteResource;
use App\Filament\Resources\WasteResource\RelationManagers\ItemsRelationManager;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewWaste extends ViewRecord
{
    protected static string $resource = WasteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CloseWasteAction::make(),
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
