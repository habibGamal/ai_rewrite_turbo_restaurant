<?php

namespace App\Filament\Resources\StockTransfers\Pages;

use App\Filament\Actions\ConfirmStockTransferAction;
use App\Filament\Resources\StockTransfers\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewStockTransfer extends ViewRecord
{
    protected static string $resource = StockTransferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ConfirmStockTransferAction::make(),
            EditAction::make(),
        ];
    }

    public function getRelationManagers(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }
}
