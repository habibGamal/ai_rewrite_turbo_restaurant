<?php

namespace App\Modules\Delivery\Filament\Resources\Drivers\Pages;

use App\Modules\Delivery\Filament\Resources\Drivers\DriverResource;
use App\Modules\Delivery\Filament\Resources\Drivers\RelationManagers\OrdersRelationManager;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewDriver extends ViewRecord
{
    protected static string $resource = DriverResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }

    public function getRelationManagers(): array
    {
        return [
            OrdersRelationManager::class,
        ];
    }
}
